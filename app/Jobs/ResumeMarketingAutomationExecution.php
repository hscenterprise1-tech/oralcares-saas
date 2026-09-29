<?php

namespace App\Jobs;

use App\Models\MarketingAutomationExecution;
use App\Models\MarketingAutomationExecutionStep;
use App\Services\Marketing\MarketingAutomationExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ResumeMarketingAutomationExecution implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $executionId) {}

    public function handle(MarketingAutomationExecutionService $executionService): void
    {
        $execution = MarketingAutomationExecution::find($this->executionId);
        if (! $execution || $execution->status !== 'waiting') {
            return;
        }

        // Same reasoning as ProcessMarketingAutomationExecution: runExecution()
        // lets an unexpected Throwable propagate so this job's own tries can
        // retry a transient failure on a resumed (post-delay) step, rather
        // than the execution silently sitting in 'running' forever with
        // nothing left watching it.
        $executionService->runExecution($execution);
    }

    /**
     * Mirrors ProcessMarketingAutomationExecution::failed() — same terminal
     * marking, reached only once this job's own retries are exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $execution = MarketingAutomationExecution::find($this->executionId);
        if (! $execution || in_array($execution->status, ['completed', 'stopped', 'failed', 'cancelled'], true)) {
            return;
        }

        $message = $exception?->getMessage() ?? 'Automation execution failed after retries.';

        $execution->update([
            'status' => 'failed',
            'last_error' => $message,
        ]);

        if ($execution->current_step_id) {
            MarketingAutomationExecutionStep::where('execution_id', $execution->id)
                ->where('automation_step_id', $execution->current_step_id)
                ->update(['status' => 'failed', 'error_message' => $message]);
        }
    }
}
