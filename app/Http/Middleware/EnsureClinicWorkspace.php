<?php

namespace App\Http\Middleware;

use App\Support\ClinicAccess\ClinicAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `clinic_workspace:front_desk` (or several, any one passing) — refuses the
 * request unless the user works in that workspace here. The same rule
 * (`ClinicAccess::workspaces()`) decides home routing, navigation and the
 * workspace switcher, so a page hidden from someone is also closed to them.
 */
class EnsureClinicWorkspace
{
    public function __construct(protected ClinicAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$workspaces): Response
    {
        abort_unless(
            array_intersect($workspaces, $this->access->workspaces()) !== [],
            403,
            'This workspace is not part of your role here.'
        );

        return $next($request);
    }
}
