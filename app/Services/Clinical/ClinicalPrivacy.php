<?php

namespace App\Services\Clinical;

use App\ClinicPermission;
use App\Models\User;

/**
 * Who may see and change a patient's clinical information. The server reads
 * this before building any payload, so a role that may not see something is
 * never sent it.
 *
 * - Anyone who can open the patient sees the safety flags: allergies and
 *   high/critical conditions and alerts (`PatientClinicalEntries::safetyFlags`).
 * - Recording conditions and allergies while registering a new patient goes
 *   with the right to register (`patient.create`).
 * - The full clinical history needs `clinical.view`; changing anything
 *   already recorded needs `clinical.edit`.
 */
class ClinicalPrivacy
{
    public function canViewDetail(?User $user): bool
    {
        return (bool) $user?->hasClinicPermission(ClinicPermission::ClinicalView);
    }

    public function canEdit(?User $user): bool
    {
        return (bool) $user?->hasClinicPermission(ClinicPermission::ClinicalEdit);
    }

    public function canRecordAtRegistration(?User $user): bool
    {
        return (bool) $user?->hasClinicPermission(ClinicPermission::PatientCreate);
    }
}
