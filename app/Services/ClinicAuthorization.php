<?php

namespace App\Services;

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\ClinicMembershipRole;
use App\PermissionKey;
use App\UserType;
use InvalidArgumentException;

class ClinicAuthorization
{
    /**
     * Create a new class instance.
     */
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Determine if the current authenticated context can perform the permission.
     *
     * @throws InvalidArgumentException
     */
    public function can(PermissionKey|string $permission, ?Branch $branch = null): bool
    {
        $permValue = $permission instanceof PermissionKey ? $permission->value : $permission;

        if (! PermissionKey::isValid($permValue)) {
            throw new InvalidArgumentException("Unknown permission key: {$permValue}");
        }

        $user = $this->clinicContext->user();
        if (! $user) {
            return false;
        }

        if ($user->user_type !== UserType::ClinicUser) {
            return false;
        }

        $membership = $this->clinicContext->membership();
        if (! $membership) {
            return false;
        }

        if ($membership->status !== ClinicMembershipStatus::Active) {
            return false;
        }

        if ($branch !== null && $branch->clinic_id !== $membership->clinic_id) {
            return false;
        }

        // Primary Owner full-access override
        if ($membership->is_primary_owner) {
            return true;
        }

        // Non-owner permission evaluation
        if ($branch !== null) {
            // Must have branch access first
            if (! $membership->hasAccessToBranch($branch)) {
                return false;
            }

            return ClinicMembershipRole::where('clinic_membership_id', $membership->id)
                ->whereHas('role', function ($query) use ($permValue) {
                    $query->where('is_active', true)
                        ->whereHas('permissions', function ($q) use ($permValue) {
                            $q->where('permission', $permValue);
                        });
                })
                ->where(function ($query) use ($branch) {
                    $query->whereNull('branch_id')
                        ->orWhere('branch_id', $branch->id);
                })
                ->exists();
        }

        // Clinic-wide check (requires a clinic-wide role assignment)
        return ClinicMembershipRole::where('clinic_membership_id', $membership->id)
            ->whereNull('branch_id')
            ->whereHas('role', function ($query) use ($permValue) {
                $query->where('is_active', true)
                    ->whereHas('permissions', function ($q) use ($permValue) {
                        $q->where('permission', $permValue);
                    });
            })
            ->exists();
    }

    /**
     * Determine if the current authenticated context cannot perform the permission.
     *
     * @throws InvalidArgumentException
     */
    public function cannot(PermissionKey|string $permission, ?Branch $branch = null): bool
    {
        return ! $this->can($permission, $branch);
    }
}
