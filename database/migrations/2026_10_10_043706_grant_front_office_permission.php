<?php

use App\ClinicPermission;
use App\ClinicRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `front_office.operate` is new: the Front Desk used to be inferred from the
 * Receptionist role name. Every clinic role whose template includes it (the
 * global templates and their per-branch copies, matched by name) receives
 * it, so no one who runs the front office today loses it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissions = config('permission.table_names.permissions');
        $roles = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $name = ClinicPermission::FrontOffice->value;

        if (! DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->exists()) {
            DB::table($permissions)->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }
        $permissionId = DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->value('id');

        foreach (ClinicRole::cases() as $clinicRole) {
            if (! in_array($name, $clinicRole->permissions(), true)) {
                continue;
            }
            foreach (DB::table($roles)->where('name', $clinicRole->value)->where('guard_name', 'web')->pluck('id') as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions');
        $id = DB::table($permissions)->where('name', ClinicPermission::FrontOffice->value)->where('guard_name', 'web')->value('id');

        if ($id !== null) {
            DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $id)->delete();
            DB::table(config('permission.table_names.model_has_permissions'))->where('permission_id', $id)->delete();
            DB::table($permissions)->where('id', $id)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
