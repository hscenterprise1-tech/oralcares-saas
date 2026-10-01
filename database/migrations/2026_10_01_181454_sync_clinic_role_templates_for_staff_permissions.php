<?php

use App\ClinicRole;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Give existing system role templates the staff document and leave
     * permissions (`staff.documents.verify`, `leave.request`,
     * `leave.approve`) by syncing them to their definitions in code. Roles
     * that do not exist yet are left for the template seeder to create.
     */
    public function up(): void
    {
        setPermissionsTeamId(null);

        foreach (ClinicRole::cases() as $clinicRole) {
            $role = Role::query()->where('name', $clinicRole->value)->where('guard_name', 'web')->whereNull('branch_id')->first();

            if (! $role) {
                continue;
            }

            $role->syncPermissions(collect($clinicRole->permissions())
                ->map(fn (string $permission) => Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']))
                ->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions granted to templates are left in place.
    }
};
