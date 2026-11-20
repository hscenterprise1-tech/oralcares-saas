<?php

use App\ClinicPermission;
use App\ClinicRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Cross-branch clinical notes (TR-14, D5): `clinical.cross_branch` on the
     * existing global role templates that hold it in code (Clinic Admin,
     * Doctor). Nothing else a template holds is touched, and custom roles are
     * not. Owners see every branch already. Safe to run again.
     */
    private const ROLES = ['Clinic Admin', 'Doctor'];

    public function up(): void
    {
        $this->apply(fn (Role $role, Permission $permission) => $role->hasPermissionTo($permission) || $role->givePermissionTo($permission));
    }

    public function down(): void
    {
        $this->apply(fn (Role $role, Permission $permission) => $role->hasPermissionTo($permission) && $role->revokePermissionTo($permission));
    }

    /**
     * @param  callable(Role, Permission): mixed  $change
     */
    private function apply(callable $change): void
    {
        setPermissionsTeamId(null);
        $permission = Permission::firstOrCreate(['name' => ClinicPermission::ClinicalCrossBranch->value, 'guard_name' => 'web']);

        foreach (self::ROLES as $name) {
            if (ClinicRole::tryFrom($name) === null) {
                continue;
            }
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->whereNull('branch_id')->first();
            if ($role) {
                $change($role, $permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
