<?php

use App\Http\Controllers\Api\GeoLocationController;
use App\Http\Controllers\Auth\ClinicLoginController;
use App\Http\Controllers\Auth\ClinicPasswordResetController;
use App\Http\Controllers\Auth\ClinicPasswordSetupController;
use App\Http\Controllers\Auth\CompanyLoginController;
use App\Http\Controllers\Clinic\Admin\BranchController as ClinicAdminBranchController;
use App\Http\Controllers\Clinic\Admin\DepartmentController as ClinicAdminDepartmentController;
use App\Http\Controllers\Clinic\Admin\EmployeeController as ClinicAdminEmployeeController;
use App\Http\Controllers\Clinic\Admin\EmployeeInvitationController as ClinicAdminEmployeeInvitationController;
use App\Http\Controllers\Clinic\Admin\RoomController as ClinicAdminRoomController;
use App\Http\Controllers\Clinic\Admin\SettingsController as ClinicAdminSettingsController;
use App\Http\Controllers\Clinic\AppointmentController as ClinicAppointmentController;
use App\Http\Controllers\Clinic\BranchSwitchController;
use App\Http\Controllers\Clinic\ClinicDashboardController;
use App\Http\Controllers\Clinic\OnboardingController as ClinicOnboardingController;
use App\Http\Controllers\Clinic\Patient360Controller;
use App\Http\Controllers\Clinic\PatientController as ClinicPatientController;
use App\Http\Controllers\Clinic\ReceptionController;
use App\Http\Controllers\Clinic\VisitController as ClinicVisitController;
use App\Http\Controllers\Company\Admin\PackageController;
use App\Http\Controllers\Company\Administration\SettingsController as CompanyAdministrationSettingsController;
use App\Http\Controllers\Company\CompanyDashboardController;
use App\Http\Controllers\Company\Crm\CrmDashboardController;
use App\Http\Controllers\Company\Crm\CrmDocumentController;
use App\Http\Controllers\Company\Crm\CrmTaskController;
use App\Http\Controllers\Company\Crm\DealContactController;
use App\Http\Controllers\Company\Crm\DealController;
use App\Http\Controllers\Company\Crm\DemoController;
use App\Http\Controllers\Company\Crm\LeadActivityController;
use App\Http\Controllers\Company\Crm\LeadController;
use App\Http\Controllers\Company\Crm\LeadEmailController;
use App\Http\Controllers\Company\Crm\LeadImportController;
use App\Http\Controllers\Company\Crm\QuotationController;
use App\Http\Controllers\Company\Crm\SalesOperationsController;
use App\Http\Controllers\Company\Crm\TaskController;
use App\Http\Controllers\Company\Crm\TeamController;
use App\Http\Controllers\Company\Crm\TrialController;
use App\Http\Controllers\Company\CustomerSuccess\AccountController as CustomerSuccessAccountController;
use App\Http\Controllers\Company\CustomerSuccess\AccountStructureController;
use App\Http\Controllers\Company\CustomerSuccess\OverviewController as CustomerSuccessOverviewController;
use App\Http\Controllers\Company\CustomerSuccess\RenewalController as CustomerSuccessRenewalController;
use App\Http\Controllers\Company\CustomerSuccess\SubscriptionActionController;
use App\Http\Controllers\Company\Finance\FinanceController;
use App\Http\Controllers\Company\Integrations\IntegrationsController;
use App\Http\Controllers\Company\Lifecycle\ProvisioningController;
use App\Http\Controllers\Company\Lifecycle\SubscriptionController as LifecycleSubscriptionController;
use App\Http\Controllers\Company\Marketing\MarketingAnalyticsController;
use App\Http\Controllers\Company\Marketing\MarketingAudienceController;
use App\Http\Controllers\Company\Marketing\MarketingAutomationController;
use App\Http\Controllers\Company\Marketing\MarketingAutomationExecutionController;
use App\Http\Controllers\Company\Marketing\MarketingCampaignController;
use App\Http\Controllers\Company\Marketing\MarketingContactController;
use App\Http\Controllers\Company\Marketing\MarketingContactImportController;
use App\Http\Controllers\Company\Marketing\MarketingInstagramAccountController;
use App\Http\Controllers\Company\Marketing\MarketingInstagramPostController;
use App\Http\Controllers\Company\Marketing\MarketingOverviewController;
use App\Http\Controllers\Company\Marketing\MarketingSettingsController;
use App\Http\Controllers\Company\Marketing\MarketingSuppressionController;
use App\Http\Controllers\Company\Marketing\MarketingWhatsAppAccountController;
use App\Http\Controllers\Company\Marketing\MarketingWhatsAppConversationController;
use App\Http\Controllers\Company\Marketing\MarketingWhatsAppTemplateController;
use App\Http\Controllers\Company\Marketing\MarketingWhatsAppWebhookController;
use App\Http\Controllers\Company\Operations\AuditController;
use App\Http\Controllers\Company\Operations\NotificationOpsController;
use App\Http\Controllers\Company\Operations\ReportController;
use App\Http\Controllers\Company\Operations\SupportController;
use App\Http\Controllers\Company\People\AttendanceController;
use App\Http\Controllers\Company\People\CompensationController;
use App\Http\Controllers\Company\People\EmployeeController;
use App\Http\Controllers\Company\People\IncentiveController;
use App\Http\Controllers\Company\People\LeaveController;
use App\Http\Controllers\Company\People\LeaveTypeController;
use App\Http\Controllers\Company\People\PayrollController;
use App\Http\Controllers\Company\People\RoleController;
use App\Http\Controllers\Company\People\StructureController;
use App\Http\Controllers\Company\People\WorkingCalendarController;
use App\Http\Controllers\Company\Security\SecurityController;
use App\Http\Controllers\Company\Workflow\WorkflowController;
use App\Http\Controllers\Dev\DesignLabController;
use App\Http\Controllers\NotificationViewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\LeadIntakeController;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Shared\Notification\Controllers\NotificationController;
use App\Shared\Notification\Controllers\NotificationPreferenceController;
use App\Shared\Search\Controllers\GlobalSearchController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return view('welcome');
});

/*
 * Public lead intake.
 *
 * The only unauthenticated door into the pipeline besides the WhatsApp webhook.
 * Throttled per IP because an open POST that writes rows is exactly what gets
 * found and abused; six a minute is far above any real person filling in a form
 * and far below anything worth a bot's time.
 */
