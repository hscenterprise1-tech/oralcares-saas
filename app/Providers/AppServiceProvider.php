<?php

namespace App\Providers;

use App\Contracts\MalwareScannerInterface;
use App\Contracts\VirusScannerInterface;
use App\Jobs\SendCampaignMessageJob;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\CompanyTeam;
use App\Models\Contact;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppConversation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\Observers\CommercialDocumentObserver;
use App\Observers\CRM\DemoObserver;
use App\Observers\CRM\LeadObserver;
use App\Observers\CRM\TrialObserver;
use App\Observers\Marketing\MarketingContactObserver;
use App\Observers\Marketing\MarketingWhatsAppConversationObserver;
use App\Observers\OrganizationObserver;
use App\Observers\PaymentObserver;
use App\Observers\Security\ClinicalAuditObserver;
use App\Observers\Security\StaffIdentityAuditObserver;
use App\Observers\TaskObserver;
use App\Policies\CompanyDepartmentPolicy;
use App\Policies\CompanyTeamPolicy;
use App\Policies\MarketingWhatsAppConversationPolicy;
use App\Policies\QuotationPolicy;
use App\Scopes\TenantScope;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\DatabaseRestorer;
use App\Services\Backup\MysqlDumper;
use App\Services\Backup\MysqlRestorer;
use App\Services\ClinicContext;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Security\Malware\ClamAvScanner;
use App\Services\Security\Malware\FakeMalwareScanner;
use App\Services\Security\Malware\MalwareScannerVirusAdapter;
use App\Services\Security\Malware\UnconfiguredScanner;
use App\Services\Security\SecurityAudit;
use App\Shared\Search\Observers\SearchIndexObserver;
use App\Support\ClinicPortalErrors;
use App\Support\CrmPermissions;
use App\Support\CrmScope;
use App\UserType;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\ExceptionResponse;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ClinicContext::class);

        // Database backups: mysqldump / mysql client, swappable (see BackupManager).
        $this->app->bind(DatabaseDumper::class, fn () => new MysqlDumper(config('database.default'), config('security.backup.mysqldump_binary')));
        $this->app->bind(DatabaseRestorer::class, fn () => new MysqlRestorer(config('security.backup.mysql_binary')));

        /*
         * One malware scanner for the whole app. `clamav` is the real one. In
         * production, anything else becomes `UnconfiguredScanner`, which fails
         * every scan, so uploads stop instead of being admitted unscanned.
         * `DummyVirusScanner` (always "clean") is gone. The older
         * `VirusScannerInterface` used by FileEngine is served by an adapter
         * over the same scanner.
         */
        $this->app->singleton(MalwareScannerInterface::class, function ($app) {
            if (config('security.malware.driver') === 'clamav') {
                $clam = config('security.malware.clamav');

                return new ClamAvScanner($clam['host'], $clam['port'], $clam['connect_timeout'], $clam['read_timeout'], $clam['max_bytes']);
            }

            if ($app->isProduction()) {
                logger()->critical('MALWARE_SCANNER is not a real scanner in production; every upload will be refused until it is.');

                return new UnconfiguredScanner;
            }

            return new FakeMalwareScanner;
        });
        $this->app->bind(VirusScannerInterface::class, MalwareScannerVirusAdapter::class);
    }

    public function boot(): void
    {
        /*
         * Production never shows debug output, even if APP_DEBUG=true was left
         * in its environment: a stack trace on a patient page would expose
         * query text, file paths and configuration to whoever triggered it.
         * The misconfiguration is logged loudly and reported by
         * `php artisan security:production-check`, which a deploy should run.
         */
        if ($this->app->isProduction() && config('app.debug')) {
            config(['app.debug' => false]);
            logger()->critical('APP_DEBUG was true in production and has been forced off. Fix the environment.');
        }

        // The Clinic Portal's own error pages and session-expiry redirect.
        Inertia::handleExceptionsUsing(fn (ExceptionResponse $response) => ClinicPortalErrors::handle($response));

        /*
         * Where an already-signed-in visitor goes when they open a sign-in or
         * password page.
         *
         * Laravel's default is the `dashboard` route, which in this application
         * is the clinic one — so a signed-in Company user was sent into the
         * clinic portal and refused by its middleware.
         */
        RedirectIfAuthenticated::redirectUsing(function (Request $request): string {
            $user = $request->user();

            return $user && ($user->isMasterAdmin() || $user->user_type === UserType::CompanyUser)
                ? route('company.dashboard')
                : route('dashboard');
        });

        Relation::morphMap([
            'lead' => Lead::class,
            'clinic' => Clinic::class,
            'organization' => Organization::class,
            'contact' => Contact::class,
        ]);

        Route::model('quotation', CompanyQuotation::class);
        Gate::policy(CompanyQuotation::class, QuotationPolicy::class);
        Gate::policy(MarketingWhatsAppConversation::class, MarketingWhatsAppConversationPolicy::class);

        /*
         * The People hierarchy. Both policies were written, both name real
         * seeded permissions — `employees.view`, `departments.manage`,
         * `teams.manage`, held by CEO, Operations Manager, HR Manager and
         * System Administrator — and neither had ever been registered, so
         * nothing could authorise against them.
         */
        Gate::policy(CompanyDepartment::class, CompanyDepartmentPolicy::class);
        Gate::policy(CompanyTeam::class, CompanyTeamPolicy::class);

        Gate::before(function (User $user, string $ability) {
            if ($user->isMasterAdmin()) {
                return true;
            }

            return null; // Let Spatie handle the rest
        });

        // CRM surfaces that have no dedicated Eloquent policy. These resolve
        // through App\Support\CrmPermissions so they honour the same canonical
        // names and legacy aliases as the CRM policies.
        // Catalog *administration* is a management surface. Reps still receive
        // the package list they need for quotations through the lead payload,
        // so restricting this screen does not block their workflow.
        Gate::define('crm.packages.view', fn (User $user) => CrmPermissions::check($user, 'view-plans'));
        Gate::define('crm.operations.view', fn (User $user) => CrmPermissions::check($user, 'view-operations'));
        Gate::define('crm.packages.manage', fn (User $user) => CrmPermissions::checkAny($user, 'view-plans', 'manage-settings'));
        Gate::define('crm.teams.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-crm', 'view-leads'));
        Gate::define('crm.teams.manage', fn (User $user) => CrmPermissions::checkAny($user, 'manage-employees', 'manage-settings'));
        Gate::define('crm.onboarding.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-onboarding', 'view-pending-onboarding'));
        Gate::define('crm.subscriptions.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-subscriptions', 'view-crm'));
        Gate::define('crm.renewals.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-renewals', 'view-crm'));
        Gate::define('crm.dashboard.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-crm', 'view-leads'));

        // Customer Success gates
        Gate::define('cs.accounts.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-organizations', 'view-crm'));
        Gate::define('cs.accounts.manage', fn (User $user) => CrmPermissions::check($user, 'manage-organizations'));
        Gate::define('cs.renewals.view', fn (User $user) => CrmPermissions::checkAny($user, 'view-renewals', 'view-crm'));

        // Team-wide reporting. The Overview itself is open to any CRM user —
        // a rep sees their own numbers there — but the sections that expose
        // other people's performance are management-only.
        Gate::define('crm.overview.team', fn (User $user) => CrmScope::isManager($user));
        Gate::define('crm.quotations.approve', fn (User $user) => CrmPermissions::checkAny($user, 'view-operations', 'view-analytics', 'manage-employees', 'manage-settings'));
        /*
         * The same "is this person a manager" set `crm.quotations.approve`
         * uses — a separate gate rather than reusing that name because
         * quotation and trial-extension approval are different authorities
         * that happen to be granted to the same people today. If they ever
         * diverge, only one of them should have to move.
         */
        Gate::define('crm.trials.approve-extension', fn (User $user) => CrmPermissions::checkAny($user, 'view-operations', 'view-analytics', 'manage-employees', 'manage-settings'));

        // Proactive per-account WhatsApp send throttling — read from the
        // tenant's own `hourly_dispatch_rate` setting (Marketing Settings),
        // ahead of dispatch rather than only reacting to a 429 after the
        // fact. Keyed by WhatsApp account, not organization, since capacity
        // is a property of the sending number.
        RateLimiter::for('campaign-dispatch', function (SendCampaignMessageJob $job) {
            $recipient = MarketingCampaignRecipient::find($job->recipientId);
            $campaign = $recipient?->campaign;
            $accountId = $campaign?->whatsapp_account_id;
            $rate = ($campaign && $campaign->organization_id)
                ? app(MarketingSettingsService::class)->getSettings($campaign->organization_id)->hourly_dispatch_rate
                : 500;

            return Limit::perHour($rate ?: 500)->by('whatsapp-account:'.($accountId ?? 'unknown'));
        });

        /*
         * Per-address ceilings on the clinic's public auth forms. These sit
         * on top of the per-email lockout in `ClinicLoginRequest`, which one
         * machine could otherwise sidestep by trying a different email on
         * every attempt. The ceilings are generous enough for a reception
         * desk full of staff behind one office IP.
         */
        RateLimiter::for('clinic-login', fn (Request $request) => Limit::perMinute(30)->by('clinic-login:'.$request->ip()));
        RateLimiter::for('clinic-password', fn (Request $request) => Limit::perMinute(10)->by('clinic-password:'.$request->ip()));

        /*
         * Signed-in API and lookup endpoints, one bucket per user per group.
         * Keying on the user means a busy reception desk in one clinic can
         * never throttle anyone in another, and one user's heavy searching
         * does not use up their own notification polling. Numbers live in
         * config/security.php and docs/security/rate-limits.md.
         */
        $perUser = fn (string $group, string $configKey) => fn (Request $request) => Limit::perMinute((int) config("security.rate_limits.{$configKey}"))
            ->by($group.':'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        RateLimiter::for('clinic-patient-lookup', $perUser('clinic-patient-lookup', 'patient_lookup'));
        RateLimiter::for('api-global-search', $perUser('api-global-search', 'global_search'));
        RateLimiter::for('api-notifications', $perUser('api-notifications', 'notifications'));
        RateLimiter::for('api-geo', $perUser('api-geo', 'geo'));

        /*
         * Tell `TenantScope` when a queued job is running, so clinic-scoped
         * data read with no tenant context fails instead of returning every
         * clinic's rows. Cleared after the job, however it ends. See
         * docs/security/tenant-context.md.
         */
        Queue::before(fn () => $this->app->instance(TenantScope::IN_QUEUE_JOB, true));
        Queue::after(fn () => $this->app->forgetInstance(TenantScope::IN_QUEUE_JOB));
        Queue::exceptionOccurred(fn () => $this->app->forgetInstance(TenantScope::IN_QUEUE_JOB));

        // Clinical and financial record changes into the security trail.
        foreach (array_keys(ClinicalAuditObserver::SUBJECTS) as $auditedModel) {
            $auditedModel::observe(ClinicalAuditObserver::class);
        }

        foreach (array_keys(StaffIdentityAuditObserver::SUBJECTS) as $staffModel) {
            $staffModel::observe(StaffIdentityAuditObserver::class);
        }

        /*
         * A clinic or a membership changing standing (active, inactive,
         * suspended) decides who can reach patient data, so every change goes
         * into the security trail, whichever screen or job made it.
         */
        Clinic::updated(function (Clinic $clinic): void {
            if ($clinic->wasChanged('status')) {
                app(SecurityAudit::class)->record('clinic.status_changed', $clinic, [
                    'from' => $clinic->getOriginal('status')?->value ?? $clinic->getOriginal('status'),
                    'to' => $clinic->status?->value ?? $clinic->status,
                ], ['clinic_id' => $clinic->id]);
            }
        });
        ClinicMembership::updated(function (ClinicMembership $membership): void {
            if ($membership->wasChanged('status')) {
                app(SecurityAudit::class)->record('membership.status_changed', $membership, [
                    'member_user_id' => $membership->user_id,
                    'from' => $membership->getOriginal('status')?->value ?? $membership->getOriginal('status'),
                    'to' => $membership->status?->value ?? $membership->status,
                ], ['clinic_id' => $membership->clinic_id]);
            }
        });

        Lead::observe(LeadObserver::class);
        Demo::observe(DemoObserver::class);
        Trial::observe(TrialObserver::class);
        CommercialDocument::observe(CommercialDocumentObserver::class);
        Payment::observe(PaymentObserver::class);
        Task::observe(TaskObserver::class);
        MarketingContact::observe(MarketingContactObserver::class);
        MarketingWhatsAppConversation::observe(MarketingWhatsAppConversationObserver::class);

        /*
         * Every customer account gets its Customer Success projection row the
         * moment it exists, so the list query can INNER JOIN that table. See
         * OrganizationObserver for the two measurements that made it matter.
         */
        Organization::observe(OrganizationObserver::class);

        // Auto-Index Models in Search Documents Index
        Lead::observe(SearchIndexObserver::class);
        Organization::observe(SearchIndexObserver::class);
        Clinic::observe(SearchIndexObserver::class);
        CommercialInvoice::observe(SearchIndexObserver::class);
        Task::observe(SearchIndexObserver::class);
        CommercialDocument::observe(SearchIndexObserver::class);
        CompanyEmployee::observe(SearchIndexObserver::class);
        User::observe(SearchIndexObserver::class);
    }
}
