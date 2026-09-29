<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksCrmPermissions;
use App\Support\CrmScope;

class OrganizationPolicy
{
    use ChecksCrmPermissions;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->hasAnyCrmPermission($user, 'view-organizations', 'view-crm');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Organization $organization): bool
    {
        if (! $this->hasAnyCrmPermission($user, 'view-organizations', 'view-crm')) {
            return false;
        }

        if ($user->isMasterAdmin() || ! $user->organization_id) {
            return true;
        }

        return CrmScope::customerAccounts($user)->where('organizations.id', $organization->id)->exists()
            || (int) $organization->id === (int) $user->organization_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->hasCrmPermission($user, 'manage-organizations');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Organization $organization): bool
    {
        if (! $this->hasCrmPermission($user, 'manage-organizations')) {
            return false;
        }

        if ($user->isMasterAdmin() || ! $user->organization_id) {
            return true;
        }

        return CrmScope::customerAccounts($user)->where('organizations.id', $organization->id)->exists()
            || (int) $organization->id === (int) $user->organization_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Organization $organization): bool
    {
        return $this->hasCrmPermission($user, 'manage-organizations');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Organization $organization): bool
    {
        return $this->hasCrmPermission($user, 'manage-organizations');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Organization $organization): bool
    {
        return $this->hasCrmPermission($user, 'manage-organizations');
    }
}
