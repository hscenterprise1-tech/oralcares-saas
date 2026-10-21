<?php

namespace App\Http\Middleware;

use App\Support\ClinicAccess\ClinicAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `clinic_module:queue` — refuses the request unless the clinic has that
 * separately sold module on (a company override, else the plan or an add-on,
 * else the module's default: `ClinicModules`). The same answer hides the
 * module's links and actions through `ClinicAccess`.
 */
class EnsureClinicModule
{
    public function __construct(protected ClinicAccess $access) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless($this->access->moduleEnabled($module), 403, 'This clinic does not have that module.');

        return $next($request);
    }
}
