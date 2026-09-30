<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class MarketingPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'view-marketing-settings',
            'manage-marketing-settings',
            'emergency-marketing-killswitch',
        ];

        foreach ($permissions as $permName) {
            Permission::firstOrCreate(
                ['name' => $permName, 'guard_name' => 'web']
            );
        }

        // Assign to Super Admin / Company Admin roles if they exist
        $rolesToAssignAll = ['super-admin', 'company-admin', 'company_admin', 'admin'];
        foreach ($rolesToAssignAll as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }

        // Assign view & manage to Marketing Manager / Executive roles
        $rolesToAssignManage = ['marketing-manager', 'marketing_manager', 'executive'];
        foreach ($rolesToAssignManage as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(['view-marketing-settings', 'manage-marketing-settings']);
            }
        }
    }
}
