<?php

namespace App\Services;

use App\ClinicRole;
use App\Models\Branch;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Assigns clinic staff roles under the correct Spatie team (branch) scope.
 *
 * The Spatie permissions team id is global request state (see
 * `getPermissionsTeamId()`/`setPermissionsTeamId()`). A plain
 * `$user->syncRoles(...)` call picks up whatever team happens to be active
 * for the *current* request — the admin's own active branch, not
 * necessarily the target employee's — so every write here explicitly sets
 * and restores the team, one branch at a time, the same way
 * `CustomerProvisioningEngine` and `Company\People\RoleController` already do.
 */
class ClinicRoleAssignmentService
{
    /**
     * Sync a user's clinic roles across every branch they can access.
     *
     * @param  array<int, string>  $roleNames
     * @param  Collection<int, Branch>|array<int, Branch>  $branches
     */
    public function syncRoles(User $user, array $roleNames, Collection|array $branches): void
    {
        // Clinic staff get clinic roles, nothing else: never a Company,
        // Master Admin or Super Admin role, whichever screen asked.
        $notClinicRoles = array_diff($roleNames, ClinicRole::values());
        if ($notClinicRoles !== []) {
            throw new InvalidArgumentException('Only clinic roles can be given to clinic staff: '.implode(', ', $notClinicRoles).' is not one.');
        }

        if (collect($branches)->pluck('clinic_id')->unique()->count() > 1) {
            throw new InvalidArgumentException('Roles can only be set across branches of one clinic at a time.');
        }

        foreach ($branches as $branch) {
            $previousTeamId = getPermissionsTeamId();

            try {
                setPermissionsTeamId($branch->id);
                $user->unsetRelation('roles');
                $before = $user->getRoleNames()->sort()->values()->all();
                $user->syncRoles($roleNames);
                $after = collect($roleNames)->sort()->values()->all();
            } finally {
                setPermissionsTeamId($previousTeamId);
            }

            // Every change to what a staff member may do, per branch, goes into
            // the security trail with the roles before and after.
            if ($before !== $after) {
                app(SecurityAudit::class)->record('access.roles_changed', $user, [
                    'member_user_id' => $user->id,
                    'branch_id' => $branch->id,
                    'from' => $before,
                    'to' => $after,
                ], ['clinic_id' => $branch->clinic_id, 'branch_id' => $branch->id]);
            }
        }
    }
}
