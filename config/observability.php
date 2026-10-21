<?php

/*
|--------------------------------------------------------------------------
| Observability
|--------------------------------------------------------------------------
|
| Thresholds and drivers for the platform's own telemetry: slow queries,
| per-request database time, slow requests, queue backlog and metrics.
| Nothing here carries query bindings or request payloads — only shapes,
| timings and identifiers — so no patient data reaches a log or metric.
|
*/

return [

    /*
     * A single query slower than this is logged to the `performance`
     * channel (SQL text without bindings). 0 disables.
     */
    'slow_query_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 500),

    /*
     * A request or job whose queries add up to more than this is logged once
     * with its route/job name. 0 disables.
     */
    'request_query_budget_ms' => (int) env('OBSERVABILITY_REQUEST_QUERY_BUDGET_MS', 2000),

    /*
     * A web request slower than this end to end is logged. 0 disables.
     */
    'slow_request_ms' => (int) env('OBSERVABILITY_SLOW_REQUEST_MS', 3000),

    /*
     * Where `App\Support\Metrics` sends counters and timings: `log` writes
     * them to the `metrics` channel (JSON, shippable to any collector),
     * `null` drops them.
     */
    'metrics_driver' => env('METRICS_DRIVER', 'log'),

    /*
     * `queue:monitor` thresholds (jobs waiting) per named queue; crossing one
     * logs a warning and a metric. Long queues have higher ceilings.
     */
    'queue_backlog' => [
        'critical' => (int) env('QUEUE_BACKLOG_CRITICAL', 50),
        'default' => (int) env('QUEUE_BACKLOG_DEFAULT', 500),
        'notifications' => (int) env('QUEUE_BACKLOG_NOTIFICATIONS', 2000),
        'provisioning' => (int) env('QUEUE_BACKLOG_PROVISIONING', 100),
        'search' => (int) env('QUEUE_BACKLOG_SEARCH', 5000),
    ],

    /*
     * Failed jobs are kept this long for inspection and `queue:retry`, then
     * pruned so the table does not grow without bound.
     */
    'failed_job_retention_hours' => (int) env('FAILED_JOB_RETENTION_HOURS', 720),

];
