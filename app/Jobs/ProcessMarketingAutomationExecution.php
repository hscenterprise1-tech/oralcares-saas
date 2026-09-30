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

class ProcessMarketingAutomationExecution implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $executionId) {}

    public function handle(MarketingAutomationExecutionService $executionService): void
    {
        $execution = MarketingAutomationExecution::find($this->executionId);
        if (! $execution || in_array($execution->status, ['completed', 'stopped', 'failed', 'cancelled'], true)) {
            return;
        }

        // Deliberately not caught: runExecution() lets an unexpected
        // Throwable from a step propagate rather than swallowing it, so
        // this job's own tries/backoff actually gets a chance to retry a
        // transient failure. Only once every attempt has failed does the
        // queue give up and call failed() below.
        $executionService->runExecution($execution);
    }

    /**
     * Called once by the queue after every retry attempt has failed —
     * marks the execution (and the step it was on) failed, and persists
     * the final error. Never reached for a business-rule failure, since
     * those are handled — and made terminal immediately — as an ordinary
     * return value inside runExecution(), not as a thrown exception.
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
