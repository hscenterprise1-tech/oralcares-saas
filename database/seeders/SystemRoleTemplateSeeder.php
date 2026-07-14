<?php

namespace Database\Seeders;

use App\Models\Role;
use App\PermissionKey;
use Illuminate\Database\Seeder;

class SystemRoleTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            'clinic_admin' => [
                'name' => 'Clinic Admin',
                'description' => 'Clinic administrator with full permissions.',
                'permissions' => [
                    PermissionKey::ClinicManage->value,
                    PermissionKey::ClinicSettingsManage->value,
                    PermissionKey::BranchManage->value,
                    PermissionKey::StaffManage->value,
                    PermissionKey::RolesManage->value,
                    PermissionKey::ReportsView->value,
                ],
            ],
            'doctor' => [
                'name' => 'Doctor',
                'description' => 'Clinical doctor role.',
                'permissions' => [
                    PermissionKey::ReportsView->value,
                ],
            ],
            'receptionist' => [
                'name' => 'Receptionist',
                'description' => 'Clinic receptionist role.',
                'permissions' => [
                    PermissionKey::ReportsView->value,
                ],
            ],
            'billing_staff' => [
                'name' => 'Billing Staff',
                'description' => 'Billing and finance staff role.',
                'permissions' => [
                    PermissionKey::ReportsView->value,
                ],
            ],
        ];

        foreach ($templates as $key => $data) {
            $role = Role::updateOrCreate(
                ['clinic_id' => null, 'key' => $key],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'is_system' => true,
                    'is_active' => true,
                ]
            );

            // Sync permissions pivot table
            $role->permissions()->delete();
            foreach ($data['permissions'] as $perm) {
                $role->permissions()->create(['permission' => $perm]);
            }
        }
    }
}
