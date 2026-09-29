<?php

use App\ClinicPermission;
use App\ClinicRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `visit.view` and `patient.search` are new. The visits list used to borrow
 * `patient.view`, and patient search / duplicate-check had no permission at all.
 *
 * Existing clinic roles (the global templates and any per-branch copies, matched
 * by role name) receive each new permission when their template includes it, so
 * no current user loses a screen they are meant to have.
 */
return new class extends Migration
{
    /** @var array<int, ClinicPermission> */
    private array $added = [ClinicPermission::VisitView, ClinicPermission::PatientSearch];

    public function up(): void
    {
        $permissions = config('permission.table_names.permissions');
        $roles = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');

        foreach ($this->added as $permission) {
            $exists = DB::table($permissions)->where('name', $permission->value)->where('guard_name', 'web')->exists();

            if (! $exists) {
                DB::table($permissions)->insert([
                    'name' => $permission->value,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $permissionId = DB::table($permissions)->where('name', $permission->value)->where('guard_name', 'web')->value('id');

            foreach (ClinicRole::cases() as $clinicRole) {
                if (! in_array($permission->value, $clinicRole->permissions(), true)) {
                    continue;
                }

                $roleIds = DB::table($roles)->where('name', $clinicRole->value)->where('guard_name', 'web')->pluck('id');

                foreach ($roleIds as $roleId) {
                    DB::table($roleHasPermissions)->insertOrIgnore([
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissions = config('permission.table_names.permissions');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');

        $ids = DB::table($permissions)
            ->whereIn('name', array_map(fn (ClinicPermission $permission) => $permission->value, $this->added))
            ->pluck('id');

        DB::table($roleHasPermissions)->whereIn('permission_id', $ids)->delete();
        DB::table($permissions)->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
