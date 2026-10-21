<?php

namespace App\Services\Onboarding;

use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Mail\OwnerInvitationMail;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicOwnerInvitation;
use App\Models\ClinicProvisioningRun;
use App\Models\User;
use App\OwnerInvitationStatus;
use App\Services\Security\SecurityAudit;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The clinic owner's invitation, from creation to an active clinic.
 *
 * One invitation per clinic and email (a unique index), so a retried
 * provisioning run or a double click never creates a second. Sending
 * rotates the token: only the newest link works. Only the token's sha256 is
 * stored, and the link is also signed and time-limited.
 */
class OwnerInvitationService
{
    /** Status → the timestamp that records reaching it. */
    private const STAMPS = [
        'sent' => 'sent_at',
        'opened' => 'opened_at',
        'accepted' => 'accepted_at',
        'profile_completed' => 'profile_completed_at',
        'onboarding_started' => 'onboarding_started_at',
        'onboarding_completed' => 'onboarding_completed_at',
        'active' => 'activated_at',
    ];

    public function __construct(protected SecurityAudit $audit) {}

    /**
     * Create the owner's invitation, or return the existing one. A revoked or
     * expired invitation is reopened rather than duplicated.
     */
    public function issue(Clinic $clinic, User $owner, ?ClinicProvisioningRun $run = null, ?User $actor = null): ClinicOwnerInvitation
    {
        return DB::transaction(function () use ($clinic, $owner, $run, $actor) {
            $invitation = ClinicOwnerInvitation::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->where('email', $owner->email)
                ->lockForUpdate()
                ->first();

            if (! $invitation) {
                $invitation = ClinicOwnerInvitation::create([
                    'clinic_id' => $clinic->id,
                    'user_id' => $owner->id,
                    'provisioning_run_id' => $run?->id,
                    'email' => $owner->email,
                    'token_hash' => hash('sha256', Str::random(64)),
                    'status' => OwnerInvitationStatus::Created,
                    'expires_at' => now()->addDays($this->validDays()),
                    'created_by' => $actor?->id,
                ]);

                $this->audit->record('owner_invitation.created', $invitation, [], ['clinic_id' => $clinic->id, 'actor_user_id' => $actor?->id]);

                return $invitation;
            }

            if (in_array($invitation->status, [OwnerInvitationStatus::Revoked, OwnerInvitationStatus::Expired], true)) {
                $invitation->update([
                    'status' => OwnerInvitationStatus::Created,
                    'revoked_at' => null,
                    'expires_at' => now()->addDays($this->validDays()),
                ]);
            }

            return $invitation;
        });
    }

    /**
     * Send (or resend) the invitation with a fresh token and expiry.
     */
    public function send(ClinicOwnerInvitation $invitation, ?User $actor = null): string
    {
        $token = Str::random(64);

        DB::transaction(function () use ($invitation, $token) {
            $locked = ClinicOwnerInvitation::withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->id);
            $this->refreshExpiry($locked);

            if ($locked->status->isAccepted()) {
                throw new DomainException('The owner has already accepted this invitation.');
            }

            if ($locked->status === OwnerInvitationStatus::Revoked) {
                throw new DomainException('This invitation was revoked.');
            }

            $locked->update([
                'token_hash' => hash('sha256', $token),
                'status' => $locked->status->rank() < OwnerInvitationStatus::Sent->rank() || $locked->status === OwnerInvitationStatus::Expired
                    ? OwnerInvitationStatus::Sent
                    : $locked->status,
                'sent_at' => now(),
                'send_count' => $locked->send_count + 1,
                'expires_at' => now()->addDays($this->validDays()),
            ]);

            $invitation->setRawAttributes($locked->getAttributes(), true);
        });

        $clinic = Clinic::withoutGlobalScopes()->findOrFail($invitation->clinic_id);
        $owner = User::findOrFail($invitation->user_id);

        Mail::to($invitation->email)->send(new OwnerInvitationMail(
            ownerName: trim(explode(' ', (string) $owner->name)[0]) ?: 'there',
            clinicName: $clinic->display_name ?: $clinic->name,
            acceptUrl: $this->url($token),
            validDays: $this->validDays(),
        ));

        $this->audit->record($invitation->send_count > 1 ? 'owner_invitation.resent' : 'owner_invitation.sent', $invitation, ['send_count' => $invitation->send_count], ['clinic_id' => $invitation->clinic_id, 'actor_user_id' => $actor?->id]);

