<?php

namespace App\Policies;

use App\Models\Demo;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;

class DemoPolicy
{
    use ChecksCrmPermissions;

    protected function checkTenant(User $user, ?Demo $demo = null): bool
    {
        // Requires an explicit CRM permission — being a CompanyUser is no
        // longer sufficient on its own.
        if (! $this->hasAnyCrmPermission($user, 'view-demos', 'view-crm', 'view-leads')) {
            return false;
        }

        if ($demo && $demo->lead) {
            if ($user->organization_id && $demo->lead->organization_id && (int) $user->organization_id !== (int) $demo->lead->organization_id) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->checkTenant($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Demo $demo): bool
    {
        return $this->checkTenant($user, $demo);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->checkTenant($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Demo $demo): bool
    {
        return $this->checkTenant($user, $demo);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Demo $demo): bool
    {
        return $this->checkTenant($user, $demo);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Demo $demo): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Demo $demo): bool
    {
        return false;
    }
}
