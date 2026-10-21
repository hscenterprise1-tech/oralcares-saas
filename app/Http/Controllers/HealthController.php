<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Readiness probe for load balancers and orchestrators: can this instance
 * reach everything a request needs — the database, the shared cache, the
 * queue and private object storage?
 *
 * `/up` (Laravel's liveness route) only proves the app boots. This answers
 * "should traffic be sent here". It names each dependency and whether it
 * answered, never an error message, host or credential; failures are logged
 * to the `performance` channel instead.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => fn () => DB::connection()->select('select 1'),
            'cache' => function (): void {
                $key = 'g:health:probe';
                Cache::put($key, 'ok', 10);
                throw_unless(Cache::get($key) === 'ok', new RuntimeException('Cache read-back failed.'));
            },
            'queue' => fn () => Queue::size(config('queue.queues.critical')),
            'storage' => fn () => Storage::disk('private')->put('health/probe.txt', (string) now()->timestamp),
        ];

        $results = [];
        foreach ($checks as $name => $check) {
            try {
                $check();
                $results[$name] = 'ok';
            } catch (Throwable $exception) {
                $results[$name] = 'failed';
                Log::channel('performance')->error('health_check_failed', ['check' => $name, 'exception' => $exception::class]);
            }
        }

        $healthy = ! in_array('failed', $results, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $results,
        ], $healthy ? 200 : 503);
    }
}
