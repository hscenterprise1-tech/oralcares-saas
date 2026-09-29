<?php

namespace App\Policies;

use App\ClinicPermission;
use App\Models\Patient;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

class PatientPolicy
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Only ClinicUsers (reception, doctor, billing, etc.) and MasterAdmin can access patients.
     * CompanyUsers are excluded from clinic patient data.
     */
    private function isClinicUser(User $user): bool
    {
        return $user->isMasterAdmin() || $user->user_type === UserType::ClinicUser;
    }

    /**
     * Determine whether the user can view any patients.
     */
    public function viewAny(User $user): bool
    {
        if (! $this->isClinicUser($user)) {
            return false;
        }

        return $user->hasClinicPermission(ClinicPermission::PatientView);
    }

    /**
     * Determine whether the user can view a specific patient.
     * Patient must belong to the same clinic as the active user.
     */
    public function view(User $user, Patient $patient): bool
    {
        if (! $this->isClinicUser($user)) {
            return false;
        }

        if ($this->clinicContext->hasClinic()) {
            if ($patient->clinic_id !== $this->clinicContext->clinic()->id) {
                return false;
            }
        }

        return $user->hasClinicPermission(ClinicPermission::PatientView);
    }

    /**
     * Determine whether the user can register new patients.
     */
    public function create(User $user): bool
    {
        if (! $this->isClinicUser($user)) {
            return false;
        }

        return $user->hasClinicPermission(ClinicPermission::PatientCreate);
    }

    /**
     * Determine whether the user can update patient demographic data.
     */
    public function update(User $user, Patient $patient): bool
    {
        if (! $this->isClinicUser($user)) {
            return false;
        }

        if ($this->clinicContext->hasClinic()) {
            if ($patient->clinic_id !== $this->clinicContext->clinic()->id) {
                return false;
            }
        }

        return $user->hasClinicPermission(ClinicPermission::PatientEdit);
    }

    /**
     * Determine whether the user can write clinical data (encounters, notes,
     * prescriptions) for this patient — the first ownership gate on the
     * `App\Http\Controllers\Clinical\*` module, which previously only
     * checked same-organization membership.
     *
     * `ClinicalEdit` alone (Practitioner and above) is the base gate; once a
     * patient has an assigned doctor (`current_practitioner_id`), only that
     * specific practitioner may write for them — except Owner/Admin, who
     * are detected by holding `PatientEdit` *and* `ClinicalEdit` together,
     * a combination `ClinicPermission::defaultRolePermissions()` never
     * grants to Reception (patient-only) or Practitioner (clinical-only),
     * so it doubles as this policy's admin-oversight bypass without a
     * dedicated permission of its own. An unassigned patient has no
     * exclusive owner yet, so anyone with `ClinicalEdit` may write for them.
     */
    public function writeClinicalData(User $user, Patient $patient): bool
    {
        if (! $this->isClinicUser($user)) {
            return false;
        }

        if ($this->clinicContext->hasClinic() && $patient->clinic_id !== $this->clinicContext->clinic()->id) {
            return false;
        }

        if (! $user->hasClinicPermission(ClinicPermission::ClinicalEdit)) {
            return false;
        }

        if ($user->hasClinicPermission(ClinicPermission::PatientEdit)) {
            return true;
        }

        if ($patient->current_practitioner_id === null) {
            return true;
        }

        $employee = $this->clinicContext->currentEmployee();

        return $employee !== null && $employee->id === $patient->current_practitioner_id;
    }

    /**
     * Determines whether the user can add a new visit (repeat case) for a patient.
     */
    public function addVisit(User $user, Patient $patient): bool
    {
        if (! $this->isClinicUser($user)) {
            return false;
        }

        return $user->hasPermission('patients.create');
    }

    /**
     * Deletion is restricted to clinic administrators only.
     */
    public function delete(User $user, Patient $patient): bool
    {
        return false; // Patients are never hard-deleted; they are deactivated.
    }

    public function restore(User $user, Patient $patient): bool
    {
        return false;
    }

    public function forceDelete(User $user, Patient $patient): bool
    {
        return false;
    }
}
