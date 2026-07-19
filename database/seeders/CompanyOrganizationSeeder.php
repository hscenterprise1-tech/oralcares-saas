<?php

namespace Database\Seeders;

use App\DepartmentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyTeam;
use App\TeamStatus;
use Illuminate\Database\Seeder;

class CompanyOrganizationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name' => 'Sales',
                'code' => 'sales',
                'description' => 'Sales and business development department',
                'sort_order' => 10,
            ],
            [
                'name' => 'Support',
                'code' => 'support',
                'description' => 'Customer success and technical support department',
                'sort_order' => 20,
            ],
            [
                'name' => 'Finance',
                'code' => 'finance',
                'description' => 'Finance, accounts, and billing department',
                'sort_order' => 30,
            ],
            [
                'name' => 'Operations',
                'code' => 'operations',
                'description' => 'Internal operations and platform management department',
                'sort_order' => 40,
            ],
            [
                'name' => 'Development',
                'code' => 'development',
                'description' => 'Engineering, product development, and QA department',
                'sort_order' => 50,
            ],
            [
                'name' => 'HR',
                'code' => 'hr',
                'description' => 'Human resources and talent acquisition department',
                'sort_order' => 60,
            ],
            [
                'name' => 'Marketing',
                'code' => 'marketing',
                'description' => 'Marketing, branding, and growth department',
                'sort_order' => 70,
            ],
        ];

        foreach ($departments as $deptData) {
            $department = CompanyDepartment::updateOrCreate(
                ['code' => $deptData['code']],
                [
                    'name' => $deptData['name'],
                    'description' => $deptData['description'],
                    'status' => DepartmentStatus::Active,
                    'sort_order' => $deptData['sort_order'],
                ]
            );

            // Create one default Operations Team inside each department
            CompanyTeam::updateOrCreate(
                ['code' => $deptData['code'].'-operations'],
                [
                    'department_id' => $department->id,
                    'name' => $deptData['name'].' Operations',
                    'description' => 'Default operations team for '.$deptData['name'],
                    'status' => TeamStatus::Active,
                ]
            );
        }
    }
}
