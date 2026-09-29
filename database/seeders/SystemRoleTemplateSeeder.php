<?php

namespace Database\Seeders;

use App\ClinicRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SystemRoleTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Clinic role templates are global (`branch_id = null`) so any branch's
     * team scope can find and assign them — Spatie resolves a role by
     * `branch_id IS NULL OR branch_id = current team`, but the *assignment*
     * (the `spatie_model_has_roles` pivot row) is still stamped with
     * whichever team is active when `assignRole()`/`syncRoles()` runs. See
     * `ClinicRoleAssignmentService`, which sets that team explicitly per
     * branch rather than relying on the acting admin's own session branch.
     */
    public function run(): void
    {
        setPermissionsTeamId(null);

        foreach (ClinicRole::cases() as $clinicRole) {
            $role = Role::firstOrCreate(
                ['name' => $clinicRole->value, 'guard_name' => 'web', 'branch_id' => null]
            );

            $permissions = collect($clinicRole->permissions())
                ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
                ->all();

            $role->syncPermissions($permissions);
        }
    }
}
