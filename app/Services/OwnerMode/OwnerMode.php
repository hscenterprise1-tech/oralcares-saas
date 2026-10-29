<?php

namespace App\Services\OwnerMode;

use App\ClinicPermission;
use App\ClinicRole;
use App\Exceptions\OwnerModeRequired;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Security\SecurityAudit;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The clinic owner's privileges, and Owner Mode: the context elevation of an
 * owner who also works as a doctor.
 *
 * Ownership comes from one place only, the clinic membership the session is
 * bound to (`ClinicContext`): its `is_primary_owner` flag, for this user, in
 * this clinic. A role name is never proof. An "Admin" employee is not the
 * owner, a Doctor role is not the owner, and neither is a master admin.
 *
 * Two kinds of owner:
 *  - An owner who does not treat patients holds the owner's privileges from
 *    sign-in. There is no mode to enter and no password to give for ordinary
 *    work.
 *  - An owner who also works as a doctor (an active practitioner profile in
 *    this clinic) works in Doctor Mode by default, with a doctor's access
 *    (`doctorModePermissions()`): their own patients and their clinical work.
 *    For clinic-wide management, or other doctors' patients, they enter Owner
 *    Mode explicitly, and leaving it restores Doctor Mode.
 *
 * Owner Mode is session state, nothing else. It records who entered it, for
 * which clinic and membership, when, and when it was last used. It is valid
 * only while all of those still match the request and it has been used within
 * the idle timeout. Anything else ends it, and the doctor-owner is back in
 * Doctor Mode. Nothing about the user's roles or membership changes.
 *
 * Separately, a sensitive action may require the owner's password to have been
 * confirmed within the last few minutes (T2, `requireForSensitiveAction`). That
 * is a per-action policy, never a gate on navigation.
 *
 * Owner-only code asks `authorized()` (or uses the `clinic_owner_mode` route
 * middleware); it never reads the session itself.
 */
class OwnerMode
{
    public const SESSION_KEY = 'clinic_owner_mode';

    /** T1: Owner Mode on. */
    public const STANDARD = 'standard';

    /** T2: Owner Mode on and the owner's identity confirmed within the fresh-verification window. */
    public const FRESH = 'fresh';

    public const ACTION_REFUSAL = 'Enter Owner Mode from your account menu to do this.';

    public const FRESH_REFUSAL = 'Confirm your password again to do this.';

    public function __construct(
        protected ClinicContext $clinicContext,
        protected SecurityAudit $audit,
    ) {}

    /**
     * Whether the signed-in user is the owner of the clinic this session is
     * working in, and so may enter Owner Mode.
     */
    public function isOwner(): bool
    {
        $user = $this->clinicContext->user() ?? auth()->user();
        $membership = $this->clinicContext->membership();
        $clinic = $this->clinicContext->clinic();

        return $user instanceof User
            && $user->user_type === UserType::ClinicUser
            && $membership !== null
            && $clinic !== null
            && $membership->is_primary_owner
            && $membership->user_id === $user->id
            && $membership->clinic_id === $clinic->id;
    }

    /**
     * Whether this clinic's owner also works here as a doctor (an active
     * practitioner profile, or the Doctor role in this clinic), and so works
     * in Doctor Mode until they enter Owner Mode. Asked of the owner only: a
     * Doctor role never makes anyone an owner.
     */
    public function isDoctorOwner(): bool
    {
        if (! $this->isOwner()) {
            return false;
        }

        // Asked on every permission check: answered once per request and membership.
        $key = 'owner_mode.doctor_owner.'.$this->clinicContext->membership()->id;
        $memo = request()->attributes->get($key);
        if (is_bool($memo)) {
            return $memo;
        }

        $practitioner = $this->clinicContext->currentEmployee()?->practitionerProfile;
        $answer = $practitioner !== null && $practitioner->is_active;

        if (! $answer) {
            $user = $this->clinicContext->user() ?? auth()->user();
            $assignments = config('permission.table_names.model_has_roles');
            $roles = config('permission.table_names.roles');
            $answer = $user instanceof User && DB::table($assignments)
                ->join($roles, "{$roles}.id", '=', "{$assignments}.role_id")
                ->where("{$assignments}.model_type", User::class)->where("{$assignments}.model_id", $user->id)
                ->where("{$roles}.name", ClinicRole::Doctor->value)
                ->whereIn("{$assignments}.branch_id", DB::table('branches')->where('clinic_id', $this->clinicContext->clinic()->id)->select('id'))
                ->exists();
        }

        request()->attributes->set($key, $answer);

        return $answer;
    }

    /**
     * What an owner holds in Doctor Mode: a doctor's permissions, the same
     * template a Doctor role is given, and nothing of the clinic's management.
     *
     * @return list<string>
     */
    public static function doctorModePermissions(): array
    {
        return ClinicPermission::defaultRolePermissions()['Practitioner'];
    }

