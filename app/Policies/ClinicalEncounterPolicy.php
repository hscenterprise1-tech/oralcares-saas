<?php

namespace App\Policies;

use App\ClinicPermission;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\Models\User;
use App\PatientStatus;
use App\Services\ClinicContext;
use App\Services\Patient\CrossBranchClinicalAccess;
use App\Support\Specialty\SpecialtyRegistry;
use App\UserType;

/**
 * Who may open and write a consultation.
 *
 * - Reading needs `clinical.view`, the encounter in the active clinic, and
 *   access to the branch it happened in.
 * - Starting one needs a practitioner with the patient's clinical write gate
 *   (`PatientPolicy::writeClinicalData`).
 * - The draft is the treating doctor's alone: only the encounter's doctor
 *   edits and signs it. Oversight (owner/admin) reads; it does not write
 *   another doctor's notes.
 * - After signing, any clinician with the write gate may add an addendum;
 *   only the signer may amend (enforced in `EncounterWorkspace`).
 */
class ClinicalEncounterPolicy
{
    public function __construct(
        protected ClinicContext $clinicContext,
        protected PatientPolicy $patients,
        protected SpecialtyRegistry $specialties,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->isClinicUser($user) && $user->hasClinicPermission(ClinicPermission::ClinicalView);
    }

    public function view(User $user, ClinicalEncounter $encounter): bool
    {
        return $this->viewAny($user)
            && $this->inActiveClinic($encounter->clinic_id)
            // Another branch's notes: only while the patient attends here today, with the permission (audited where opened).
            && ($this->canOpenBranch($encounter->branch_id) || app(CrossBranchClinicalAccess::class)->mayView($user, $encounter));
    }

    public function start(User $user, Patient $patient): bool
    {
        return $this->inActiveClinic($patient->clinic_id)
            && $this->clinicContext->currentEmployee()?->practitionerProfile !== null
            // No new consultation for a deleted record (`PatientCareGuard`).
            && $patient->status !== PatientStatus::Deleted
            && $this->patients->writeClinicalData($user, $patient);
    }

    public function update(User $user, ClinicalEncounter $encounter): bool
    {
        return $this->view($user, $encounter)
            && $encounter->isEditable()
            && $this->isTreatingDoctor($encounter)
            && $this->patients->writeClinicalData($user, $encounter->patient()->withoutGlobalScopes()->firstOrFail());
    }

    public function finalize(User $user, ClinicalEncounter $encounter): bool
    {
        return $this->update($user, $encounter);
    }

    /**
     * Read a specialty extension's part of the record (e.g. the dental chart):
     * the consultation's specialty (or, without one, a specialty its branch
     * practises) must bring it.
     */
    public function viewExtension(User $user, ClinicalEncounter $encounter, string $extension): bool
    {
        return $this->view($user, $encounter) && $this->branchHasExtension($encounter, $extension);
    }

    /** Write a specialty extension's part of an open consultation. */
    public function writeExtension(User $user, ClinicalEncounter $encounter, string $extension): bool
    {
        return $this->update($user, $encounter) && $this->branchHasExtension($encounter, $extension);
    }

    public function addendum(User $user, ClinicalEncounter $encounter): bool
    {
        return $this->view($user, $encounter)
            && $encounter->isFinalized()
            && $this->clinicContext->currentEmployee() !== null
            && $this->patients->writeClinicalData($user, $encounter->patient()->withoutGlobalScopes()->firstOrFail());
    }

    private function isClinicUser(User $user): bool
    {
        return $user->user_type === UserType::ClinicUser;
    }

    private function inActiveClinic(?int $clinicId): bool
    {
        return $this->clinicContext->hasClinic() && $clinicId === $this->clinicContext->clinic()->id;
    }

    private function canOpenBranch(?int $branchId): bool
    {
        $membership = $this->clinicContext->membership();
        if (! $membership || $branchId === null) {
            return false;
        }

        $branch = Branch::query()->withoutGlobalScopes()->find($branchId);

        return $branch !== null && $membership->hasAccessToBranch($branch);
    }

    private function branchHasExtension(ClinicalEncounter $encounter, string $extension): bool
    {
        $branch = $encounter->branch_id ? Branch::query()->withoutGlobalScopes()->find($encounter->branch_id) : null;
        if (! $branch) {
            return false;
        }

        $practised = $this->specialties->forBranch($branch);
        // A consultation held in one specialty (a physiotherapy visit at a dental + physio branch)
        // brings that specialty's parts only; one without a specialty brings the branch's.
        $specialties = $encounter->specialty !== null && in_array($encounter->specialty, $practised, true) ? [$encounter->specialty] : $practised;

        return in_array($extension, $this->specialties->extensionsFor($specialties), true);
    }

    private function isTreatingDoctor(ClinicalEncounter $encounter): bool
    {
        $employee = $this->clinicContext->currentEmployee();

        return $employee !== null && $encounter->doctor_id === $employee->id;
    }
}
