<?php

namespace App\Policies;

use App\Models\PatientClinicalAccessAudit;
use App\Models\User;

class PatientClinicalAccessAuditPolicy
{
    /**
     * Determine whether the user can view any clinical access audits.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('clinical.audit.view')
            || $user->hasPermissionTo('governance.view')
            || $user->isClinicOwner();
    }

    /**
     * Determine whether the user can view a specific clinical access audit.
     */
    public function view(User $user, PatientClinicalAccessAudit $audit): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can create clinical access audits.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Audits are strictly immutable: updates are unconditionally forbidden.
     */
    public function update(User $user, PatientClinicalAccessAudit $audit): bool
    {
        return false;
    }

    /**
     * Audits are strictly immutable: deletion is unconditionally forbidden.
     */
    public function delete(User $user, PatientClinicalAccessAudit $audit): bool
    {
        return false;
    }
}