    /**
     * Enter Owner Mode (or, for an owner who does not treat patients, confirm
     * their password for a T2 action), after the caller has run the step-up check.
     *
     * @throws AuthorizationException when the user is not this clinic's owner
     */
    public function activate(string $method): void
    {
        if (! $this->isOwner()) {
            throw new AuthorizationException('Only the clinic owner can enter Owner Mode.');
        }

        $now = CarbonImmutable::now()->getTimestamp();

        // Confirming again while Owner Mode is on refreshes the verification
        // only. The mode keeps the time it began, so its absolute lifetime is
        // never pushed back.
        if ($this->isActive()) {
            session([self::SESSION_KEY => ['verified_at' => $now, 'last_seen_at' => $now, 'method' => $method] + session(self::SESSION_KEY)]);
            session()->regenerate();

            $this->audit->record('owner_mode.reverified', $this->clinicContext->membership(), ['method' => $method]);

            return;
        }

        session([self::SESSION_KEY => [
            'user_id' => auth()->id(),
            'clinic_id' => $this->clinicContext->clinic()->id,
            'membership_id' => $this->clinicContext->membership()->id,
            'method' => $method,
            'activated_at' => $now,
            'verified_at' => $now,
            'last_seen_at' => $now,
        ]]);

        // A privilege change gets a fresh session id, as signing in does.
        session()->regenerate();

        $this->audit->record('owner_mode.activated', $this->clinicContext->membership(), [
            'method' => $method,
            'idle_timeout_minutes' => $this->idleTimeoutMinutes(),
            'lifetime_minutes' => $this->lifetimeMinutes(),
        ]);
    }

    /**
     * Whether Owner Mode is on for this user, in this clinic, right now. A
     * valid check counts as activity and pushes the idle expiry back.
     */
    public function isActive(): bool
    {
        $state = session(self::SESSION_KEY);

        if (! is_array($state)) {
            return false;
        }

        // Outside a clinic context (a sign-in page, a background call with no
        // clinic) there is nothing to check against; the state is left alone.
        if (! $this->clinicContext->hasClinic()) {
            return false;
        }

        if (! $this->matchesContext($state)) {
            $this->end('owner_mode.exited', $state, 'context_changed');

            return false;
        }

        if ($this->isPastLifetime($state)) {
            $this->end('owner_mode.expired', $state, 'lifetime');

            return false;
        }

        if ($this->isExpired($state)) {
            $this->end('owner_mode.expired', $state, 'idle_timeout');

            return false;
        }

        session([self::SESSION_KEY => ['last_seen_at' => CarbonImmutable::now()->getTimestamp()] + $state]);

        return true;
    }

    /**
     * The check for owner-only work: the actual owner, with the owner's
     * privileges in effect. Always, for an owner who does not treat patients;
     * for a doctor-owner, only in Owner Mode.
     */
    public function authorized(): bool
    {
        // isActive() runs first so that a session now in another clinic, or no
        // longer the owner's, ends Owner Mode instead of merely skipping it.
        $active = $this->isActive();

        if (! $this->isOwner()) {
            return false;
        }

        return $active || ! $this->isDoctorOwner();
    }

    /**
     * Whether a doctor-owner has entered Owner Mode: the elevation itself, as
     * the account menu shows it. Always false for anyone else.
     */
    public function elevated(): bool
    {
        return $this->isActive() && $this->isDoctorOwner();
    }

    /**
     * The owner's privileges in effect, and the owner's password confirmed
     * within the last few minutes: the check for the most sensitive (T2)
     * actions.
     */
    public function freshlyVerified(): bool
    {
        if (! $this->authorized() || ! $this->isActive()) {
            return false;
        }

        $verifiedAt = (int) (session(self::SESSION_KEY)['verified_at'] ?? 0);

        return $verifiedAt > 0 && CarbonImmutable::now()->getTimestamp() - $verifiedAt <= $this->freshVerificationMinutes() * 60;
    }

    /**
     * The step-up rule for a sensitive action, checked where the action is
     * carried out. The clinic owner must hold the owner's privileges here: a
     * doctor-owner in Doctor Mode is asked to enter Owner Mode, while an owner
     * who does not treat patients already holds them. For a T2 action, the
     * owner must also have confirmed their password within the
     * fresh-verification window. Staff act on the permissions they hold at the
     * record's branch, which their own checks enforce.
     *
     * Work with no signed-in user (console commands, queued jobs, fixtures)
     * has no interactive session to step up and is not checked here. In a
     * request, the actor must be the signed-in user, and a clinic user with no
     * clinic context is refused.
     *
     * @param  array<string, mixed>  $subject  ids naming the record, for the audit trail
     *
     * @throws AuthorizationException
     */
    public function requireForSensitiveAction(User $actor, string $action, string $tier = self::STANDARD, array $subject = []): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        // `$stepUp`: the owner may confirm and send again (`OwnerModeRequired`); otherwise a plain refusal.
        $refuse = function (string $reason, string $message, ?string $stepUp = null) use ($action, $tier, $subject): never {
            $this->audit->record('owner_mode.action_refused', $this->clinicContext->membership(), ['action' => $action, 'tier' => $tier, 'reason' => $reason, ...$subject]);

            throw $stepUp !== null ? new OwnerModeRequired($message, $stepUp) : new AuthorizationException($message);
        };

