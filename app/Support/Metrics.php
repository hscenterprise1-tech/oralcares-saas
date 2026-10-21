<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Platform counters and timings.
 *
 * One call site for every metric, so the destination can change without
 * touching callers: `log` writes a JSON line to the `metrics` channel (a log
 * collector turns those into time series), `null` drops them. Tags carry
 * identifiers and names only — never patient data, request payloads or query
 * bindings.
 */
class Metrics
{
    /**
     * @param  array<string, scalar|null>  $tags
     */
    public static function increment(string $name, int $by = 1, array $tags = []): void
    {
        static::record('counter', $name, $by, $tags);
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    public static function timing(string $name, float $milliseconds, array $tags = []): void
    {
        static::record('timing', $name, round($milliseconds, 2), $tags);
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    public static function gauge(string $name, int|float $value, array $tags = []): void
    {
        static::record('gauge', $name, $value, $tags);
    }

    /**
     * @param  array<string, scalar|null>  $tags
     */
    private static function record(string $type, string $name, int|float $value, array $tags): void
    {
        if (config('observability.metrics_driver') !== 'log') {
            return;
        }

        Log::channel('metrics')->info($name, ['type' => $type, 'value' => $value, 'tags' => $tags]);
    }
}
