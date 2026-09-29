<?php

namespace App\Http\Middleware;

use App\Services\ClinicContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentBranch
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->clinicContext->hasBranch()) {
            setPermissionsTeamId($this->clinicContext->branch()->id);
        }

        return $next($request);
    }
}
