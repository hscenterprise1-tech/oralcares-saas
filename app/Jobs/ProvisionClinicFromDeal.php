<?php

namespace App\Jobs;

use App\Events\ClinicProvisioningFailed;
use App\Models\ClinicProvisioningRun;
use App\ProvisioningRunStatus;
use App\Services\Provisioning\ClinicProvisioningOrchestrator;
use App\Services\Security\SecurityAudit;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Provisions one clinic from a won deal, off the request.
 *
 * Unique per run while queued, retried with backoff, and resumable: the run
 * records each completed stage, so a retry continues where the last attempt
 * stopped. When the last retry fails the run stays failed with its error and
 * stage, and can be retried from the company side.
 */
class ProvisionClinicFromDeal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public int $runId)
    {
        $this->onQueue(config('queue.queues.provisioning'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'clinic-provisioning-run:'.$this->runId;
    }

    public function handle(ClinicProvisioningOrchestrator $orchestrator): void
    {
        $run = ClinicProvisioningRun::find($this->runId);

        if (! $run || $run->status === ProvisioningRunStatus::Succeeded) {
            return;
        }

        $orchestrator->run($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ClinicProvisioningRun::find($this->runId);

        if (! $run) {
            return;
        }

        $error = Str::limit($exception?->getMessage() ?? 'Unknown error', 1000);
        $run->update(['status' => ProvisioningRunStatus::Failed, 'last_error' => $error, 'finished_at' => now()]);

        Log::error('Clinic provisioning failed.', ['run_id' => $run->id, 'lead_id' => $run->lead_id, 'stage' => $run->current_stage, 'error' => $error]);
        app(SecurityAudit::class)->record('provisioning.failed', $run, ['stage' => $run->current_stage, 'attempts' => $run->attempts], ['clinic_id' => $run->clinic_id, 'actor_user_id' => null]);

        event(new ClinicProvisioningFailed($run, $error));
    }
}
