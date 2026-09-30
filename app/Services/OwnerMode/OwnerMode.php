<?php

namespace App\Services\OwnerMode;

use App\Models\User;
use App\Services\ClinicContext;
use App\Services\Security\SecurityAudit;
use App\UserType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Owner Mode: the clinic owner's temporary privileged workspace.
 *
 * Ownership comes from one place only, the clinic membership the session is
 * bound to (`ClinicContext`): its `is_primary_owner` flag, for this user, in
 * this clinic. A role name is never proof. An "Admin" employee is not the
 * owner, and neither is a master admin.
 *
 * The mode is session state, nothing else. It records who entered it, for
 * which clinic and membership, when, and when it was last used. It is valid
 * only while all of those still match the request and it has been used within
 * the idle timeout. Anything else ends it; an idle one is recorded as expired
 * and needs the step-up check again. Nothing about the user's roles or
 * membership changes.
 *
 * Owner-only code asks `authorized()` (or uses the `clinic_owner_mode` route
 * middleware); it never reads the session itself.
 */
class OwnerMode
{
    public const SESSION_KEY = 'clinic_owner_mode';

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
     * Enter Owner Mode, after the caller has run the step-up check.
     *
     * @throws AuthorizationException when the user is not this clinic's owner
     */
    public function activate(string $method): void
    {
        if (! $this->isOwner()) {
            throw new AuthorizationException('Only the clinic owner can enter Owner Mode.');
        }

        $now = CarbonImmutable::now()->getTimestamp();

        session([self::SESSION_KEY => [
            'user_id' => auth()->id(),
            'clinic_id' => $this->clinicContext->clinic()->id,
            'membership_id' => $this->clinicContext->membership()->id,
            'method' => $method,
            'activated_at' => $now,
            'last_seen_at' => $now,
        ]]);

        // A privilege change gets a fresh session id, as signing in does.
        session()->regenerate();

        $this->audit->record('owner_mode.activated', $this->clinicContext->membership(), [
            'method' => $method,
            'idle_timeout_minutes' => $this->idleTimeoutMinutes(),
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

        if ($this->isExpired($state)) {
            $this->end('owner_mode.expired', $state, 'idle_timeout');

            return false;
        }

        session([self::SESSION_KEY => ['last_seen_at' => CarbonImmutable::now()->getTimestamp()] + $state]);

        return true;
    }

    /**
     * The check for owner-only actions: the actual owner, with Owner Mode on.
     */
    public function authorized(): bool
    {
        // isActive() runs first so that a session now in another clinic, or no
        // longer the owner's, ends Owner Mode instead of merely skipping it.
        return $this->isActive() && $this->isOwner();
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

        return CarbonImmutable::createFromTimestamp((int) $state['last_seen_at'])->addMinutes($this->idleTimeoutMinutes());
    }

    /**
     * What the portal shows about Owner Mode: whether it is offered to this
     * user and whether it is on. Presentation only.
     *
     * @return array{available: bool, active: bool, expires_at: string|null, idle_timeout_minutes: int}
     */
    public function status(): array
    {
        $available = $this->isOwner();
        $active = $this->isActive() && $available;

        return [
            'available' => $available,
            'active' => $active,
            'expires_at' => $active ? $this->expiresAt()?->toIso8601String() : null,
            'idle_timeout_minutes' => $this->idleTimeoutMinutes(),
        ];
    }

    public function idleTimeoutMinutes(): int
    {
        return max(1, (int) config('security.owner_mode.idle_timeout_minutes', 15));
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
