<?php

namespace App\Http\Middleware;

use App\Exceptions\TenantContextMissingException;
use App\Services\ClinicContext;
use App\UserType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireClinicContext
{
    public function __construct(protected ClinicContext $clinicContext) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws TenantContextMissingException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->user_type === UserType::ClinicUser) {
            if (! $this->clinicContext->hasClinic()) {
                throw new TenantContextMissingException;
            }
        }

        return $next($request);
    }
}
