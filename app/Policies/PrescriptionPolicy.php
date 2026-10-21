<?php

namespace App\Policies;

use App\ClinicPermission;
use App\Models\ClinicalEncounter;
use App\Models\Prescription;
use App\Models\User;
use App\Services\ClinicContext;

/**
 * Prescriptions follow their consultation: whoever may read the consultation
 * may read and print its prescriptions; only its treating doctor, holding
 * `prescription.manage`, writes them while it is open; only the prescriber
 * cancels a finalised one.
 */
class PrescriptionPolicy
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected ClinicalEncounterPolicy $encounters,
    ) {}

    public function view(User $user, Prescription $prescription): bool
    {
        $encounter = $this->encounterOf($prescription);

        return $encounter !== null && $this->encounters->view($user, $encounter);
    }

    /** Start (or reopen) the draft prescription of a consultation. */
    public function create(User $user, ClinicalEncounter $encounter): bool
    {
        return $user->hasClinicPermission(ClinicPermission::PrescriptionManage)
            && $this->encounters->update($user, $encounter);
    }

    public function update(User $user, Prescription $prescription): bool
    {
        $encounter = $this->encounterOf($prescription);

        return $prescription->isDraft()
            && $encounter !== null
            && $this->create($user, $encounter);
    }

    public function cancel(User $user, Prescription $prescription): bool
    {
        $me = $this->clinicContext->currentEmployee();

        return $prescription->isFinalized()
            && $user->hasClinicPermission(ClinicPermission::PrescriptionManage)
            && $this->view($user, $prescription)
            && $me !== null
            && $me->id === ($prescription->prescriber_id ?? $prescription->finalized_by);
    }

    public function print(User $user, Prescription $prescription): bool
    {
        return ! $prescription->isDraft() && $this->view($user, $prescription);
    }

    private function encounterOf(Prescription $prescription): ?ClinicalEncounter
    {
        return $prescription->encounter_id
            ? ClinicalEncounter::query()->withoutGlobalScopes()->find($prescription->encounter_id)
            : null;
    }
}
