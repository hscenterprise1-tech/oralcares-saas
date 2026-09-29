<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id: the one a trusted proxy sent in
 * `X-Request-Id` if it is well-formed, otherwise a new UUID. It is added to
 * every log line and every security audit entry written during the request,
 * and returned in the response header, so one incident can be followed
 * across the load balancer, application logs and the audit trail.
 */
class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $requestId = preg_match('/^[A-Za-z0-9\-_.]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);
        Log::withContext(['request_id' => $requestId]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
