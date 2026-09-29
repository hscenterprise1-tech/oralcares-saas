<?php

namespace App\Http\Middleware;

use App\Models\OnboardingProgress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureOnboardingCompleted
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        if ($user->organization_id) {
            $progress = OnboardingProgress::where('organization_id', $user->organization_id)->first();

            if ($progress && $progress->status !== 'completed') {
                if ($request->routeIs('clinic.onboarding.*')) {
                    return $next($request);
                }

                return redirect()->route('clinic.onboarding.index');
            } else {
                if ($request->routeIs('clinic.onboarding.*')) {
                    return redirect()->route('dashboard');
                }
            }
        }

        return $next($request);
    }
}
