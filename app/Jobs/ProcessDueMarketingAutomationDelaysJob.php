<?php

namespace App\Jobs;

use App\Services\Marketing\MarketingAutomationExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resumes automation executions whose delay step has come due.
 *
 * An automation could always add a delay step, but nothing ever polled
 * next_run_at, so every delayed execution sat in 'waiting' forever. Runs
 * every minute — the finest delay unit a workflow can configure — and is
 * idempotent: ResumeMarketingAutomationExecution no-ops for anything not
 * still 'waiting' by the time it runs.
 */
class ProcessDueMarketingAutomationDelaysJob implements ShouldQueue
{
    use Queueable;

    public function handle(MarketingAutomationExecutionService $executionService): void
    {
        $executionService->dispatchDueExecutions();
    }
}
