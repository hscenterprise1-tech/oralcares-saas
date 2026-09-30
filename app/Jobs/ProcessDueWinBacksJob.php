<?php

namespace App\Jobs;

use App\Services\LeadWorkspaceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Notifies reps when a scheduled win-back falls due.
 *
 * Sales Operations could always *schedule* a win-back, but nothing acted on the
 * date, so `winback_scheduled_at` was effectively decorative. Runs daily and is
 * idempotent — a lead is notified once, however many times the job runs.
 */
class ProcessDueWinBacksJob implements ShouldQueue
{
    use Queueable;

    public function handle(LeadWorkspaceService $workspaceService): void
    {
        $workspaceService->processDueWinBacks();
    }
}
