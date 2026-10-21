<?php

namespace App\Policies;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicalOrder;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

/**
 * Investigations: ordered by the consultation's treating doctor, fulfilled
 * and resulted by clinical staff of the branch, reviewed by a practitioner
 * who may write the patient's record.
 */
class ClinicalOrderPolicy
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicalEncounterPolicy $encounters,
        protected PatientPolicy $patients,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->user_type === UserType::ClinicUser && $user->hasClinicPermission(ClinicPermission::ClinicalView);
    }

    public function view(User $user, ClinicalOrder $order): bool
    {
        return $this->viewAny($user)
            && $this->clinicContext->hasClinic()
            && $order->clinic_id === $this->clinicContext->clinic()->id
            && $this->canOpenBranch($order->branch_id);
    }

    /** Order investigations from a consultation. */
    public function place(User $user, ClinicalEncounter $encounter): bool
    {
        return $this->encounters->update($user, $encounter);
    }

    /** Mark collected, enter a result, or cancel an item. */
    public function fulfil(User $user, ClinicalOrder $order): bool
    {
        return $this->view($user, $order)
            && $order->status === ClinicalOrder::ACTIVE
            && $user->hasClinicPermission(ClinicPermission::ClinicalEdit);
    }

    /** Read a result and make it final. */
    public function review(User $user, ClinicalOrder $order): bool
    {
        $patient = Patient::query()->withoutGlobalScopes()->find($order->patient_id);

        return $this->fulfil($user, $order)
            && $this->clinicContext->currentEmployee()?->practitionerProfile !== null
            && $patient !== null
            && $this->patients->writeClinicalData($user, $patient);
    }

    private function canOpenBranch(?int $branchId): bool
    {
        $membership = $this->clinicContext->membership();
        if (! $membership) {
            return false;
        }
        if ($branchId === null) {
            return true;
        }

        $branch = Branch::query()->withoutGlobalScopes()->find($branchId);

        return $branch !== null && $membership->hasAccessToBranch($branch);
    }
}
