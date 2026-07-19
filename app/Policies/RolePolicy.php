<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\ClinicContext;

class RolePolicy
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Role $role): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        // Allow system roles (clinic_id null) or roles matching the active clinic context
        return $role->clinic_id === null ||
            ($this->clinicContext->hasClinic() && $role->clinic_id === $this->clinicContext->clinic()->id);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Role $role): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        // Cannot update system roles from tenant context
        return ! $role->is_system &&
            $role->clinic_id !== null &&
            $this->clinicContext->hasClinic() &&
            $role->clinic_id === $this->clinicContext->clinic()->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Role $role): bool
    {
        if ($user->isMasterAdmin()) {
            return true;
        }

        return ! $role->is_system &&
            $role->clinic_id !== null &&
            $this->clinicContext->hasClinic() &&
            $role->clinic_id === $this->clinicContext->clinic()->id;
    }
}
