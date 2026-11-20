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
     * The desk sells a package when the patient takes one (owner's decision,
     * 2026-11-05): `package.sell` on the global Receptionist role template, as
     * `ClinicPermission::defaultRolePermissions` now holds it. Nothing else is
     * touched, nor are custom roles. Safe to run again.
     */
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
        $role = Role::query()->where('name', ClinicRole::Receptionist->value)->where('guard_name', 'web')->whereNull('branch_id')->first();
        if ($role) {
            $change($role, Permission::firstOrCreate(['name' => ClinicPermission::PackageSell->value, 'guard_name' => 'web']));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
