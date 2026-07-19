<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class ClinicRoleService
{
    /**
     * Create a clinic-specific role with mapped permissions.
     */
    public function createRole(Clinic $clinic, string $name, string $key, array $permissions = []): Role
    {
        return DB::transaction(function () use ($clinic, $name, $key, $permissions) {
            $role = Role::create([
                'clinic_id' => $clinic->id,
                'key' => $key,
                'name' => $name,
                'is_system' => false,
                'is_active' => true,
            ]);

            foreach ($permissions as $perm) {
                $role->permissions()->create(['permission' => $perm]);
            }

            return $role;
        });
    }

    /**
     * Assign a role to a clinic employee.
     */
    public function assignRoleToEmployee(ClinicEmployee $employee, Role $role): void
    {
        $employee->roles()->syncWithoutDetaching([$role->id]);
    }
}
