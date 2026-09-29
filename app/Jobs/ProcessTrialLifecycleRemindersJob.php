<?php

namespace App\Jobs;

use App\Services\CRM\TrialLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Move every trial to where the calendar says it should be.
 *
 * This used to call `checkAndGenerateTrialReminders()`, which asked
 * `end_date->isTomorrow()` and `isPast()`. Those are true on exactly one day, so
 * a run that did not happen — a deploy window, a queue backlog, a server that
 * was down at 08:15 — skipped that milestone permanently, and nobody ever found
 * out that a client had not been warned their trial was ending.
 *
 * {@see TrialLifecycleService::syncAll()} replaces the calendar questions with
 * recorded milestones, so this job is both catch-up and idempotent: run it late
 * and it fires everything it owes, run it twice and the second pass does
 * nothing.
 *
 * That also means the cadence is now a safety margin rather than a correctness
 * requirement. It runs daily because the client-facing messages read as daily
 * ones; running it hourly would be harmless, just pointless.
 */
class ProcessTrialLifecycleRemindersJob implements ShouldQueue
{
    use Queueable;

    public function handle(TrialLifecycleService $lifecycle): void
    {
        $result = $lifecycle->syncAll();

        Log::info('Trial lifecycle synced', $result);
    }
}
