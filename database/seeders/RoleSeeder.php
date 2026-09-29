<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            'Super Admin',
            'CEO',
            'Operations',
            'Sales',
            'CRM',
            'Support',
            'Finance',
            'HR',
            'Marketing',
            'Developer',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Prepare CRM permissions
        $crmPermissions = [
            'view_crm',
            'manage_leads',
            'manage_organizations',
            'manage_contacts',
            'manage_activities',
            'manage_tasks',
        ];

        foreach ($crmPermissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        // Architecture definition (No restrictive middleware yet)
        $crmRoles = ['Super Admin', 'CEO', 'Operations', 'Sales', 'CRM', 'Support'];
        foreach ($crmRoles as $r) {
            $role = Role::findByName($r, 'web');
            $role->syncPermissions($crmPermissions);
        }

        // Assign 'Super Admin' role to the master user
        // Disabled because team_foreign_key (branch_id) is strictly NOT NULL
        // and Master Admin already has overarching access via UserType::MasterAdmin.
        /*
        $masterEmail = config('auth.master_admin.email');
        if ($masterEmail) {
            $masterUser = User::where('email', $masterEmail)->first();
            if ($masterUser) {
                $masterUser->assignRole('Super Admin');
            }
        }
        */
    }
}
