<?php

namespace App\Services;

use App\BranchStatus;
use App\Models\Branch;
use App\Models\ClinicMembership;
use App\Models\ClinicMembershipRole;
use App\Models\Role;
use InvalidArgumentException;

class AssignRoleToMembershipService
{
    /**
     * Assign a role to a clinic membership.
     *
     * @throws InvalidArgumentException
     */
    public function execute(ClinicMembership $membership, Role $role, ?Branch $branch = null): ClinicMembershipRole
    {
        // 1. Verify role is clinic-scoped (cannot be a system template with clinic_id = null)
        if ($role->clinic_id === null || $role->is_system) {
            throw new InvalidArgumentException('System role templates cannot be directly assigned to clinic memberships.');
        }

        // 2. Validate membership clinic matches role clinic
        if ($membership->clinic_id !== $role->clinic_id) {
            throw new InvalidArgumentException('Membership clinic must match the role clinic.');
        }

        // 3. Verify role is active
        if (! $role->is_active) {
            throw new InvalidArgumentException('Cannot assign an inactive role.');
        }

        // 4. Validate branch scope if branch_id is provided
        if ($branch !== null) {
            if ($branch->clinic_id !== $membership->clinic_id) {
                throw new InvalidArgumentException('The assigned branch must belong to the same clinic.');
            }

            if ($branch->status !== BranchStatus::Active) {
                throw new InvalidArgumentException('Cannot assign a role to an inactive branch.');
            }

            if (! $membership->hasAccessToBranch($branch)) {
                throw new InvalidArgumentException('Membership must have access to the branch before assigning a branch-specific role.');
            }
        }

        // 5. Create assignment (idempotent check)
        return ClinicMembershipRole::firstOrCreate([
            'clinic_membership_id' => $membership->id,
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
        ]);
    }
}
