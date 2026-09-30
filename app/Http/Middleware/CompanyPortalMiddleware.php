<?php

namespace App\Http\Middleware;

use App\UserType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CompanyPortalMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Ensure we are on the 'admin' subdomain (in production / domain mode)
        if (config('app.routing_mode') === 'domain') {
            $host = $request->getHost();
            $adminDomain = 'admin.'.parse_url(config('app.url'), PHP_URL_HOST);

            // Skip host check on localhost if testing directly, but enforce in production
            if (app()->environment('production') && $host !== $adminDomain) {
                abort(404);
            }
        }

        // 2. Ensure user is authenticated
        if (! Auth::check()) {
            return redirect()->route('company.login');
        }

        // 3. Ensure user has a valid Company Role (or user type)
        $user = Auth::user();
        if (! $user || ($user->user_type !== UserType::MasterAdmin && $user->user_type !== UserType::CompanyUser)) {
            abort(403, 'Unauthorized action. Company portal access required.');
        }

        return $next($request);
    }
}