Route::get('enquiry', [LeadIntakeController::class, 'show'])->name('public.enquiry');
Route::post('enquiry', [LeadIntakeController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('public.enquiry.store');

// Public Webhook Endpoints (unauthenticated for WhatsApp Cloud API)
Route::get('webhooks/whatsapp', [MarketingWhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [MarketingWhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp.handle');

// Development Tools
if (app()->environment('local')) {
    Route::get('/design-lab', [DesignLabController::class, 'index'])->name('design-lab');
}

// Parse the base domain (e.g., 'localhost' or 'oralcares.in')
$baseDomain = parse_url(config('app.url'), PHP_URL_HOST);

// ==========================================
// PORTAL ROUTES DEFINITIONS
// ==========================================

$companyRoutes = function () {
    Route::get('/', function () {
        return redirect()->route('company.login');
    });
    Route::get('/login', [CompanyLoginController::class, 'create'])->name('company.login');
    Route::post('/login', [CompanyLoginController::class, 'store']);
    Route::post('/logout', [CompanyLoginController::class, 'destroy'])->name('company.logout');

    /*
     * Password recovery and email verification live on Breeze's own routes —
     * `/forgot-password`, `/reset-password/{token}`, `/verify-email` — which
     * have working controllers behind them and now render the Company's own
     * Inertia pages.
     *
     * Ten routes used to sit here. Each rendered a form that posted to a POST
     * route nobody had registered, so every submission was a 405: the Company
     * login page's "forgot password" link led to a dead end, and magic links,
     * one-time codes, PINs, two-factor and user invitations were advertised on
     * public URLs while none of them existed. The pages they rendered are kept
     * in the design system; nothing routes to them until there is a backend.
     */
    Route::get('/session-timeout', function () {
        return Inertia::render('Auth/SessionTimeout');
    })->name('company.session-timeout');
    Route::get('/session-expired', function () {
        return Inertia::render('Auth/SessionExpired');
    })->name('company.session-expired');
    Route::get('/unauthorized', function () {
        return Inertia::render('Auth/Unauthorized');
    })->name('company.unauthorized');
    Route::get('/error/403', function () {
        return Inertia::render('Auth/Error403');
    })->name('company.error.403');
    Route::get('/error/404', function () {
        return Inertia::render('Auth/Error404');
    })->name('company.error.404');
    Route::get('/error/500', function () {
        return Inertia::render('Auth/Error500');
    })->name('company.error.500');
    Route::get('/maintenance', function () {
        return Inertia::render('Auth/Maintenance');
    })->name('company.maintenance');
    Route::get('/offline', function () {
        return Inertia::render('Auth/Offline');
    })->name('company.offline');
    Route::get('/browser-unsupported', function () {
        return Inertia::render('Auth/BrowserUnsupported');
    })->name('company.browser-unsupported');

    Route::middleware(['company_portal'])->group(function () {
        Route::get('/dashboard', [CompanyDashboardController::class, 'index'])
            ->middleware('can:view executive dashboard')
            ->name('company.dashboard');

        // People (HR) Module
        /*
         * Packages define what may be sold, which is configuration rather than
         * a selling surface — it belongs beside People, and its navigation
         * already sat under Admin while the code sat under CRM.
         */
        Route::prefix('admin')->name('company.admin.')->group(function () {
            Route::get('packages', [PackageController::class, 'index'])->middleware('can:crm.packages.view')->name('packages.index');
            Route::post('packages', [PackageController::class, 'store'])->middleware('can:crm.packages.manage')->name('packages.store');
            Route::put('packages/{package}', [PackageController::class, 'update'])->middleware('can:crm.packages.manage')->name('packages.update');
            Route::delete('packages/{package}', [PackageController::class, 'destroy'])->middleware('can:crm.packages.manage')->name('packages.destroy');
        });
        Route::prefix('people')->name('company.people.')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index'])
                ->middleware('can:view-employees')
                ->name('employees.index');
            Route::get('/employees/{employee}', [EmployeeController::class, 'show'])
                ->middleware('can:view-employees')
                ->name('employees.show');
            Route::post('/employees', [EmployeeController::class, 'store'])
                ->middleware('can:manage-employees')
                ->name('employees.store');
            Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
                ->middleware('can:manage-employees')
                ->name('employees.update');
            /*
             * The HQ org chart. Nine departments and nine teams have existed
             * with no surface; `company_employees` files people against both.
             * Authorised by CompanyDepartmentPolicy / CompanyTeamPolicy, which
             * name `employees.view`, `departments.manage` and `teams.manage`.
             */
            /*
             * Payroll runs. Reading takes `view-payroll`, changing anything
             * takes `manage-payroll`, and approval takes `approve-payroll` —
             * the separation the old finance route lacked, where a read
             * permission authorised writing a payment.
             */
            Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
            Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store');
            Route::get('/payroll/{period}', [PayrollController::class, 'show'])->name('payroll.show');
            Route::post('/payroll/{period}/calculate', [PayrollController::class, 'calculate'])->name('payroll.calculate');
            Route::post('/payroll/{period}/transition', [PayrollController::class, 'transition'])->name('payroll.transition');
            Route::get('/payroll/{period}/payslip/{entry}', [PayrollController::class, 'payslip'])->name('payroll.payslip');

            /* Compensation belongs to the person, so it hangs off the employee. */
            Route::get('/employees/{employee}/compensation', [CompensationController::class, 'index'])->name('employees.compensation.index');
            Route::post('/employees/{employee}/compensation', [CompensationController::class, 'store'])->name('employees.compensation.store');

            /* A separate award ledger. Not payroll, and deliberately not merged. */
            Route::get('/incentives', [IncentiveController::class, 'index'])->name('incentives.index');
            Route::post('/incentives', [IncentiveController::class, 'store'])->name('incentives.store');

            /*
             * HQ attendance and leave.
             *
             * The index and detail routes carry no `can:` gate on purpose: a
             * person is entitled to their own attendance and their own leave
             * without holding a company-wide read permission, and the
             * controllers narrow every query through `PeopleScope`. Everything
             * that *writes* is gated — either by middleware here, or inside the
             * controller where which permission applies depends on the act
             * (deciding on leave takes a real reporting line or `approve-leave`,
             * and never both being ignored).
             */
            Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            Route::post('/attendance/clock', [AttendanceController::class, 'clock'])->name('attendance.clock');
            Route::post('/attendance', [AttendanceController::class, 'store'])
                ->middleware('can:manage-attendance')
                ->name('attendance.store');
            Route::post('/attendance/{record}/correct', [AttendanceController::class, 'correct'])
                ->middleware('can:manage-attendance')
                ->name('attendance.correct');
            Route::get('/attendance/employees/{employee}', [AttendanceController::class, 'show'])->name('attendance.show');

            Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
            Route::get('/leave/balances', [LeaveController::class, 'balances'])->name('leave.balances');
            Route::post('/leave', [LeaveController::class, 'store'])
                ->middleware('can:manage-leave')
                ->name('leave.store');
            Route::post('/leave/{leave}/decide', [LeaveController::class, 'decide'])->name('leave.decide');
            Route::post('/leave/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');

            Route::get('/leave-types', [LeaveTypeController::class, 'index'])
                ->middleware('can:view-leave')
                ->name('leave-types.index');
            Route::post('/leave-types', [LeaveTypeController::class, 'store'])
                ->middleware('can:manage-leave')
                ->name('leave-types.store');
            Route::put('/leave-types/{leaveType}', [LeaveTypeController::class, 'update'])
                ->middleware('can:manage-leave')
                ->name('leave-types.update');
            Route::post('/leave-balances/entries', [LeaveTypeController::class, 'recordBalanceEntry'])
                ->middleware('can:manage-leave')
                ->name('leave-balances.entries.store');

            Route::get('/working-calendar', [WorkingCalendarController::class, 'index'])
                ->middleware('can:view-attendance')
                ->name('working-calendar.index');
            Route::put('/working-calendar/pattern', [WorkingCalendarController::class, 'updatePattern'])
                ->middleware('can:manage-attendance')
                ->name('working-calendar.pattern.update');
            Route::post('/working-calendar/holidays', [WorkingCalendarController::class, 'storeHoliday'])
                ->middleware('can:manage-attendance')
                ->name('working-calendar.holidays.store');
            Route::delete('/working-calendar/holidays/{holiday}', [WorkingCalendarController::class, 'destroyHoliday'])
                ->middleware('can:manage-attendance')
                ->name('working-calendar.holidays.destroy');
            Route::get('/structure', [StructureController::class, 'index'])->name('structure.index');
            Route::post('/structure/departments', [StructureController::class, 'storeDepartment'])->name('structure.departments.store');
            Route::put('/structure/departments/{department}', [StructureController::class, 'updateDepartment'])->name('structure.departments.update');
            Route::post('/structure/departments/{department}/teams', [StructureController::class, 'storeTeam'])->name('structure.teams.store');
            Route::put('/structure/departments/{department}/teams/{team}', [StructureController::class, 'updateTeam'])->name('structure.teams.update');

            /*
             * Roles are read-only: `spatie_roles` has no column marking a system
             * role, so there is no safe rule for who may edit one. Assignment is
             * writable and runs inside the role's Spatie team scope.
             */
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            Route::post('/roles/{role}/assign', [RoleController::class, 'assign'])->name('roles.assign');
            Route::post('/roles/{role}/revoke', [RoleController::class, 'revoke'])->name('roles.revoke');
            Route::post('/employees/{employee}/transition', [EmployeeController::class, 'transitionStatus'])
                ->middleware('can:manage-employees')
                ->name('employees.transition');
        });

        // Finance Module
        Route::prefix('finance')->name('company.finance.')->group(function () {
            /*
             * The module's front page. Gated on `view-finance`, the permission
             * that means "may see the company's books at all" — the per-surface
             * permissions below narrow it from there.
             */
            Route::get('/', [FinanceController::class, 'overview'])
                ->middleware('can:view-finance')
                ->name('overview');
            Route::get('/revenue', [FinanceController::class, 'revenue'])
                ->middleware('can:view-revenue')
                ->name('revenue.index');
            Route::get('/invoices', [FinanceController::class, 'invoices'])
                ->middleware('can:view-invoices')
                ->name('invoices.index');
            Route::get('/payments', [FinanceController::class, 'payments'])
                ->middleware('can:view-payments')
                ->name('payments.index');
            Route::get('/expenses', [FinanceController::class, 'expensesIndex'])
                ->middleware('can:view-expenses')
                ->name('expenses.index');
            /*
             * Writing an expense is not reading one.
             *
             * This POST was gated on `can:view-expenses` and the row it wrote
             * was saved `approved` in the same request, so anybody who could
             * read the ledger could enter and self-approve any amount.
             * Submitting now takes `manage-expenses`; the approval step takes
             * `approve-expenses` and is authorized inside the controller,
             * because which one applies depends on the target state.
             */
            Route::post('/expenses', [FinanceController::class, 'expensesStore'])
                ->middleware('can:manage-expenses')
                ->name('expenses.store');
            Route::post('/expenses/{expense}/transition', [FinanceController::class, 'expensesTransition'])
                ->middleware('can:view-expenses')
                ->name('expenses.transition');
            /*
             * `GET /company/finance/payroll` — the legacy payroll register —
             * is gone.
             *
             * It was the last reader of the eighteen `company_payrolls` rows
             * that predate periods, compensation and approval, and that
             * People's own payroll code describes as "shown, counted and never
             * touched". Its menu entry had already been removed for that
             * reason, which left a live, gated route that nothing linked to: a
             * second door onto dead data, still reachable by URL and still
             * bookmarkable.
             *
             * Payroll is `company.people.payroll.*` and nowhere else. The
             * `company_payrolls` table and `FinanceController::payrollIndex()`
             * are untouched — removing the route is a navigation change, and
             * deleting the rows would not be.
             */
        });

        /*
         * Security and access review.
         *
         * Read-only. Roles are changed where they already are, in People; this
         * reports what access exists and where a Company route is not enforcing
         * any. Gated on the permission that already means "may see what happened
         * here" rather than a new one.
         */
        Route::get('/security', [SecurityController::class, 'index'])
            ->middleware('can:view-audit-logs')
            ->name('company.security.index');

        /*
         * Integrations — what this company is connected to, and whether it is
         * still working.
         *
         * Read-only. Numbers are connected and disconnected in Marketing
         * settings, which owns that flow; nothing here retries or re-sends.
         * `view-integrations` already existed and gated nothing, because until
         * now there was no integrations route at all.
         */
        Route::get('/integrations', [IntegrationsController::class, 'index'])
            ->middleware('can:view-integrations')
            ->name('company.integrations.index');

        /*
         * Workflow administration — what the workflow engine has been
         * configured to do, across tenants.
         *
         * Read-only. The engine is clinic-scoped by schema: a definition belongs
         * to a clinic, a run to a clinical encounter, and a history entry names
         * a clinic employee as its performer. HQ oversees it; whether HQ may
         * author a tenant's workflow is a business decision, so no write route
         * exists. `view-platform` was already seeded and gated nothing.
         */
        Route::get('/workflows', [WorkflowController::class, 'index'])
            ->middleware('can:view-platform')
            ->name('company.workflows.index');

        /*
         * Centralized Settings — the one canonical configuration hub for
         * Company/HQ, replacing the per-module Settings links that used to
         * each point somewhere different (a module overview, `/profile`, even
         * Security).
         *
         * It renders no privileged data of its own: every section is either a
         * structural placeholder or a card that deep-links to the existing,
         * already-gated route for that concern (Marketing Settings, Roles &
         * Permissions, Leave Types, ...). Authorization for that content still
         * happens on arrival, exactly as it did before this page existed, so
         * this route itself needs nothing beyond the company-portal auth the
         * whole group already requires.
         */
        Route::get('/administration/settings', [CompanyAdministrationSettingsController::class, 'index'])
            ->name('company.administration.settings.index');

        /*
         * Legacy redirects (301) for backwards compatibility.
         * Exactly ONE legacy route per old URL, preserving legacy named routes.
         *
         * Each keeps the permission its destination asks for. A redirect with
         * no middleware answers 301 to somebody the target would refuse, which
         * turns a clean 403 into a bounce into a 403 somewhere else — the same
         * mistake the CRM block below already carries a comment about.
         */
        Route::get('subscriptions', function () {
            return redirect()->to('/company/customer-success/subscriptions', 301);
        })->middleware('can:view-subscriptions')->name('company.subscriptions.index');
        Route::get('subscriptions/{subscription}', function (string $subscription) {
            return redirect()->to("/company/customer-success/subscriptions/{$subscription}", 301);
        })->middleware('can:view-subscriptions')->name('company.subscriptions.show');
        Route::get('usage', function () {
            return redirect()->to('/company/customer-success/usage', 301);
        })->middleware('can:view-usage')->name('company.usage.index');
        Route::get('lifecycle', function () {
            return redirect()->to('/company/customer-success/lifecycle', 301);
        })->middleware('can:view-provisioning')->name('company.lifecycle.index');
        Route::get('lifecycle/{organization}', function (string $organization) {
            return redirect()->to("/company/customer-success/accounts/{$organization}?tab=lifecycle", 301);
        })->middleware('can:view-provisioning')->name('company.lifecycle.show');

        /*
         * Customer Operations — support, reporting, audit and notification ops.
         *
         * Every read is gated; every write takes the matching manage
         * permission. Audit and notification ops carry no write route at all:
         * a history that can be revised is not a history, and delivery
         * outcomes are a record of what happened.
         */
        Route::prefix('support')->name('company.support.')->group(function () {
            Route::get('/', [SupportController::class, 'index'])
                ->middleware('can:view-support')
                ->name('index');
            Route::post('/', [SupportController::class, 'store'])
                ->middleware('can:manage-support')
                ->name('store');
            Route::post('/sla', [SupportController::class, 'updateSla'])
                ->middleware('can:manage-support')
                ->name('sla.update');
            Route::get('/{ticket}', [SupportController::class, 'show'])
                ->middleware('can:view-support')
                ->name('show');
            Route::post('/{ticket}/comment', [SupportController::class, 'comment'])
                ->middleware('can:manage-support')
                ->name('comment');
            Route::post('/{ticket}/update', [SupportController::class, 'update'])
                ->middleware('can:manage-support')
                ->name('update');
        });

        Route::get('/reports', [ReportController::class, 'index'])
            ->middleware('can:view-reports')
            ->name('company.reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])
            ->middleware('can:view-reports')
            ->name('company.reports.export');

        Route::get('/audit', [AuditController::class, 'index'])
            ->middleware('can:view-audit-logs')
            ->name('company.audit.index');

        Route::get('/notifications-ops', [NotificationOpsController::class, 'index'])
            ->middleware('can:view-notifications')
            ->name('company.notifications.ops');

        // Marketing Module (M-1 Contacts Foundation)
        /*
         * Marketing, gated at the group.
         *
         * Every route in here enforced nothing: no `can:` middleware and no
         * authorization call in any action, so any authenticated company user
         * could launch a campaign, delete contacts, connect or delete a
         * WhatsApp account, and throw the emergency broadcast freeze. Forty-four
         * of them wrote data.
         *
         * `view-marketing` already existed for exactly this and was gated on by
         * nothing. Gating the group is the smallest correct fix; the settings
         * and killswitch routes below narrow further onto the two permissions
         * that were seeded for them and likewise never used.
         */
        Route::prefix('marketing')->name('company.marketing.')->middleware('can:view-marketing')->group(function () {
            // Import Batches — list and detail (must be before resource routes)
            Route::get('contacts/import', [MarketingContactImportController::class, 'index'])->name('contacts.import.index');
            Route::get('contacts/import/template', [MarketingContactImportController::class, 'template'])->name('contacts.import.template');
            Route::post('contacts/import/preview', [MarketingContactImportController::class, 'preview'])->name('contacts.import.preview');
            Route::post('contacts/import/process', [MarketingContactImportController::class, 'process'])->name('contacts.import.process');
            Route::get('contacts/import/status/{import}', [MarketingContactImportController::class, 'status'])->name('contacts.import.status');
            Route::get('contacts/import/{import}', [MarketingContactImportController::class, 'show'])->name('contacts.import.show');
            Route::post('contacts/bulk-tag', [MarketingContactController::class, 'bulkTag'])->name('contacts.bulk-tag');
            // Scoped to what the controller implements — a bare resource()
            // also registers create/edit, which 500 on the missing methods.
            // Contacts are created and edited through a modal on the index.
            Route::resource('contacts', MarketingContactController::class)
                ->only(['index', 'store', 'show', 'update', 'destroy']);

            // Audiences Management
            Route::post('audiences/preview-count', [MarketingAudienceController::class, 'previewCount'])->name('audiences.preview-count');
            Route::post('audiences/{audience}/duplicate', [MarketingAudienceController::class, 'duplicate'])->name('audiences.duplicate');
            Route::resource('audiences', MarketingAudienceController::class);

            // WhatsApp Account Management
            Route::get('whatsapp', [MarketingWhatsAppAccountController::class, 'index'])->name('whatsapp.index');
            Route::post('whatsapp', [MarketingWhatsAppAccountController::class, 'store'])->name('whatsapp.store');
            Route::delete('whatsapp/{id}', [MarketingWhatsAppAccountController::class, 'destroy'])->name('whatsapp.destroy');
            Route::post('whatsapp/{id}/test', [MarketingWhatsAppAccountController::class, 'testConnection'])->name('whatsapp.test');

            // WhatsApp Conversations Workspace
            Route::get('conversations/search-leads', [MarketingWhatsAppConversationController::class, 'searchLeads'])->name('conversations.search-leads');
            Route::get('conversations', [MarketingWhatsAppConversationController::class, 'index'])->name('conversations.index');
            Route::get('conversations/{id}/messages', [MarketingWhatsAppConversationController::class, 'messages'])->name('conversations.messages');
            Route::post('conversations/{id}/reply', [MarketingWhatsAppConversationController::class, 'reply'])->name('conversations.reply');
            Route::post('conversations/{id}/read', [MarketingWhatsAppConversationController::class, 'markRead'])->name('conversations.read');
            Route::post('conversations/{id}/create-lead', [MarketingWhatsAppConversationController::class, 'createLead'])->name('conversations.create-lead');
            Route::post('conversations/{id}/link-lead', [MarketingWhatsAppConversationController::class, 'linkLead'])->name('conversations.link-lead');
            Route::post('conversations/{id}/assign', [MarketingWhatsAppConversationController::class, 'assign'])->name('conversations.assign');

            // Templates Management
            Route::get('templates', [MarketingWhatsAppTemplateController::class, 'index'])->name('templates.index');
            Route::post('templates/sync', [MarketingWhatsAppTemplateController::class, 'sync'])->name('templates.sync');

            // Instagram Content Automation (AI draft -> human approval -> auto-publish)
            Route::get('instagram/settings', [MarketingInstagramAccountController::class, 'index'])->name('instagram.settings');
            Route::post('instagram/settings', [MarketingInstagramAccountController::class, 'store'])->name('instagram.settings.store');
            Route::delete('instagram/settings/{id}', [MarketingInstagramAccountController::class, 'destroy'])->name('instagram.settings.destroy');
            Route::post('instagram/settings/{id}/test', [MarketingInstagramAccountController::class, 'testConnection'])->name('instagram.settings.test');
            Route::post('instagram/settings/ai', [MarketingInstagramAccountController::class, 'saveAiSettings'])->name('instagram.settings.ai');
            Route::post('instagram/settings/automation', [MarketingInstagramAccountController::class, 'saveAutomationSettings'])->name('instagram.settings.automation');
            Route::post('instagram/settings/logo', [MarketingInstagramAccountController::class, 'uploadLogo'])->name('instagram.settings.logo');

            Route::get('instagram', [MarketingInstagramPostController::class, 'index'])->name('instagram.index');
            Route::post('instagram/generate', [MarketingInstagramPostController::class, 'generate'])->name('instagram.generate');
            Route::put('instagram/{id}', [MarketingInstagramPostController::class, 'update'])->name('instagram.update');
            Route::post('instagram/{id}/approve', [MarketingInstagramPostController::class, 'approve'])->name('instagram.approve');
            Route::post('instagram/{id}/reject', [MarketingInstagramPostController::class, 'reject'])->name('instagram.reject');
            Route::delete('instagram/{id}', [MarketingInstagramPostController::class, 'destroy'])->name('instagram.destroy');

            // Campaigns Management
            Route::get('campaigns', [MarketingCampaignController::class, 'index'])->name('campaigns.index');
            Route::get('campaigns/create', [MarketingCampaignController::class, 'create'])->name('campaigns.create');
            Route::post('campaigns', [MarketingCampaignController::class, 'store'])->name('campaigns.store');
            Route::get('campaigns/{campaign}', [MarketingCampaignController::class, 'show'])->name('campaigns.show');
            Route::post('campaigns/{campaign}/launch', [MarketingCampaignController::class, 'launch'])->name('campaigns.launch');
            Route::post('campaigns/{campaign}/pause', [MarketingCampaignController::class, 'pause'])->name('campaigns.pause');
            Route::post('campaigns/{campaign}/cancel', [MarketingCampaignController::class, 'cancel'])->name('campaigns.cancel');
            Route::post('campaigns/{campaign}/retry-failed', [MarketingCampaignController::class, 'retryFailed'])->name('campaigns.retry-failed');
            Route::post('campaigns/{campaign}/duplicate', [MarketingCampaignController::class, 'duplicate'])->name('campaigns.duplicate');

            // Automations Management
            Route::get('automations', [MarketingAutomationController::class, 'index'])->name('automations.index');
            Route::get('automations/create', [MarketingAutomationController::class, 'create'])->name('automations.create');
            Route::post('automations', [MarketingAutomationController::class, 'store'])->name('automations.store');
            Route::get('automations/{id}', [MarketingAutomationController::class, 'show'])->name('automations.show');
            Route::get('automations/{id}/edit', [MarketingAutomationController::class, 'edit'])->name('automations.edit');
            Route::put('automations/{id}', [MarketingAutomationController::class, 'update'])->name('automations.update');
            Route::post('automations/{id}/activate', [MarketingAutomationController::class, 'activate'])->name('automations.activate');
            Route::post('automations/{id}/pause', [MarketingAutomationController::class, 'pause'])->name('automations.pause');
            Route::post('automations/{id}/resume', [MarketingAutomationController::class, 'resume'])->name('automations.resume');
            Route::post('automations/{id}/archive', [MarketingAutomationController::class, 'archive'])->name('automations.archive');
            Route::post('automations/{id}/duplicate', [MarketingAutomationController::class, 'duplicate'])->name('automations.duplicate');

            // Execution History
            // Every run across every automation — what the Execution Logs nav entry means.
            Route::get('executions', [MarketingAutomationExecutionController::class, 'index'])->name('executions.index');
            Route::get('automations/{id}/executions', [MarketingAutomationExecutionController::class, 'index'])->name('automations.executions.index');
            Route::get('automations/executions/{executionId}', [MarketingAutomationExecutionController::class, 'show'])->name('automations.executions.show');

            // Analytics & Attribution Foundation
            Route::get('analytics', [MarketingAnalyticsController::class, 'index'])->name('analytics.index');
            Route::get('analytics/campaigns/{id}', [MarketingAnalyticsController::class, 'campaign'])->name('analytics.campaign');
            Route::get('analytics/audiences/{id}', [MarketingAnalyticsController::class, 'audience'])->name('analytics.audience');
            Route::get('analytics/journey/{contact}', [MarketingAnalyticsController::class, 'journey'])->name('analytics.journey');
            Route::get('analytics/export', [MarketingAnalyticsController::class, 'export'])->name('analytics.export');

            // Suppression & Compliance Center Foundation
            Route::get('suppression', [MarketingSuppressionController::class, 'index'])->name('suppression.index');
            Route::get('suppression/{contact}', [MarketingSuppressionController::class, 'show'])->name('suppression.show');
            Route::post('suppression/{contact}/suppress', [MarketingSuppressionController::class, 'suppress'])->name('suppression.suppress');
            Route::post('suppression/{contact}/unsuppress', [MarketingSuppressionController::class, 'unsuppress'])->name('suppression.unsuppress');
            Route::post('suppression/{contact}/opt-out', [MarketingSuppressionController::class, 'optOut'])->name('suppression.opt-out');

            // Settings & Governance Controls Foundation
            Route::get('settings', [MarketingSettingsController::class, 'index'])->name('settings.index');
            Route::put('settings', [MarketingSettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/emergency-freeze', [MarketingSettingsController::class, 'toggleEmergencyFreeze'])->name('settings.emergency-freeze');

            // Marketing Overview Dashboard
            Route::get('overview', [MarketingOverviewController::class, 'index'])->name('overview');
        });

        // Enterprise CRM (Phase E-2)
        //
        // Every route in this group carries a `can:` guard scoped to the
        // narrowest applicable permission. These names resolve through
        // App\Support\CrmPermissions, which also accepts the legacy dot/snake
        // permission conventions still present on some seeded roles. Master
        // admins bypass via the Gate::before hook in AppServiceProvider.
        Route::prefix('crm')->name('company.crm.')->group(function () {
            Route::get('/', [CrmDashboardController::class, 'index'])->middleware('can:crm.dashboard.view')->name('dashboard');
            Route::get('overview', [CrmDashboardController::class, 'index'])->middleware('can:crm.dashboard.view')->name('overview');
            Route::get('my-workspace', [LeadController::class, 'myWorkspace'])->middleware('can:viewAny,App\Models\Lead')->name('my-workspace');
            // Phase 4: Accounts moved to Customer Success; legacy routes preserve authorization and redirect
            Route::redirect('organizations', '/company/customer-success/accounts')
                ->middleware('can:viewAny,App\Models\Organization')
                ->name('organizations.index');
            Route::get('organizations/{organization}', fn (Organization $organization) => redirect("/company/customer-success/accounts/{$organization->id}"))
                ->middleware('can:view,organization')
                ->name('organizations.show');
            Route::put('organizations/{organization}', [CustomerSuccessAccountController::class, 'update'])
                ->middleware('can:update,organization')
                ->name('organizations.update');

            /*
             * The standalone contacts directory is gone.
             *
             * It was a second people system: its own list, its own modal and
             * its own write endpoints, none of which the deal ever used. Deal
             * contacts are managed on the Deal Profile through
             * `leads/{lead}/contacts/*`, which stamps the organisation, keeps
             * the WhatsApp number and enforces one primary per deal — three
             * things the directory's endpoints did not do. The `contacts`
             * table and the Contact model are untouched; only the duplicate
             * surface over them has been removed.
             */

            // Sales Teams & Assignment Configuration
            Route::get('teams', [TeamController::class, 'index'])->middleware('can:crm.teams.view')->name('teams.index');
            Route::get('teams/{team}', [TeamController::class, 'show'])->middleware('can:crm.teams.view')->name('teams.show');
            Route::post('teams', [TeamController::class, 'store'])->middleware('can:crm.teams.manage')->name('teams.store');
            Route::put('teams/{team}', [TeamController::class, 'update'])->middleware('can:crm.teams.manage')->name('teams.update');
            Route::post('teams/{team}/members', [TeamController::class, 'addMember'])->middleware('can:crm.teams.manage')->name('teams.members.store');
            Route::delete('teams/{team}/members/{user}', [TeamController::class, 'removeMember'])->middleware('can:crm.teams.manage')->name('teams.members.destroy');
            Route::post('teams/{team}/toggle-active', [TeamController::class, 'toggleActive'])->middleware('can:crm.teams.manage')->name('teams.toggle-active');
            Route::delete('teams/{team}', [TeamController::class, 'destroy'])->middleware('can:crm.teams.manage')->name('teams.destroy');

            // Packages (trial + subscription plan catalog)

            // Deals — the one destination over the `leads` table.
            //
            // Leads and Sales Pipeline were two pages over the same rows with
            // two filter engines. Both legacy URLs now redirect here rather
            // than breaking bookmarks, and the board is a view of this page
            // rather than a separate product.
            Route::get('deals', [DealController::class, 'index'])
                ->middleware('can:viewAny,App\Models\Lead')
                ->name('deals.index');

            // The legacy URLs keep their authorization. A bare
            // Route::redirect carries no middleware, which would have
            // turned a 403 into a redirect for a user with no CRM access.
            Route::redirect('pipeline', '/company/crm/deals')
                ->middleware('can:viewAny,App\Models\Lead')
                ->name('pipeline');

            Route::redirect('leads/pipeline', '/company/crm/deals')
                ->middleware('can:viewAny,App\Models\Lead')
                ->name('leads.pipeline');
            Route::post('leads/batch-reassign', [LeadController::class, 'batchReassign'])->middleware('can:create,App\Models\Lead')->name('leads.batch-reassign');
            /*
             * Bulk stage moves honour the same per-deal requirements the detail
             * page does — a deal missing a quotation is skipped, not forced —
             * and "mark lost" is this route with a status and a reason rather
             * than an endpoint of its own.
             */
            Route::post('leads/batch-stage', [LeadController::class, 'batchStage'])->middleware('can:create,App\Models\Lead')->name('leads.batch-stage');
            Route::post('leads/batch-task', [LeadController::class, 'batchTask'])->middleware('can:create,App\Models\Lead')->name('leads.batch-task');

            /*
             * A person's own named cuts of the Deals list. Personal by
             * definition, so destroy is scoped to the owner rather than to a
             * policy — there is nobody else a saved view could belong to.
             */
            /*
             * Asked while the create form is being filled in, so a possible
             * duplicate is a warning rather than something you discover after
             * making one. Read-only; a match is not an error.
             */
            Route::get('deals/check-duplicate', [DealController::class, 'checkDuplicate'])->name('deals.check-duplicate');

            Route::post('deals/views', [DealController::class, 'storeSavedView'])->name('deals.views.store');
            Route::delete('deals/views/{savedView}', [DealController::class, 'destroySavedView'])->name('deals.views.destroy');
            Route::post('leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
            Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
            Route::post('leads/{lead}/transition', [LeadController::class, 'transitionStage'])->name('leads.transition');
            /* The one way out of Lost, and it asks why. */
            Route::post('leads/{lead}/reopen', [LeadController::class, 'reopen'])->name('leads.reopen');
            Route::post('leads/{lead}/communications', [LeadController::class, 'logCommunication'])->name('leads.communications');
            Route::post('leads/{lead}/whatsapp', [LeadController::class, 'sendWhatsApp'])->name('leads.whatsapp');
            Route::post('leads/{lead}/email', [LeadEmailController::class, 'store'])->name('leads.email.store');
            Route::post('leads/{lead}/email/{thread}/retry', [LeadEmailController::class, 'retry'])->name('leads.email.retry');
            Route::post('leads/{lead}/call-disposition', [LeadController::class, 'dispositionCall'])->name('leads.call-disposition');
            Route::post('leads/{lead}/followup/complete', [LeadController::class, 'completeFollowup'])->name('leads.followup.complete');

            // Lead Nested Activities, Tasks, Documents
            Route::post('leads/{lead}/activities', [LeadActivityController::class, 'store'])->name('leads.activities.store');
            Route::post('leads/{lead}/tasks', [CrmTaskController::class, 'store'])->name('leads.tasks.store');
            Route::post('leads/{lead}/tasks/{task}/complete', [CrmTaskController::class, 'complete'])->name('leads.tasks.complete');
            Route::delete('leads/{lead}/tasks/{task}', [CrmTaskController::class, 'destroy'])->name('leads.tasks.destroy');
            Route::post('leads/{lead}/documents', [CrmDocumentController::class, 'store'])->name('leads.documents.store');
            Route::get('leads/{lead}/documents/{document}/download', [CrmDocumentController::class, 'download'])->name('leads.documents.download');
            Route::delete('leads/{lead}/documents/{document}', [CrmDocumentController::class, 'destroy'])->name('leads.documents.destroy');

            Route::get('leads/export', [LeadController::class, 'export'])->middleware('can:viewAny,App\Models\Lead')->name('leads.export');

            // Bulk lead creation from a CSV file — reached from a modal on Deals, not a page of its own.
            Route::post('leads/import/preview', [LeadImportController::class, 'preview'])->name('leads.import.preview');
            Route::post('leads/import/process', [LeadImportController::class, 'process'])->name('leads.import.process');
            Route::get('leads/import/{import}/status', [LeadImportController::class, 'status'])->name('leads.import.status');
            /*
             * The full reference record, edited as one form.
             *
             * This replaces `leads/{lead}/inspect`, a byte-identical clone of
             * `show` that nothing linked to and no test covered.
             */
            Route::get('leads/{lead}/profile', [LeadController::class, 'profile'])->name('leads.profile');

            // The old Leads directory is now the Deals "All" view. The URL is
            // kept as a redirect rather than removed — bookmarks and any link
            // outside this repository should still land somewhere useful.
            Route::redirect('leads', '/company/crm/deals')
                ->middleware('can:viewAny,App\Models\Lead')
                ->name('leads.index');

            // Deal Detail keeps its /leads/{lead} URL: Phase 2 rebuilt that
            // page in place, and changing the canonical record URL would break
            // every link on the timeline, in notifications and in email.
            /*
             * `edit` is gone with `Leads/Edit.tsx` — an unlinked third edit
             * surface. Reference data is edited on `leads/{lead}/profile`.
             */
            Route::resource('leads', LeadController::class)
                ->except(['index', 'edit']);

            /*
             * Tasks are written from My Work and from a deal; there is no
             * tasks page.
             *
             * The index was a third list of the same rows — My Work already
             * carries your open tasks, everyone else's open tasks for an
             * admin, and the create/edit modal these endpoints serve. The URL
             * redirects rather than 404s, because a task notification may
             * still point at it.
             */
            Route::redirect('tasks', '/company/crm/my-workspace')
                ->middleware('can:viewAny,App\Models\Task')
                ->name('tasks.index');
            Route::resource('tasks', TaskController::class)->only(['store', 'update', 'destroy']);

            /*
             * `POST crm/activities` is gone with ActivityController.
             *
             * It wrote to the same `activities` table as
             * `leads/{lead}/activities` with a different authorization model
             * and a `user_id ?? 1` fallback, and nothing in the product ever
             * called it. Organisation and contact timelines are written by the
             * services that own those records.
             */

            // Demos
            Route::post('demos/{demo}/confirm', [DemoController::class, 'confirm'])->name('demos.confirm');
            Route::post('demos/{demo}/reschedule', [DemoController::class, 'reschedule'])->name('demos.reschedule');
            Route::post('demos/{demo}/complete', [DemoController::class, 'complete'])->name('demos.complete');
            Route::post('demos/{demo}/no-show', [DemoController::class, 'noShow'])->name('demos.no-show');
            Route::post('demos/{demo}/cancel', [DemoController::class, 'cancel'])->name('demos.cancel');
            Route::post('leads/{lead}/schedule-demo', [DemoController::class, 'store'])->name('leads.schedule-demo');

            /*
             * The demos index is gone; the demo page is not.
             *
             * A demo is contextual — it belongs to a deal and to a day — so it
             * is worked from the deal's Evaluation zone and from My Work's
             * Demos tab, both of which now carry all four actions including
             * the reschedule this page alone used to offer. `demos/{demo}`
             * survives as the one view of a demo that can be sent to whoever
             * is presenting it, linked from the deal.
             */
            Route::redirect('demos', '/company/crm/my-workspace')
                ->middleware('can:viewAny,App\Models\Demo')
                ->name('demos.index');
            Route::resource('demos', DemoController::class)->only(['show', 'store', 'update', 'destroy']);

            // Quotations
            Route::post('quotations/{quotation}/revise', [QuotationController::class, 'revise'])->name('quotations.revise');
            Route::post('quotations/{quotation}/send', [QuotationController::class, 'send'])->name('quotations.send');
            Route::put('quotations/{quotation}/discount', [QuotationController::class, 'discount'])->name('quotations.discount');
            Route::post('quotations/{quotation}/payments', [QuotationController::class, 'storePayment'])->name('quotations.payments.store');
            Route::post('quotations/{quotation}/approve', [QuotationController::class, 'approve'])->name('quotations.approve');
            Route::post('quotations/{quotation}/reject', [QuotationController::class, 'reject'])->name('quotations.reject');
            Route::get('quotations/{quotation}/printable', [QuotationController::class, 'printable'])->name('quotations.printable');
            Route::get('quotations/{quotation}/pdf', [QuotationController::class, 'pdf'])->name('quotations.pdf');
            Route::post('leads/{lead}/create-quotation', [QuotationController::class, 'store'])->name('leads.create-quotation');

            // Proforma invoice, tax invoice and payment receipts
            Route::post('quotations/{quotation}/proforma', [QuotationController::class, 'issueProforma'])->name('quotations.proforma.issue');
            Route::get('quotations/{quotation}/proforma', [QuotationController::class, 'proforma'])->name('quotations.proforma');
            Route::get('quotations/{quotation}/proforma/pdf', [QuotationController::class, 'proformaPdf'])->name('quotations.proforma.pdf');
            Route::get('quotations/{quotation}/tax-invoice', [QuotationController::class, 'taxInvoice'])->name('quotations.tax-invoice');
            Route::get('quotations/{quotation}/tax-invoice/pdf', [QuotationController::class, 'taxInvoicePdf'])->name('quotations.tax-invoice.pdf');
            Route::get('quotations/{quotation}/payments/{payment}/receipt', [QuotationController::class, 'receipt'])->name('quotations.payments.receipt');
            Route::get('quotations/{quotation}/payments/{payment}/receipt/pdf', [QuotationController::class, 'receiptPdf'])->name('quotations.payments.receipt.pdf');

            /*
             * The commercial steps, taken from the deal itself.
             *
             * Agreements could previously only be created from the documents
             * module, and payments only against an invoice — a document type
             * this lifecycle never produces. Both conditions on closing a deal
             * were therefore unreachable from the page that closes deals.
             */

            /*
             * The people on a deal. Deal-scoped rather than routed through the
             * global contacts endpoints, which authorize against the contact,
             * never stamp the organisation a contact row requires, drop the
             * WhatsApp number on update, and let two contacts on one deal both
             * claim to be primary.
             */
            Route::post('leads/{lead}/contacts', [DealContactController::class, 'store'])->name('leads.contacts.store');
            Route::put('leads/{lead}/contacts/{contact}', [DealContactController::class, 'update'])->name('leads.contacts.update');
            Route::post('leads/{lead}/contacts/{contact}/promote', [DealContactController::class, 'promote'])->name('leads.contacts.promote');
            Route::delete('leads/{lead}/contacts/{contact}', [DealContactController::class, 'destroy'])->name('leads.contacts.destroy');
            Route::resource('quotations', QuotationController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

            // Trials
            /*
             * No manual activate. A trial is live when its environment exists,
             * so the actions here are the ones that build it, retry it, or hand
             * it over again.
             */
            Route::post('trials/{trial}/retry', [TrialController::class, 'retry'])->name('trials.retry');
            Route::post('trials/{trial}/resend-handover', [TrialController::class, 'resendHandover'])->name('trials.resend-handover');
            Route::post('trials/{trial}/cancel', [TrialController::class, 'cancel'])->name('trials.cancel');
            Route::post('trials/{trial}/checklist', [TrialController::class, 'checklist'])->name('trials.checklist');
            Route::post('trials/{trial}/extend', [TrialController::class, 'extend'])->name('trials.extend');
            /*
             * The governed path: a rep without approval authority asks here
             * and waits; a manager (or a rep who already holds the approval
             * permission) asking here gets `extend` applied in the same
             * request. `extend` above stays reachable for anyone still
             * calling it directly with `trials.provision`.
             */
            Route::post('trials/{trial}/extension-requests', [TrialController::class, 'requestExtension'])->name('trials.extension-requests.store');
            Route::post('trials/{trial}/extension-requests/{extensionRequest}/approve', [TrialController::class, 'approveExtension'])->name('trials.extension-requests.approve');
            Route::post('trials/{trial}/extension-requests/{extensionRequest}/reject', [TrialController::class, 'rejectExtension'])->name('trials.extension-requests.reject');
            /*
             * Losing an evaluation is its own outcome, not a cancellation.
             * `cancel` is us stopping a trial; this is the customer deciding
             * against us, and it carries the reason and the competitor that
             * make a loss worth anything later.
             */
            Route::post('trials/{trial}/mark-lost', [TrialController::class, 'markLost'])->name('trials.mark-lost');
            // Measure the tenant now, rather than reading last night's snapshot.
            Route::post('trials/{trial}/sync-usage', [TrialController::class, 'syncUsage'])->name('trials.sync-usage');
            Route::post('trials/{trial}/complete', [TrialController::class, 'complete'])->name('trials.complete');
            Route::post('leads/{lead}/create-trial', [TrialController::class, 'storeForLead'])->name('leads.create-trial');
            Route::resource('trials', TrialController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

            // Onboarding — merged into Account 360; legacy routes redirect or forward
            /*
             * The onboarding write actions moved to Customer Success with the
             * rest of the surface; Account 360 posts to
             * `company.customer-success.accounts.onboarding.*`. Only the
             * redirects below remain, so an old link still lands somewhere real.
             */
            Route::redirect('onboarding', '/company/customer-success/accounts')
                ->middleware('can:crm.onboarding.view')
                ->name('onboarding.index');
            Route::get('onboarding/{onboarding}', fn (OnboardingProgress $onboarding) => redirect("/company/customer-success/accounts/{$onboarding->organization_id}?tab=onboarding"))
                ->middleware('can:crm.onboarding.view')
                ->name('onboarding.show');

            /*
             * Loss triage, which is now the Deals "Lost" view.
             *
             * The workspace this replaced listed the same `leads` rows the
             * Deals list holds, under a second set of counts that regularly
             * disagreed with it. The two verbs it carried are kept exactly as
             * they were and are now called from the row they act on, so the
             * reason is recorded in the same place the loss is read.
             */
            Route::redirect('operations', '/company/crm/deals?view=lost')
                ->middleware('can:crm.operations.view')
                ->name('operations.index');
            Route::post('leads/{lead}/triage', [SalesOperationsController::class, 'triage'])->name('leads.triage');
            Route::post('leads/{lead}/winback', [SalesOperationsController::class, 'scheduleWinBack'])->name('leads.winback');

            // Subscriptions & Renewals — merged into Account 360 and Renewals Queue
            Route::redirect('subscriptions', '/company/customer-success/accounts')
                ->middleware('can:crm.subscriptions.view')
                ->name('subscriptions.index');
            Route::redirect('renewals', '/company/customer-success/renewals')
                ->middleware('can:crm.renewals.view')
                ->name('renewals.index');

            // Commercial Documents
            // PaymentController::store existed and was authorized but was never
            // routed, so the "record payment" action on an invoice 404'd.
        });

        // Customer Success (Phase 4)
        Route::prefix('customer-success')->name('company.customer-success.')->group(function () {
            /*
             * The module's own front door.
             *
             * Customer Success opened on the account list sorted by name, which
             * at a hundred and twenty thousand accounts is not a starting point.
             * Same permission as the list it summarises — it shows counts of
             * rows the reader could already see.
             */
            Route::get('/', [CustomerSuccessOverviewController::class, 'index'])
                ->middleware('can:viewAny,App\Models\Organization')
                ->name('overview');

            // Accounts Workspace (Index & Filter)
            Route::get('accounts', [CustomerSuccessAccountController::class, 'index'])
                ->middleware('can:viewAny,App\Models\Organization')
                ->name('accounts.index');

            /*
             * The current worklist, taken off the screen.
             *
             * Reading, so it takes the read permission — an export exposes
             * exactly the rows the list already showed this reader, through the
             * same scoped builder.
             *
             * Declared before `accounts/{organization}`, or the detail route's
             * wildcard would swallow `/accounts/export` and try to resolve an
             * account called "export".
             */
            Route::get('accounts/export', [CustomerSuccessAccountController::class, 'export'])
                ->middleware('can:viewAny,App\Models\Organization')
                ->name('accounts.export');

            /*
             * Reassigning or re-risking a selection. The same two writes Account
             * 360 already offers, so it takes the same write authority.
             */
            Route::post('accounts/bulk', [CustomerSuccessAccountController::class, 'bulkUpdate'])
                ->middleware('can:cs.accounts.manage')
                ->name('accounts.bulk');

            // Account 360 Detail
            Route::get('accounts/{organization}', [CustomerSuccessAccountController::class, 'show'])
                ->middleware('can:view,organization')
                ->name('accounts.show');

            // Update Account Health / Metadata
            Route::put('accounts/{organization}', [CustomerSuccessAccountController::class, 'update'])
                ->middleware('can:update,organization')
                ->name('accounts.update');

            // Onboarding actions on customer account
            /*
             * The customer's structure, managed where the customer lives.
             *
             * Clinics and branches were previously only creatable from a Blade
             * console that did not know which account a clinic belonged to —
             * which is why three clinics carry no organisation. These hang off
             * the account and authorise against it, so that cannot recur.
             */
            Route::post('accounts/{organization}/clinics', [AccountStructureController::class, 'storeClinic'])
                ->middleware('can:update,organization')
                ->name('accounts.clinics.store');

            Route::put('accounts/{organization}/clinics/{clinic}', [AccountStructureController::class, 'updateClinic'])
                ->middleware('can:update,organization')
                ->name('accounts.clinics.update');

            Route::post('accounts/{organization}/clinics/{clinic}/branches', [AccountStructureController::class, 'storeBranch'])
                ->middleware('can:update,organization')
                ->name('accounts.clinics.branches.store');

            Route::put('accounts/{organization}/clinics/{clinic}/branches/{branch}', [AccountStructureController::class, 'updateBranch'])
                ->middleware('can:update,organization')
                ->name('accounts.clinics.branches.update');
            Route::post('accounts/{organization}/onboarding/complete-step', [CustomerSuccessAccountController::class, 'completeOnboardingStep'])
                ->middleware('can:update,organization')
                ->name('accounts.onboarding.complete-step');

            Route::post('accounts/{organization}/onboarding/resend-invite', [CustomerSuccessAccountController::class, 'resendOnboardingInvite'])
                ->middleware('can:update,organization')
                ->name('accounts.onboarding.resend-invite');

            Route::post('accounts/{organization}/onboarding/mark-go-live', [CustomerSuccessAccountController::class, 'markGoLive'])
                ->middleware('can:update,organization')
                ->name('accounts.onboarding.mark-go-live');

            // Cross-Account Renewals Queue
            Route::get('renewals', [CustomerSuccessRenewalController::class, 'index'])
                ->middleware('can:cs.renewals.view')
                ->name('renewals.index');

            // Subscriptions Workspace (Canonical Phase 7)
            Route::get('subscriptions', [LifecycleSubscriptionController::class, 'index'])
                ->middleware('can:view-subscriptions')
                ->name('subscriptions.index');

            Route::get('subscriptions/{subscription}', [LifecycleSubscriptionController::class, 'show'])
                ->middleware('can:view-subscriptions')
                ->name('subscriptions.show');

            /*
             * Changing a contract, from the module that owns the customer.
             *
             * Customer Success could read a subscription and could not touch
             * one: every upgrade, seat change, renewal and cancellation was
             * routed into the CRM as a *deal* — the pipeline built for winning
             * strangers — or done by hand in the database.
             *
             * All four take `cs.accounts.manage`, the same authority as any
             * other write against a customer, and each re-checks that the
             * contract belongs to an account this reader may reach.
             */
            Route::post('subscriptions/{subscription}/change-plan', [SubscriptionActionController::class, 'changePlan'])
                ->middleware('can:cs.accounts.manage')
                ->name('subscriptions.change-plan');

            Route::post('subscriptions/{subscription}/entitlements', [SubscriptionActionController::class, 'adjustEntitlements'])
                ->middleware('can:cs.accounts.manage')
                ->name('subscriptions.entitlements');

            Route::post('subscriptions/{subscription}/renew', [SubscriptionActionController::class, 'renew'])
                ->middleware('can:cs.accounts.manage')
                ->name('subscriptions.renew');

            /*
             * Bulk renew takes its ids in the body rather than the URL, so it
             * sits before nothing and binds nothing — the scope re-resolves
             * every posted id against the reader's own book.
             */
            Route::post('subscriptions/bulk-renew', [SubscriptionActionController::class, 'bulkRenew'])
                ->middleware('can:cs.accounts.manage')
                ->name('subscriptions.bulk-renew');

            Route::post('subscriptions/{subscription}/cancel', [SubscriptionActionController::class, 'cancel'])
                ->middleware('can:cs.accounts.manage')
                ->name('subscriptions.cancel');

            // Usage Capacity & Expansion Worklist (Canonical Phase 8)
            Route::get('usage', [LifecycleSubscriptionController::class, 'usage'])
                ->middleware('can:view-usage')
                ->name('usage.index');

            // Provisioning / Lifecycle Workspace (Canonical)
            Route::get('lifecycle', [ProvisioningController::class, 'index'])
                ->middleware('can:view-provisioning')
                ->name('lifecycle.index');
        });
        /*
         * The master console has been retired. Its clinic pages moved into
         * Customer Success Account 360, where a clinic sits under the account
         * that owns it, and its dashboard rendered `Company/Dashboard` with
         * props that page never declared — a 200 with nothing on it. Both login
         * controllers already send a master admin to `company.dashboard`.
         */
    });
};

$clinicRoutes = function () {
    Route::get('/', function () {
        return redirect()->route('clinic.login');
    });
    Route::get('/login', [ClinicLoginController::class, 'create'])->name('clinic.login');
    Route::post('/login', [ClinicLoginController::class, 'store'])->middleware('throttle:clinic-login');
    Route::post('/logout', [ClinicLoginController::class, 'destroy'])->name('clinic.logout');

    // Reached via a signed link before the invitee has any session — must
    // stay outside the authenticated group below. Rate-limited like sign-in:
    // it checks an existing account's password.
    Route::match(['get', 'post'], '/invitations/accept/{token}', [ClinicAdminEmployeeInvitationController::class, 'accept'])
        ->middleware('throttle:clinic-login')
        ->name('clinic.invitation.accept');

    // One-time password links: the primary owner's first password (setup)
    // and "Forgot your password?" (reset). The {token} is the random secret
    // itself, checked by ClinicPasswordTokenService; it carries no user data.
    Route::get('/password/setup/{token}', [ClinicPasswordSetupController::class, 'create'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('clinic.password.setup');
    Route::post('/password/setup/{token}', [ClinicPasswordSetupController::class, 'store'])
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:clinic-password')
        ->name('clinic.password.store');
    Route::get('/password/forgot', [ClinicPasswordResetController::class, 'create'])
        ->name('clinic.password.request');
    Route::post('/password/forgot', [ClinicPasswordResetController::class, 'store'])
        ->middleware('throttle:clinic-password')
        ->name('clinic.password.email');
    Route::get('/password/reset/{token}', [ClinicPasswordResetController::class, 'edit'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('clinic.password.reset');
    Route::post('/password/reset/{token}', [ClinicPasswordResetController::class, 'update'])
        ->where('token', '[A-Za-z0-9]+')
        ->middleware('throttle:clinic-password')
        ->name('clinic.password.update');

    Route::middleware(['clinic_portal', 'clinic_context', 'onboarding_completed', 'subscription_window'])->group(function () {
        Route::get('/dashboard', [ClinicDashboardController::class, 'index'])->name('clinic.dashboard');
        Route::get('/dashboard-redirect', function () {
            return redirect()->route('clinic.dashboard');
        })->name('dashboard');

        // The branch switcher's search: bounded pages of the member's own
        // accessible branches, for clinics with too many to share on a page.
        Route::get('/branches', [BranchSwitchController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('clinic.branches.index');
        Route::post('/branches/switch', [BranchSwitchController::class, 'switch'])->name('clinic.branches.switch');

        // ==========================================
        // RECEPTION COMMAND CENTER (Phase 2)
        // ==========================================
        Route::middleware('clinic_permission:queue.manage')->group(function () {
            Route::prefix('reception')->name('clinic.reception.')->group(function () {
                Route::get('/', [ReceptionController::class, 'index'])->name('index');
                Route::get('/check-in/search', [ReceptionController::class, 'search'])
                    ->middleware('throttle:clinic-patient-lookup')
                    ->name('check-in.search');
                Route::post('/check-in', [ReceptionController::class, 'checkIn'])->name('check-in');
                Route::post('/walk-in', [ReceptionController::class, 'walkIn'])->name('walk-in');
                Route::patch('/queue/{item}/status', [ReceptionController::class, 'updateQueueStatus'])->name('queue.status');
                Route::patch('/queue/{item}/priority', [ReceptionController::class, 'updatePriority'])->name('queue.priority');
            });
            Route::get('/reception', [ReceptionController::class, 'index'])->name('clinic.reception');
            Route::post('/queue/call-next', [ReceptionController::class, 'callNext'])->name('clinic.queue.call-next');
        });

        // ==========================================
        // PATIENTS & PATIENT 360 (Phase 2)
        // ==========================================
        // Plan layer: the module must be in the clinic's plan. Per-user access
        // is checked by `PatientPolicy` inside the controllers.
        Route::prefix('patients')->name('clinic.patients.')->middleware('clinic_entitled:patients')->group(function () {
            Route::get('/', [ClinicPatientController::class, 'index'])->name('index');
            Route::get('/create', [ClinicPatientController::class, 'create'])->name('create');
            // Duplicate-check is a step of registering a patient and returns
            // matching patients, so it needs the permission to register one.
            Route::post('/check-duplicate', [ClinicPatientController::class, 'checkDuplicate'])
                ->middleware(['clinic_permission:patient.create', 'throttle:clinic-patient-lookup'])
                ->name('check-duplicate');
            Route::post('/', [ClinicPatientController::class, 'store'])->name('store');
            Route::get('/{patient}', [Patient360Controller::class, 'show'])->name('show');
            Route::get('/{patient}/edit', [ClinicPatientController::class, 'edit'])->name('edit');
            Route::put('/{patient}', [ClinicPatientController::class, 'update'])->name('update');
            Route::post('/{patient}/transfer', [ClinicPatientController::class, 'transfer'])->name('transfer');
            Route::get('/{patient}/timeline', [Patient360Controller::class, 'timeline'])->name('timeline');
            Route::get('/{patient}/billing', [Patient360Controller::class, 'billing'])->name('billing');
            Route::get('/{patient}/clinical', [Patient360Controller::class, 'clinical'])->name('clinical');
            // Uploading needs its own permission. Being able to view a patient
            // is not enough to add files to their record.
            Route::post('/{patient}/documents', [Patient360Controller::class, 'uploadDocument'])
                ->middleware('clinic_permission:documents.manage')
                ->name('documents.store');
            Route::get('/{patient}/documents/{document}', [Patient360Controller::class, 'downloadDocument'])
                ->whereNumber('document')
                ->name('documents.download');
        });
        Route::get('/search/patients', [ClinicPatientController::class, 'search'])
            ->middleware(['clinic_entitled:patients', 'clinic_permission:patient.search', 'throttle:clinic-patient-lookup'])
            ->name('clinic.patients.search');

        // ==========================================
        // APPOINTMENTS (Phase 2)
        // ==========================================
        // These routes had no permission check at the route or in the
        // controller. The nav hid Appointments from users without
        // `appointment.view`, but any clinic user could still open, create or
        // re-status appointments directly. Each action now needs its own
        // permission, on top of the plan layer.
        Route::prefix('appointments')->name('clinic.appointments.')->middleware('clinic_entitled:appointments')->group(function () {
            Route::get('/', [ClinicAppointmentController::class, 'index'])
                ->middleware('clinic_permission:appointment.view')
                ->name('index');
            Route::post('/', [ClinicAppointmentController::class, 'store'])
                ->middleware('clinic_permission:appointment.create')
                ->name('store');
            Route::patch('/{appointment}/status', [ClinicAppointmentController::class, 'updateStatus'])
                ->middleware('clinic_permission:appointment.manage')
                ->name('status');
        });

        // ==========================================
        // VISITS (Phase 2)
        // ==========================================
        Route::prefix('visits')->name('clinic.visits.')->group(function () {
            Route::get('/', [ClinicVisitController::class, 'index'])
                ->middleware('clinic_permission:visit.view')
                ->name('index');
            Route::post('/', [ClinicVisitController::class, 'store'])
                ->middleware('clinic_permission:visit.create')
                ->name('store');
        });

        // ==========================================
        // ONBOARDING WIZARD
        // ==========================================
        Route::prefix('onboarding')->name('clinic.onboarding.')->group(function () {
            // Anyone in the clinic can see where setup stands; only a clinic
            // admin can tick steps off or finish it.
            Route::get('/', [ClinicOnboardingController::class, 'index'])->name('index');
            Route::post('/', [ClinicOnboardingController::class, 'update'])
                ->middleware('clinic_permission:settings.manage')
                ->name('update');
            Route::post('/complete', [ClinicOnboardingController::class, 'complete'])
                ->middleware('clinic_permission:settings.manage')
                ->name('complete');
        });

        // ==========================================
        // CLINIC ADMIN (staff, invitations, infrastructure)
        // ==========================================
        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('clinic_permission:staff.manage')->group(function () {
                Route::resource('employees', ClinicAdminEmployeeController::class)->except('show');

                Route::prefix('invitations')->name('invitations.')->group(function () {
                    Route::post('/', [ClinicAdminEmployeeInvitationController::class, 'store'])->name('store');
                    Route::post('/{invitation}/revoke', [ClinicAdminEmployeeInvitationController::class, 'revoke'])->name('revoke');
                });
            });

            // No separate department/room permission exists: both are branch
            // infrastructure, managed by whoever manages branches.
            Route::middleware('clinic_permission:branch.manage')->group(function () {
                Route::resource('branches', ClinicAdminBranchController::class)->only(['index', 'store', 'update', 'destroy']);
                Route::resource('departments', ClinicAdminDepartmentController::class)->only(['index', 'store', 'update', 'destroy']);
                Route::resource('rooms', ClinicAdminRoomController::class)->only(['index', 'store', 'update', 'destroy']);
            });

            Route::middleware('clinic_permission:settings.manage')->group(function () {
                Route::get('settings', [ClinicAdminSettingsController::class, 'index'])->name('settings.index');
                Route::put('settings', [ClinicAdminSettingsController::class, 'update'])->name('settings.update');
                Route::put('settings/patient-fields', [ClinicAdminSettingsController::class, 'updatePatientFields'])->name('settings.patient-fields');
                Route::put('settings/patient-flow', [ClinicAdminSettingsController::class, 'updatePatientFlow'])->name('settings.patient-flow');
                Route::put('settings/case-number', [ClinicAdminSettingsController::class, 'updateCaseNumber'])->name('settings.case-number');
            });
        });
    });
};

// ==========================================
// APPLY ROUTING ARCHITECTURE
// ==========================================

if (config('app.routing_mode') === 'path') {
    Route::prefix('company')->group($companyRoutes);
    Route::prefix('clinic')->group($clinicRoutes);
} else {
    Route::domain('admin.'.$baseDomain)->group($companyRoutes);
    Route::domain('{clinic}.'.$baseDomain)->group($clinicRoutes);
}

require __DIR__.'/auth.php';

Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Web Views
    Route::get('/notifications', [NotificationViewController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/preferences', [NotificationViewController::class, 'preferences'])->name('notifications.preferences.show');

    // API endpoints (AJAX)
    // Rate limits per signed-in user, per endpoint group (docs/security/rate-limits.md).
    Route::prefix('api/notifications')->name('api.notifications.')->middleware('throttle:api-notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unreadCount');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('markAllAsRead');
        Route::post('/bulk', [NotificationController::class, 'bulkAction'])->name('bulk');
        Route::patch('/{id}/read', [NotificationController::class, 'markAsRead'])->name('read');
        Route::patch('/{id}/archive', [NotificationController::class, 'archive'])->name('archive');

        Route::get('/preferences', [NotificationPreferenceController::class, 'show'])->name('preferences.show');
        Route::post('/preferences', [NotificationPreferenceController::class, 'update'])->name('preferences.update');
    });

    Route::prefix('search')->name('search.')->group(function () {
        Route::get('/results', function () {
            return view('search.results');
        })->name('results');
    });

    Route::prefix('api/search')->name('api.search.')->middleware('throttle:api-global-search')->group(function () {
        Route::get('/', [GlobalSearchController::class, 'search'])->name('index');
    });

    // Geo Location Intelligence APIs (Platform Master Data)
    Route::prefix('api/v1/geo')->name('api.v1.geo.')->middleware('throttle:api-geo')->group(function () {
        Route::get('/pincodes/{pincode}', [GeoLocationController::class, 'pincode'])->name('pincode');
        Route::get('/pincode/{pincode}', [GeoLocationController::class, 'pincode'])->name('pincode.alias');
        Route::get('/countries', [GeoLocationController::class, 'countries'])->name('countries');
        Route::get('/states', [GeoLocationController::class, 'states'])->name('states');
        Route::get('/districts', [GeoLocationController::class, 'districts'])->name('districts');
        Route::get('/cities', [GeoLocationController::class, 'cities'])->name('cities');
        Route::get('/areas', [GeoLocationController::class, 'areas'])->name('areas');
        Route::get('/pincodes', [GeoLocationController::class, 'pincodes'])->name('pincodes');
        Route::get('/search', [GeoLocationController::class, 'search'])->name('search');
        Route::post('/validate', [GeoLocationController::class, 'validateLocation'])->name('validate');
    });
});
