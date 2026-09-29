<?php

namespace App\Http\Middleware;

use App\Services\ClinicContext;
use App\UserType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ClinicPortalMiddleware
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Ensure user is authenticated
        if (! Auth::check()) {
            return redirect()->route('clinic.login');
        }

        $user = Auth::user();
        if (! $user || $user->user_type !== UserType::ClinicUser) {
            abort(403, 'Unauthorized action. Clinic portal access required.');
        }

        // 2. In domain mode, the request must be addressed to a real clinic
        // subdomain — not the bare app host or the admin subdomain. Path
        // mode has no subdomain to check; an absent `clinic` input there
        // just means "use the session's own clinic" (see ResolveClinicContext).
        if (config('app.routing_mode') === 'domain' && $this->clinicContext->requestedClinicSlug($request) === null) {
            abort(404);
        }

        // 3. Clinic context (which clinic/branch this user is actually a
        // member of) is resolved upstream by `ResolveClinicContext`, scoped
        // to this same slug — see `ClinicContext::requestedClinicSlug()`.
        // `clinic_context` (RequireClinicContext), next in this route
        // group, rejects the request if that resolution came up empty.

        return $next($request);
    }
}
