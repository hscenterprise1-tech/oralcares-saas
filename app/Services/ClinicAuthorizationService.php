<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ClinicEmployee;

class ClinicAuthorizationService
{
    /**
     * Resolve permissions for a clinic employee, optionally scoped to a branch.
     */
    public function hasPermission(ClinicEmployee $employee, string $permission, ?Branch $branch = null): bool
    {
        if ($branch !== null) {
            $hasBranchAccess = $employee->branches()->where('branch_id', $branch->id)->exists();
            if (! $hasBranchAccess) {
                return false;
            }
        }

        return $employee->roles()
            ->where('is_active', true)
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('permission', $permission);
            })
            ->exists();
    }
}
