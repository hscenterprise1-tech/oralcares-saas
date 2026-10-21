<?php

namespace App\Http\Middleware;

use App\Models\OnboardingProgress;
use App\Services\ClinicContext;
use App\Services\Onboarding\OnboardingGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingCompleted
{
    public function __construct(protected ClinicContext $clinicContext, protected OnboardingGate $gate) {}

    /**
     * Keep people with unfinished onboarding on the onboarding pages, and
     * send everyone else away from them.
     *
     * Clinics provisioned through CRM are governed by the onboarding engine:
     * the clinic's own setup first, then each invited staff member's profile
     * setup. Older clinics use the legacy checklist; clinics with neither
     * pass straight through.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $clinic = $this->clinicContext->clinic();

        if (! $user) {
            return $next($request);
        }

        $onOnboarding = $request->routeIs('clinic.onboarding.*');

        if ($clinic && $this->gate->governs($clinic)) {
            $pending = $this->gate->pendingFor($user, $clinic, $this->clinicContext->currentEmployee());

            if ($pending) {
                return $onOnboarding ? $next($request) : redirect()->route('clinic.onboarding.index');
            }

            if ($onOnboarding && ! $request->routeIs('clinic.onboarding.done')) {
                // A late auto-save after finishing gets an answer, not a
                // redirect the browser would replay with the same method.
                return $request->expectsJson() && ! $request->header('X-Inertia')
                    ? response()->json(['message' => 'Setup is already complete.'], 409)
                    : redirect()->route('dashboard');
            }

            // The completion page is shown once, after the last step.
            return $next($request);
        }

        $progress = OnboardingProgress::forClinicUser($user, $clinic);

        if ($progress && $progress->status !== 'completed') {
            return $onOnboarding ? $next($request) : redirect()->route('clinic.onboarding.index');
        }

        return $onOnboarding ? redirect()->route('dashboard') : $next($request);
    }
}
