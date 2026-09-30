<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CompanyPortalRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create Permissions
        $permissions = [
            'view executive dashboard',
            'manage billing',
            'view own leads',
            'create leads',
            'edit own leads',
            'convert leads',

            // Sidebar Permissions
            'view-crm',
            'view-leads',
            'view-pipeline',
            'view-demos',
            'view-trials',
            'view-organizations',
            'manage-organizations',
            'view-quotations',
            'view-subscriptions',
            'view-renewals',
            'view-onboarding',
            'view-pending-onboarding',
            'view-marketing',
            'view-campaigns',
            'view-templates',
            'view-contacts',
            'view-ai-content',
            'view-analytics',
            'view-clinics',
            'view-branches',
            'view-doctors',
            'view-employees',
            'manage-employees',
            'view-operations',
            'view-support',
            'view-tasks',
            'view-notifications',
            'view-finance',
            'view-revenue',
            'view-payments',
            'view-invoices',
            'view-plans',
            'view-expenses',
            /*
             * The same split payroll needed, for the same reason.
             *
             * `POST /company/finance/expenses` was gated on `view-expenses` and
             * wrote the row `approved` in that one request — anybody who could
             * read the expense ledger could enter and self-approve any amount.
             * Submitting takes `manage-expenses`; approving takes
             * `approve-expenses`, so the person who claims an expense is not
             * the person who signs it off.
             */
            'manage-expenses',
            'approve-expenses',
            'view-payroll',
            /*
             * Reading payroll and paying it are different authorities.
             * `POST /company/finance/payroll` was gated on `view-payroll`, so
             * anybody who could look at the payroll could also create a record
             * and mark it paid for any amount. Mutations take `manage-payroll`;
             * moving a run past approval takes `approve-payroll`, so the person
             * who calculates a run is not automatically the person who signs it
             * off.
             */
            'manage-payroll',
            'approve-payroll',
            /*
             * Attendance and leave, split the same way payroll and expenses
             * are. Reading is one authority, recording another, and deciding on
             * somebody's leave a third — `approve-leave` is also the HR
             * fallback for the four people on record who have no manager and
             * whose requests would otherwise be undecidable.
             */
            'view-attendance',
            'manage-attendance',
            'view-leave',
            'manage-leave',
            'approve-leave',
            'view-incentives',
            'manage-incentives',
            /*
             * Customer Operations. `view-support` already existed and pointed
             * at nothing — there was no support table, route or controller
             * anywhere. `manage-support`, `view-reports` and
             * `manage-notifications` are new; audit stays read-only and needs
             * only `view-audit-logs`.
             */
            /*
             * Account lifecycle. `view-subscriptions` and `view-renewals`
             * already existed; usage and provisioning had no permission at all,
             * so their surfaces could not have been gated.
             */
            'view-usage',
            'view-provisioning',
            'manage-support',
            'view-reports',
            'manage-notifications',
            'view-platform',
            'view-integrations',
            'view-audit-logs',
            'manage-settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create Roles and Assign Permissions
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions($permissions);

        $salesRep = Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
        $salesRep->syncPermissions([
            'view own leads',
            'create leads',
            'edit own leads',
            'convert leads',
            'view-crm',
            'view-leads',
            'view-pipeline',
            'view-demos',
            'view-trials',
            'view-quotations',
            'view-contacts',
            'view-tasks',
            // Deliberately NOT 'view-operations' — CrmDashboardController treats
            // that as a manager-level signal (it unlocks the team performance
            // table), so it belongs to CRM Manager / Sales Manager, not a rep.
        ]);

        $this->seedCustomerSuccessRoles();
    }

    /**
     * The two people who actually run Customer Success.
     *
     * Until now there were none. Every Customer Success permission —
     * `view-organizations`, `view-subscriptions`, `view-usage`,
     * `view-renewals`, `view-provisioning`, `manage-organizations` — belonged
     * to Super Admin and to nobody else, so a company that wanted to staff the
     * module had exactly one option: make every customer success rep a platform
     * administrator. At a hundred and twenty thousand clinics that is a team of
     * dozens holding the keys to the whole product.
     *
     * Two personas, split on the one thing that matters — who may change a
     * customer:
     *
     *   Executive  reads every surface and raises renewal and expansion deals
     *              into CRM, which is the daily job. Cannot alter an account.
     *   Manager    the same, plus `manage-organizations`: health and churn,
     *              the onboarding machine, and the customer's clinic and branch
     *              structure.
     *
     * Neither carries `view-operations` or `manage-settings`. Reading the
     * customer book is not a reason to hold CRM's management surfaces.
     */
    protected function seedCustomerSuccessRoles(): void
    {
        $read = [
            'view-crm',
            'view-organizations',
            'view-subscriptions',
            'view-renewals',
            'view-usage',
            'view-provisioning',
            'view-onboarding',
            /*
             * Raising the renewal is the job. `create leads` is what the
             * "Create renewal deal" and "Create expansion deal" actions
             * authorise against — without it the queue is a list you can read
             * and not act on.
             */
            'create leads',
        ];

        foreach ([...$read, 'manage-organizations'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'Customer Success Executive', 'guard_name' => 'web'])
            ->syncPermissions($read);

        Role::firstOrCreate(['name' => 'Customer Success Manager', 'guard_name' => 'web'])
            ->syncPermissions([...$read, 'manage-organizations']);
    }
}
