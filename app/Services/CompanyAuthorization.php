<?php

namespace App\Services;

use App\CompanyRoleStatus;
use App\EmploymentStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyPermission;
use Illuminate\Support\Facades\Cache;

class CompanyAuthorization
{
    /**
     * Determine if the employee has the given permission.
     */
    public function hasPermission(CompanyEmployee $employee, string $permissionKey): bool
    {
        // 1. Employee must be active
        if ($employee->employment_status !== EmploymentStatus::Active) {
            return false;
        }

        // 2. Check active roles and their active permissions
        return $employee->roles()
            ->where('company_roles.status', CompanyRoleStatus::Active)
            ->whereHas('permissions', function ($query) use ($permissionKey) {
                $query->where('company_permissions.key', $permissionKey);
            })
            ->exists();
    }

    /**
     * Determine if the employee has the given role.
     */
    public function hasRole(CompanyEmployee $employee, string $roleKey): bool
    {
        if ($employee->employment_status !== EmploymentStatus::Active) {
            return false;
        }

        return $employee->roles()
            ->where('company_roles.key', $roleKey)
            ->where('company_roles.status', CompanyRoleStatus::Active)
            ->exists();
    }

    /**
     * Determine if a key is a valid company permission key.
     */
    public function isPermissionKey(string $key): bool
    {
        return Cache::remember(
            "co_permission_exists_{$key}",
            now()->addMinutes(60),
            fn () => CompanyPermission::where('key', $key)->exists()
        );
    }
}
