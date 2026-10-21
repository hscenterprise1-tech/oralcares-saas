<?php

namespace App\Listeners;

use App\Events\OnboardingSessionCompleted;
use App\Events\OnboardingSessionStarted;
use App\Models\Clinic;
use App\Models\ClinicOwnerInvitation;
use App\Models\OnboardingProgress;
use App\Models\OnboardingSession;
use App\OwnerInvitationStatus;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\Services\Onboarding\OwnerInvitationService;
use App\Services\Security\SecurityAudit;

/**
 * Moves the owner invitation through onboarding started → completed →
 * active, and marks Customer Success's onboarding record complete so the
 * company side sees the clinic as live.
 */
class TrackOwnerOnboarding
{
    public function __construct(protected OwnerInvitationService $invitations, protected SecurityAudit $audit) {}

    public function handleStarted(OnboardingSessionStarted $event): void
    {
        foreach ($this->ownerInvitations($event->session) as $invitation) {
            $this->invitations->advance($invitation, OwnerInvitationStatus::OnboardingStarted);
        }
    }

    public function handleCompleted(OnboardingSessionCompleted $event): void
    {
        $session = $event->session;

        if ($session->kind !== OnboardingSession::KIND_CLINIC) {
            return;
        }

        foreach ($this->ownerInvitations($session) as $invitation) {
            $this->invitations->advance($invitation, OwnerInvitationStatus::OnboardingCompleted);
            $this->invitations->advance($invitation, OwnerInvitationStatus::Active);
        }

        $clinic = Clinic::withoutGlobalScopes()->find($session->clinic_id);

        if ($clinic?->organization_id) {
            OnboardingProgress::query()
                ->where('organization_id', $clinic->organization_id)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed', 'current_step' => 'go_live']);

            if ($clinic->organization) {
                app(AccountSnapshotService::class)->refreshIfCustomer($clinic->organization);
            }
        }

        $this->audit->record('clinic.activated', $session, [], ['clinic_id' => $session->clinic_id, 'actor_user_id' => $event->actor?->id]);
    }

    /**
     * @return iterable<ClinicOwnerInvitation>
     */
    private function ownerInvitations(OnboardingSession $session): iterable
    {
        if ($session->kind !== OnboardingSession::KIND_CLINIC) {
            return [];
        }

        return ClinicOwnerInvitation::withoutGlobalScopes()
            ->where('clinic_id', $session->clinic_id)
            ->whereIn('status', [
                OwnerInvitationStatus::Accepted->value,
                OwnerInvitationStatus::ProfileCompleted->value,
                OwnerInvitationStatus::OnboardingStarted->value,
                OwnerInvitationStatus::OnboardingCompleted->value,
            ])
            ->get();
    }
}
