<?php

namespace App\Http\Middleware;

use App\Models\OnboardingProgress;
use App\Services\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingCompleted
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Keep a clinic with unfinished onboarding on its onboarding page, and send
     * everyone else away from it. Clinics with no onboarding record (provisioned
     * before onboarding existed) pass straight through.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $progress = OnboardingProgress::forClinicUser($user, $this->clinicContext->clinic());
        $onOnboarding = $request->routeIs('clinic.onboarding.*');

        if ($progress && $progress->status !== 'completed') {
            return $onOnboarding ? $next($request) : redirect()->route('clinic.onboarding.index');
        }

        return $onOnboarding ? redirect()->route('dashboard') : $next($request);
    }
}
