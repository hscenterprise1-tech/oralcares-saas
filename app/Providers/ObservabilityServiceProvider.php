<?php

namespace App\Providers;

use App\Support\Metrics;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The platform's own telemetry: slow queries, per-request/per-job database
 * time, job durations and failures, and queue backlog (config/observability.php).
 *
 * SQL is recorded without its bindings and jobs by class name, so neither
 * patient data nor credentials can reach the performance or metrics logs.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /** @var array<string, float> start time per running job id */
    private array $jobStartedAt = [];

    public function boot(): void
    {
        $this->watchSlowQueries();
        $this->watchQueryBudget();
        $this->watchJobs();

        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::channel('performance')->warning('queue_backlog', [
                'connection' => $event->connection,
                'queue' => $event->queue,
                'size' => $event->size,
            ]);
            Metrics::gauge('queue.backlog', $event->size, ['queue' => $event->queue]);
        });
    }

    private function watchSlowQueries(): void
    {
        $threshold = (int) config('observability.slow_query_ms');

        if ($threshold <= 0) {
            return;
        }

        DB::listen(function (QueryExecuted $query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            Log::channel('performance')->warning('slow_query', [
                'ms' => $query->time,
                'connection' => $query->connectionName,
                'sql' => $query->sql,
                'route' => Route::currentRouteName(),
            ]);
            Metrics::increment('db.slow_query', 1, ['connection' => $query->connectionName]);
        });
    }

    private function watchQueryBudget(): void
    {
        $budget = (int) config('observability.request_query_budget_ms');

        if ($budget <= 0) {
            return;
        }

        DB::whenQueryingForLongerThan($budget, function (Connection $connection) use ($budget): void {
            Log::channel('performance')->warning('query_budget_exceeded', [
                'budget_ms' => $budget,
                'total_ms' => round($connection->totalQueryDuration(), 2),
                'connection' => $connection->getName(),
                'route' => Route::currentRouteName(),
            ]);
        });
    }

    private function watchJobs(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->jobStartedAt[$event->job->getJobId()] = microtime(true);

            // Each job gets its own query budget, as a request does.
            foreach (DB::getConnections() as $connection) {
                $connection->resetTotalQueryDuration();
                $connection->allowQueryDurationHandlersToRunAgain();
            }
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            $started = $this->jobStartedAt[$event->job->getJobId()] ?? null;
            unset($this->jobStartedAt[$event->job->getJobId()]);

            if ($started !== null) {
                Metrics::timing('queue.job.duration', (microtime(true) - $started) * 1000, [
                    'job' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(),
                ]);
            }
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            unset($this->jobStartedAt[$event->job->getJobId()]);

            Metrics::increment('queue.job.failed', 1, [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'exception' => $event->exception::class,
            ]);
        });
    }
}
