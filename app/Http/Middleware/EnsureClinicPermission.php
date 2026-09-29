<?php

namespace App\Http\Middleware;

use App\ClinicPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `clinic_permission:settings.manage` — refuses the request unless the user
 * holds that clinic permission in the active clinic/branch (owners and
 * master admins always pass, see `User::hasClinicPermission()`).
 */
class EnsureClinicPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(
            $request->user()?->hasClinicPermission(ClinicPermission::from($permission)) ?? false,
            403,
            'You do not have permission to do this.'
        );

        return $next($request);
    }
}