        if ($user->id !== $actor->id) {
            $refuse('actor_mismatch', 'This action can only be taken by the signed-in user.');
        }

        if ($user->user_type === UserType::ClinicUser && ! $this->clinicContext->hasClinic()) {
            $refuse('no_clinic_context', 'No clinic is selected.');
        }

        if (! $this->isOwner()) {
            return;
        }

        if (! $this->authorized()) {
            $refuse('owner_mode_inactive', self::ACTION_REFUSAL, OwnerModeRequired::INACTIVE);
        }

        if ($tier === self::FRESH && ! $this->freshlyVerified()) {
            $refuse('verification_stale', self::FRESH_REFUSAL, OwnerModeRequired::STALE);
        }
    }

    /**
     * Leave Owner Mode on the owner's own request.
     */
    public function exit(): void
    {
        $state = session(self::SESSION_KEY);

        if (is_array($state)) {
            $this->end('owner_mode.exited', $state, 'exited');
        }
    }

    /**
     * When Owner Mode will lapse if nothing else happens, or null when it is off.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        $state = session(self::SESSION_KEY);

        if (! is_array($state) || ! isset($state['last_seen_at'])) {
            return null;
        }

        $idle = CarbonImmutable::createFromTimestamp((int) $state['last_seen_at'])->addMinutes($this->idleTimeoutMinutes());
        $lifetime = CarbonImmutable::createFromTimestamp((int) ($state['activated_at'] ?? 0))->addMinutes($this->lifetimeMinutes());

        return $idle->min($lifetime);
    }

    /**
     * What the portal shows about Owner Mode: whether it is offered to this
     * user and whether it is on. Presentation only.
     *
     * @return array{available: bool, active: bool, expires_at: string|null, idle_timeout_minutes: int}
     */
    public function status(): array
    {
        // Owner Mode is something to enter only for an owner who also works as a doctor;
        // any other owner already holds the owner's privileges.
        $available = $this->isDoctorOwner();
        $active = $available && $this->isActive();

        return [
            'available' => $available,
            'active' => $active,
            'owner' => $this->isOwner(),
            'expires_at' => $active ? $this->expiresAt()?->toIso8601String() : null,
            'idle_timeout_minutes' => $this->idleTimeoutMinutes(),
        ];
    }

    public function idleTimeoutMinutes(): int
    {
        return max(1, (int) config('security.owner_mode.idle_timeout_minutes', 15));
    }

    /** How long Owner Mode lasts from the moment it was entered, however busy the session. */
    public function lifetimeMinutes(): int
    {
        return max(1, (int) config('security.owner_mode.lifetime_minutes', 60));
    }

    /** How recent the owner's confirmation must be for a T2 action. */
    public function freshVerificationMinutes(): int
    {
        return max(1, (int) config('security.owner_mode.fresh_verification_minutes', 5));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function matchesContext(array $state): bool
    {
        return ($state['user_id'] ?? null) === auth()->id()
            && ($state['clinic_id'] ?? null) === $this->clinicContext->clinic()?->id
            && ($state['membership_id'] ?? null) === $this->clinicContext->membership()?->id
            && $this->isOwner();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function isExpired(array $state): bool
    {
        $lastSeen = (int) ($state['last_seen_at'] ?? 0);

        return CarbonImmutable::now()->getTimestamp() - $lastSeen > $this->idleTimeoutMinutes() * 60;
    }

    /**
     * Past the absolute lifetime. A state with no start time (from before the
     * lifetime existed) counts as past it: fail closed.
     *
     * @param  array<string, mixed>  $state
     */
    private function isPastLifetime(array $state): bool
    {
        $activatedAt = (int) ($state['activated_at'] ?? 0);

        return $activatedAt <= 0 || CarbonImmutable::now()->getTimestamp() - $activatedAt > $this->lifetimeMinutes() * 60;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function end(string $event, array $state, string $reason): void
    {
        session()->forget(self::SESSION_KEY);

        $this->audit->record($event, null, [
            'reason' => $reason,
            'owner_mode_clinic_id' => $state['clinic_id'] ?? null,
            'active_seconds' => CarbonImmutable::now()->getTimestamp() - (int) ($state['activated_at'] ?? 0),
        ]);
    }
}