        return $token;
    }

    public function revoke(ClinicOwnerInvitation $invitation, ?User $actor = null): void
    {
        if ($invitation->status->isAccepted()) {
            throw new DomainException('An accepted invitation cannot be revoked.');
        }

        $invitation->update(['status' => OwnerInvitationStatus::Revoked, 'revoked_at' => now()]);
        $this->audit->record('owner_invitation.revoked', $invitation, [], ['clinic_id' => $invitation->clinic_id, 'actor_user_id' => $actor?->id]);
    }

    /**
     * The invitation a link points at, if it can still be used. Marks a
     * stale one expired on the way.
     */
    public function findUsable(string $plainToken): ?ClinicOwnerInvitation
    {
        $invitation = ClinicOwnerInvitation::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $invitation) {
            return null;
        }

        $this->refreshExpiry($invitation);

        $clinic = Clinic::withoutGlobalScopes()->find($invitation->clinic_id);
        if (! $clinic || $clinic->status !== ClinicStatus::Active) {
            return null;
        }

        return $invitation->status->isOpen() || $invitation->status->isAccepted() ? $invitation : null;
    }

    /**
     * A provisioning-created login has never been used: no verified email.
     * Anyone else already has a password of their own.
     */
    public function ownerHasOwnPassword(ClinicOwnerInvitation $invitation): bool
    {
        return User::whereKey($invitation->user_id)->whereNotNull('email_verified_at')->exists();
    }

    /**
     * Accept: set the password of a new login, or confirm an existing one.
     * The owner's membership was created by provisioning; acceptance only
     * checks it is still there and active for this clinic.
     */
    public function accept(ClinicOwnerInvitation $invitation, string $password): ClinicMembership
    {
        return DB::transaction(function () use ($invitation, $password) {
            $invitation = ClinicOwnerInvitation::withoutGlobalScopes()->lockForUpdate()->findOrFail($invitation->id);
            $this->refreshExpiry($invitation);

            if (! $invitation->status->isOpen()) {
                throw new DomainException('This invitation can no longer be used.');
            }

            $owner = User::lockForUpdate()->findOrFail($invitation->user_id);

            if ($owner->email_verified_at) {
                if (! Hash::check($password, $owner->password)) {
                    throw new DomainException('That password does not match this account.');
                }
            } else {
                $owner->forceFill([
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();
            }

            // Before sign-in there is no clinic context for the tenant scope.
            $membership = ClinicMembership::withoutGlobalScopes()
                ->where('clinic_id', $invitation->clinic_id)
                ->where('user_id', $owner->id)
                ->where('is_primary_owner', true)
                ->where('status', ClinicMembershipStatus::Active)
                ->first();

            if (! $membership) {
                throw new DomainException('This clinic is not set up for this account yet. Contact OralCares support.');
            }

            $this->advance($invitation, OwnerInvitationStatus::Accepted);
            $this->audit->record('owner_invitation.accepted', $invitation, [], ['clinic_id' => $invitation->clinic_id, 'actor_user_id' => $owner->id]);

            return $membership;
        });
    }

    /**
     * Move the lifecycle forward. Moving to a state already passed is a
     * no-op, so repeated events are harmless.
     */
    public function advance(ClinicOwnerInvitation $invitation, OwnerInvitationStatus $to): void
    {
        if ($to->rank() < 0 || $invitation->status->rank() < 0 || $invitation->status->rank() >= $to->rank()) {
            return;
        }

        $updates = ['status' => $to];
        foreach (self::STAMPS as $status => $column) {
            $rank = OwnerInvitationStatus::from($status)->rank();
            if ($rank > $invitation->status->rank() && $rank <= $to->rank() && ! $invitation->{$column}) {
                $updates[$column] = now();
            }
        }

        $invitation->update($updates);
    }

    /**
     * The owner invitation for a clinic and user, if any.
     */
    public function forOwner(int $clinicId, int $userId): ?ClinicOwnerInvitation
    {
        return ClinicOwnerInvitation::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Mark every unaccepted invitation past its expiry as expired, in one
     * indexed update rather than a scan per clinic.
     */
    public function expireStale(): int
    {
        return ClinicOwnerInvitation::withoutGlobalScopes()
            ->whereIn('status', [OwnerInvitationStatus::Created->value, OwnerInvitationStatus::Sent->value, OwnerInvitationStatus::Opened->value])
            ->where('expires_at', '<', now())
            ->update(['status' => OwnerInvitationStatus::Expired->value, 'updated_at' => now()]);
    }

    public function url(string $plainToken): string
    {
        return URL::temporarySignedRoute('clinic.owner-invitation.accept', now()->addDays($this->validDays()), ['token' => $plainToken]);
    }

    private function refreshExpiry(ClinicOwnerInvitation $invitation): void
    {
        if ($invitation->status->isOpen() && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => OwnerInvitationStatus::Expired]);
            $this->audit->record('owner_invitation.expired', $invitation, [], ['clinic_id' => $invitation->clinic_id, 'actor_user_id' => null]);
        }
    }

    private function validDays(): int
    {
        return (int) config('clinic_onboarding.owner_invitation_days', 7);
    }
}
