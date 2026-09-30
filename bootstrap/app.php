<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ClinicPortalMiddleware;
use App\Http\Middleware\CompanyPortalMiddleware;
use App\Http\Middleware\EnforceSubscriptionWindow;
use App\Http\Middleware\EnsureClinicEntitled;
use App\Http\Middleware\EnsureClinicPermission;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureOnboardingCompleted;
use App\Http\Middleware\EnsureOwnerMode;
use App\Http\Middleware\EnsureUserIsMasterAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireClinicContext;
use App\Http\Middleware\ResolveClinicContext;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentBranch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        ]);

        /*
         * Baseline response headers on every web response (see
         * `SecurityHeaders` for what is and is not sent, and why).
         */
        $middleware->append(SecurityHeaders::class);

        // First, so every log line and audit entry of the request carries it.
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
