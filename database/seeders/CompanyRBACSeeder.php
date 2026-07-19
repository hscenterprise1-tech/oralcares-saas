<?php

namespace Database\Seeders;

use App\CompanyRoleStatus;
use App\Models\CompanyPermission;
use App\Models\CompanyRole;
use Illuminate\Database\Seeder;

class CompanyRBACSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Seed Permissions
        $permissions = [
            // Dashboard
            ['name' => 'View Dashboard', 'key' => 'dashboard.view', 'module' => 'Dashboard', 'description' => 'Ability to view the main company dashboard.'],

            // CRM
            ['name' => 'View CRM', 'key' => 'crm.view', 'module' => 'CRM', 'description' => 'Ability to view CRM dashboard and leads.'],
            ['name' => 'Create CRM Lead', 'key' => 'crm.create', 'module' => 'CRM', 'description' => 'Ability to create CRM records.'],
            ['name' => 'Update CRM Lead', 'key' => 'crm.update', 'module' => 'CRM', 'description' => 'Ability to update CRM records.'],
            ['name' => 'Delete CRM Lead', 'key' => 'crm.delete', 'module' => 'CRM', 'description' => 'Ability to delete CRM records.'],

            // Leads
            ['name' => 'View Leads', 'key' => 'leads.view', 'module' => 'Leads', 'description' => 'Ability to view leads.'],
            ['name' => 'Create Lead', 'key' => 'leads.create', 'module' => 'Leads', 'description' => 'Ability to create new leads.'],
            ['name' => 'Update Lead', 'key' => 'leads.update', 'module' => 'Leads', 'description' => 'Ability to update lead info.'],
            ['name' => 'Delete Lead', 'key' => 'leads.delete', 'module' => 'Leads', 'description' => 'Ability to delete leads.'],

            // Tasks
            ['name' => 'View Tasks', 'key' => 'tasks.view', 'module' => 'Tasks', 'description' => 'Ability to view assigned or public tasks.'],
            ['name' => 'Manage Tasks', 'key' => 'tasks.manage', 'module' => 'Tasks', 'description' => 'Ability to create, update, and delete tasks.'],

            // Trials & Onboarding
            ['name' => 'Manage Trials', 'key' => 'trials.manage', 'module' => 'Trials', 'description' => 'Ability to approve or modify client trial periods.'],
            ['name' => 'Manage Onboarding', 'key' => 'onboarding.manage', 'module' => 'Onboarding', 'description' => 'Ability to run clinic onboarding flows.'],

            // Clinics
            ['name' => 'View Clinics', 'key' => 'clinics.view', 'module' => 'Clinics', 'description' => 'Ability to view clinic records.'],
            ['name' => 'Manage Clinics', 'key' => 'clinics.manage', 'module' => 'Clinics', 'description' => 'Ability to edit or suspend clinics.'],

            // Subscriptions & Renewals
            ['name' => 'View Subscriptions', 'key' => 'subscriptions.view', 'module' => 'Subscriptions', 'description' => 'Ability to view subscription statuses.'],
            ['name' => 'Manage Subscriptions', 'key' => 'subscriptions.manage', 'module' => 'Subscriptions', 'description' => 'Ability to upgrade or cancel subscriptions.'],
            ['name' => 'Manage Renewals', 'key' => 'renewals.manage', 'module' => 'Renewals', 'description' => 'Ability to process renewal requests.'],

            // Support
            ['name' => 'View Tickets', 'key' => 'support.view', 'module' => 'Support', 'description' => 'Ability to view customer support tickets.'],
            ['name' => 'Assign Tickets', 'key' => 'support.assign', 'module' => 'Support', 'description' => 'Ability to assign tickets to agents.'],
            ['name' => 'Reply to Tickets', 'key' => 'support.reply', 'module' => 'Support', 'description' => 'Ability to reply and close support tickets.'],

            // Employees
            ['name' => 'View Employees', 'key' => 'employees.view', 'module' => 'Employees', 'description' => 'Ability to view employee directory.'],
            ['name' => 'Manage Employees', 'key' => 'employees.manage', 'module' => 'Employees', 'description' => 'Ability to create and update employee profiles.'],

            // Departments & Teams
            ['name' => 'Manage Departments', 'key' => 'departments.manage', 'module' => 'Departments', 'description' => 'Ability to configure departments.'],
            ['name' => 'Manage Teams', 'key' => 'teams.manage', 'module' => 'Teams', 'description' => 'Ability to configure internal teams.'],

            // Reports & Analytics
            ['name' => 'View Reports', 'key' => 'reports.view', 'module' => 'Reports', 'description' => 'Ability to download business reports.'],
            ['name' => 'View Analytics', 'key' => 'analytics.view', 'module' => 'Analytics', 'description' => 'Ability to view company growth analytics.'],

            // Finance
            ['name' => 'View Finance', 'key' => 'finance.view', 'module' => 'Finance', 'description' => 'Ability to view invoicing and accounts.'],
            ['name' => 'Manage Finance', 'key' => 'finance.manage', 'module' => 'Finance', 'description' => 'Ability to process payouts and edit invoices.'],

            // Settings
            ['name' => 'Manage Settings', 'key' => 'settings.manage', 'module' => 'Settings', 'description' => 'Ability to change company-wide platform settings.'],
        ];

        $createdPermissions = [];
        foreach ($permissions as $permData) {
            $createdPermissions[$permData['key']] = CompanyPermission::updateOrCreate(
                ['key' => $permData['key']],
                [
                    'name' => $permData['name'],
                    'module' => $permData['module'],
                    'description' => $permData['description'],
                ]
            );
        }

        // Helper to retrieve permission IDs by keys
        $getIds = fn (array $keys) => array_map(fn ($k) => $createdPermissions[$k]->id, $keys);

        // 2. Seed Roles and assign permissions
        $roles = [
            'ceo' => [
                'name' => 'CEO',
                'description' => 'Chief Executive Officer with full corporate privileges.',
                'permissions' => array_keys($createdPermissions),
            ],
            'operations-manager' => [
                'name' => 'Operations Manager',
                'description' => 'Manages day-to-day business operations, clinic trial onboarding, and subscriptions.',
                'permissions' => [
                    'dashboard.view', 'clinics.view', 'clinics.manage', 'trials.manage', 'onboarding.manage',
                    'subscriptions.view', 'subscriptions.manage', 'renewals.manage', 'employees.view', 'reports.view',
                ],
            ],
            'sales-manager' => [
                'name' => 'Sales Manager',
                'description' => 'Leads corporate sales, manages leads, CRM pipeline, and CRM tasks.',
                'permissions' => [
                    'dashboard.view', 'crm.view', 'crm.create', 'crm.update', 'crm.delete',
                    'leads.view', 'leads.create', 'leads.update', 'leads.delete', 'tasks.view',
                    'tasks.manage', 'reports.view', 'analytics.view',
                ],
            ],
            'sales-executive' => [
                'name' => 'Sales Executive',
                'description' => 'Manages client pipelines, inputs prospects into CRM, and resolves CRM tasks.',
                'permissions' => [
                    'dashboard.view', 'crm.view', 'crm.create', 'crm.update',
                    'leads.view', 'leads.create', 'leads.update', 'tasks.view', 'tasks.manage',
                ],
            ],
            'support-manager' => [
                'name' => 'Support Manager',
                'description' => 'Oversees technical support, ticket assignment, and customer satisfaction KPIs.',
                'permissions' => [
                    'dashboard.view', 'support.view', 'support.assign', 'support.reply', 'tasks.view',
                    'tasks.manage', 'reports.view',
                ],
            ],
            'support-executive' => [
                'name' => 'Support Executive',
                'description' => 'Answers support queries, resolves tickets, and closes support records.',
                'permissions' => [
                    'dashboard.view', 'support.view', 'support.reply', 'tasks.view', 'tasks.manage',
                ],
            ],
            'finance-manager' => [
                'name' => 'Finance Manager',
                'description' => 'Oversees corporate accounts, invoices, billing reconciliations, and finances.',
                'permissions' => [
                    'dashboard.view', 'finance.view', 'finance.manage', 'reports.view',
                ],
            ],
            'finance-executive' => [
                'name' => 'Finance Executive',
                'description' => 'Generates client invoices, monitors payouts, and processes financial ledgers.',
                'permissions' => [
                    'dashboard.view', 'finance.view',
                ],
            ],
            'hr-manager' => [
                'name' => 'HR Manager',
                'description' => 'Manages employee onboarding, updates employee catalog, and configures teams.',
                'permissions' => [
                    'dashboard.view', 'employees.view', 'employees.manage', 'departments.manage', 'teams.manage',
                ],
            ],
            'developer' => [
                'name' => 'Developer',
                'description' => 'Engineering staff with developer accesses.',
                'permissions' => array_keys($createdPermissions), // Full access
            ],
            'marketing-executive' => [
                'name' => 'Marketing Executive',
                'description' => 'Manages public relations, campaigns, social leads, and corporate analytics.',
                'permissions' => [
                    'dashboard.view', 'crm.view', 'leads.view', 'leads.create', 'leads.update', 'analytics.view',
                ],
            ],
        ];

        foreach ($roles as $key => $roleData) {
            $role = CompanyRole::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                    'status' => CompanyRoleStatus::Active,
                ]
            );

            // Sync permissions
            $role->permissions()->sync($getIds($roleData['permissions']));
        }
    }
}
