<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ClinicPortalMiddleware;
use App\Http\Middleware\CompanyPortalMiddleware;
use App\Http\Middleware\EnforceSubscriptionWindow;
use App\Http\Middleware\EnsureClinicEntitled;
use App\Http\Middleware\EnsureClinicModule;
use App\Http\Middleware\EnsureClinicPermission;
use App\Http\Middleware\EnsureClinicWorkspace;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureOnboardingCompleted;
use App\Http\Middleware\EnsureOwnerMode;
use App\Http\Middleware\EnsureUserIsMasterAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordRequestMetrics;
use App\Http\Middleware\RequireClinicContext;
use App\Http\Middleware\ResolveClinicContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentBranch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        /*
         * Readiness probe, outside the `web` group: no session is started, so
         * a load balancer polling it every few seconds never writes session
         * rows or cookies.
         */
        then: function (): void {
            Route::get('/health/ready', HealthController::class)
                ->middleware('throttle:120,1')
                ->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Meta posts WhatsApp webhooks without a CSRF token; each endpoint verifies Meta's signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        /*
         * `HandleInertiaRequests::share()` runs synchronously, before `$next()`
         * — that's when `auth.user.permissions` (`getAllPermissions()`) is
         * computed. With it registered before `SetCurrentBranch`, that read
         * happened under whatever Spatie team id was left over from the
         * previous request, not the branch this one resolved — so a clinic
         * user's own nav items came back empty despite the server granting
         * every one of them. `ClinicContext`/`SetCurrentBranch` must run first.
         */
        $middleware->web(append: [
            ResolveClinicContext::class,
            SetCurrentBranch::class,
            HandleInertiaRequests::class,
        ]);

        /*
         * `append` above places these after the web group's own defaults —
         * including `SubstituteBindings`, which resolves route-model-bound
         * parameters (e.g. `{employee}`). Any `ClinicScoped` model queried
         * during binding runs `TenantScope::apply()`, which reads
         * `ClinicContext::hasClinic()` — false until `ResolveClinicContext`
         * has run, so binding a clinic-scoped model threw
         * `TenantContextMissingException` (403) on every request, unmasked
         * only by the fact that `TenantScope` no-ops in console (tests
         * run in console, so this never surfaced there). These two must
         * run before `SubstituteBindings`, not just before Inertia.
         */
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveClinicContext::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentBranch::class,
        );

        $middleware->alias([
            'master_admin' => EnsureUserIsMasterAdmin::class,
            'clinic_context' => RequireClinicContext::class,
            'company_portal' => CompanyPortalMiddleware::class,
            'clinic_portal' => ClinicPortalMiddleware::class,
            'subscription_window' => EnforceSubscriptionWindow::class,
            'module' => EnsureModuleEnabled::class,
            'onboarding_completed' => EnsureOnboardingCompleted::class,
            'clinic_permission' => EnsureClinicPermission::class,
            'clinic_entitled' => EnsureClinicEntitled::class,
            'clinic_owner_mode' => EnsureOwnerMode::class,
            'clinic_workspace' => EnsureClinicWorkspace::class,
            'clinic_module' => EnsureClinicModule::class,
        ]);

        /*
         * Baseline response headers on every web response (see
         * `SecurityHeaders` for what is and is not sent, and why).
         */
        $middleware->append(SecurityHeaders::class);

        // Request timing by route name (config/observability.php).
        $middleware->append(RecordRequestMetrics::class);

        // First, so every log line and audit entry of the request carries it.
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
