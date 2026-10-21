<?php

namespace App\Console\Commands;

use App\Models\ClinicProvisioningRun;
use App\ProvisioningRunStatus;
use App\Services\Provisioning\ClinicProvisioning;
use DomainException;
use Illuminate\Console\Command;

/**
 * Shows clinic provisioning runs (newest first, failed ones on request) and
 * retries a failed run from the stage it reached.
 */
class ClinicProvisioningRuns extends Command
{
    protected $signature = 'clinic:provisioning-runs
        {--failed : Only failed runs}
        {--retry= : Retry the failed run with this id}
        {--limit=25 : How many runs to list}';

    protected $description = 'List clinic provisioning runs and retry failed ones';

    public function handle(ClinicProvisioning $provisioning): int
    {
        if ($id = $this->option('retry')) {
            $run = ClinicProvisioningRun::find($id);

            if (! $run) {
                $this->error("No provisioning run #{$id}.");

                return self::FAILURE;
            }

            try {
                $run = $provisioning->retry($run);
            } catch (DomainException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info("Run #{$run->id} queued again from stage ".($run->current_stage ?? 'start').'.');

            return self::SUCCESS;
        }

        $runs = ClinicProvisioningRun::query()
            ->when($this->option('failed'), fn ($query) => $query->where('status', ProvisioningRunStatus::Failed))
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->table(
            ['Run', 'Lead', 'Clinic', 'Status', 'Stage', 'Done', 'Attempts', 'Error', 'Queued'],
            $runs->map(fn (ClinicProvisioningRun $run) => [
                $run->id,
                $run->lead_id,
                $run->clinic_id ?? '—',
                $run->status->value,
                $run->current_stage ?? '—',
                count($run->completed_stages ?? []).'/'.count($run->stages()),
                $run->attempts,
                str($run->last_error ?? '')->limit(60),
                $run->queued_at?->diffForHumans() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
