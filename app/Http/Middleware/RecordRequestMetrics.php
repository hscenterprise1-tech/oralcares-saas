<?php

namespace App\Http\Middleware;

use App\Support\Metrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Times every web request by route name and status, and logs the ones slower
 * than `observability.slow_request_ms`. Records the route's name — never its
 * URL, which can carry identifiers and tokens.
 */
class RecordRequestMetrics
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $response = $next($request);

        $milliseconds = (microtime(true) - $started) * 1000;
        $route = $request->route()?->getName() ?? 'unnamed';
        $tags = ['route' => $route, 'method' => $request->method(), 'status' => $response->getStatusCode()];

        Metrics::timing('http.request.duration', $milliseconds, $tags);

        $slow = (int) config('observability.slow_request_ms');
        if ($slow > 0 && $milliseconds >= $slow) {
            Log::channel('performance')->warning('slow_request', [...$tags, 'ms' => round($milliseconds, 2)]);
        }

        return $response;
    }
}
