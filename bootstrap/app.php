<?php

use App\Http\Middleware\EnsureUserIsMasterAdmin;
use App\Http\Middleware\RequireClinicContext;
use App\Http\Middleware\RequireFeatureEntitlement;
use App\Http\Middleware\ResolveClinicContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ResolveClinicContext::class,
        ]);
        $middleware->alias([
            'master_admin' => EnsureUserIsMasterAdmin::class,
            'clinic_context' => RequireClinicContext::class,
            'entitlement' => RequireFeatureEntitlement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
