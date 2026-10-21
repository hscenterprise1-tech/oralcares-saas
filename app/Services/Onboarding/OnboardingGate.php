<?php

namespace App\Services\Onboarding;

use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\OnboardingSession;
use App\Models\User;
use App\OwnerInvitationStatus;

/**
 * Which onboarding, if any, stands between a signed-in person and the
 * clinic portal, and whether they may change it.
 *
 * The clinic's own setup comes first: until it is complete, nobody uses the
 * portal (people without settings access see a waiting screen). After that
 * an invited staff member finishes their own profile setup.
 */
class OnboardingGate
{
    public function __construct(protected OnboardingEngine $engine, protected OwnerInvitationService $invitations) {}

    public function pendingFor(User $user, Clinic $clinic, ?ClinicEmployee $employee): ?OnboardingSession
    {
        $clinicSession = $this->engine->clinicSession($clinic);

        if ($clinicSession && ! $clinicSession->isCompleted()) {
            return $clinicSession;
        }

        $staffSession = $employee ? $this->engine->staffSession($employee) : null;

        return $staffSession && ! $staffSession->isCompleted() ? $staffSession : null;
    }

    /**
     * Whether this clinic is governed by the onboarding engine at all
     * (clinics provisioned before it use the legacy checklist).
     */
    public function governs(Clinic $clinic): bool
    {
        return $this->engine->clinicSession($clinic) !== null;
    }

    public function canEdit(User $user, OnboardingSession $session, ?ClinicEmployee $employee): bool
    {
        if ($session->kind === OnboardingSession::KIND_STAFF) {
            return $employee !== null && (int) $employee->id === (int) $session->clinic_employee_id;
        }

        return $user->hasClinicPermission(ClinicPermission::SettingsManage);
    }

    /**
     * The owner accepted but has not confirmed their own details yet.
     */
    public function needsAccountSetup(User $user, Clinic $clinic): bool
    {
        return $this->invitations->forOwner($clinic->id, $user->id)?->status === OwnerInvitationStatus::Accepted;
    }
}
