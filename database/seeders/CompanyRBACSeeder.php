<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

            // Sidebar UI Permissions
            ['name' => 'View Onboarding Section', 'key' => 'view-onboarding', 'module' => 'Onboarding', 'description' => 'Ability to view onboarding sidebar section.'],
            ['name' => 'View Pending Onboarding', 'key' => 'view-pending-onboarding', 'module' => 'Onboarding', 'description' => 'Ability to view pending onboarding.'],
            ['name' => 'View Quotations', 'key' => 'view-quotations', 'module' => 'CRM', 'description' => 'Ability to view quotations.'],
            ['name' => 'View Marketing Contacts', 'key' => 'view-contacts', 'module' => 'Marketing', 'description' => 'Ability to view marketing contacts.'],
            ['name' => 'View AI Content Studio', 'key' => 'view-ai-content', 'module' => 'Marketing', 'description' => 'Ability to view AI content studio.'],
            ['name' => 'View Expenses', 'key' => 'view-expenses', 'module' => 'Finance', 'description' => 'Ability to view expenses.'],
            ['name' => 'View Payroll', 'key' => 'view-payroll', 'module' => 'Finance', 'description' => 'Ability to view payroll.'],
            ['name' => 'View Incentives', 'key' => 'view-incentives', 'module' => 'Finance', 'description' => 'Ability to view incentives.'],

            // Settings
            ['name' => 'Manage Settings', 'key' => 'settings.manage', 'module' => 'Settings', 'description' => 'Ability to change company-wide platform settings.'],
        ];

        setPermissionsTeamId(null);

        foreach ($permissions as $permData) {
            Permission::firstOrCreate(['name' => $permData['key'], 'guard_name' => 'web']);
        }

        // 2. Seed Roles and assign permissions
        $roles = [
            'ceo' => [
                'name' => 'CEO',
                'description' => 'Chief Executive Officer with full corporate privileges.',
                'permissions' => array_column($permissions, 'key'),
            ],
            'operations-manager' => [
                'name' => 'Operations Manager',
                'description' => 'Manages day-to-day business operations, clinic trial onboarding, and subscriptions.',
                'permissions' => [
                    'dashboard.view', 'crm.view', 'leads.view', 'tasks.view', 'tasks.manage',
                    'trials.manage', 'onboarding.manage', 'clinics.view', 'clinics.manage',
                    'subscriptions.view', 'subscriptions.manage', 'renewals.manage',
                    'support.view', 'employees.view', 'departments.manage', 'teams.manage',
                    'reports.view', 'analytics.view', 'finance.view',
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
                    'dashboard.view', 'finance.view', 'finance.manage', 'subscriptions.view',
                    'subscriptions.manage', 'renewals.manage', 'reports.view',
                ],
            ],
            'hr-manager' => [
                'name' => 'HR Manager',
                'description' => 'Manages internal company employees, access roles, and teams.',
                'permissions' => [
                    'dashboard.view', 'employees.view', 'employees.manage',
                    'departments.manage', 'teams.manage', 'reports.view',
                ],
            ],
            'system-administrator' => [
                'name' => 'System Administrator',
                'description' => 'Handles global platform settings, integrations, and high-level audits.',
                'permissions' => [
                    'dashboard.view', 'settings.manage', 'analytics.view',
                    'employees.view', 'clinics.view', 'clinics.manage',
                ],
            ],
        ];

        foreach ($roles as $roleKey => $roleData) {
            $role = Role::firstOrCreate(['name' => $roleData['name'], 'guard_name' => 'web', 'branch_id' => null]);
            $role->syncPermissions($roleData['permissions']);
        }
    }
}
