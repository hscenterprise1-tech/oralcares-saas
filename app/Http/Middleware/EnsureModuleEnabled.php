<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\ClinicContext;
use App\Services\Core\ModuleEngine;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $clinicContext = app(ClinicContext::class);
        $clinic = $clinicContext->clinic();

        if (! $clinic || ! $clinic->organization_id) {
            abort(403, 'Organization context required for module access.');
        }

        $organization = Organization::find($clinic->organization_id);

        if (! $organization) {
            abort(403, 'Organization not found.');
        }

        $engine = app(ModuleEngine::class);

        if (! $engine->isEnabled($organization, $module)) {
            abort(403, "The '{$module}' module is not enabled for your organization.");
        }

        return $next($request);
    }
}
