<?php

namespace App\Services\Provisioning;

use App\Jobs\ProvisionClinicFromDeal;
use App\Models\ClinicProvisioningRun;
use App\Models\Lead;
use App\Models\User;
use App\ProvisioningRunStatus;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The single entry point for provisioning a clinic from CRM.
 *
 * A run is keyed by `lead:{id}`, so marking a deal Won twice, a double
 * click, or two workers never start a second provisioning for the same
 * deal. The work itself happens in a queued job.
 */
class ClinicProvisioning
{
    public function requestForWonDeal(Lead $lead, ?User $actor = null): ClinicProvisioningRun
    {
        $key = 'lead:'.$lead->id;

        try {
            $run = ClinicProvisioningRun::firstOrCreate(
                ['idempotency_key' => $key],
                [
                    // A deal for an account that already has a clinic (renewal,
                    // expansion, a converted trial) only updates the tenant.
                    'source' => $lead->customer_organization_id ? ClinicProvisioningRun::SOURCE_EXISTING_CUSTOMER : ClinicProvisioningRun::SOURCE_DEAL_WON,
                    'lead_id' => $lead->id,
                    'requested_by' => $actor?->id,
                    'status' => ProvisioningRunStatus::Queued,
                    'queued_at' => now(),
                    'completed_stages' => [],
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $run = ClinicProvisioningRun::where('idempotency_key', $key)->firstOrFail();
        }

        if ($run->wasRecentlyCreated || $run->status === ProvisioningRunStatus::Failed) {
            $this->dispatch($run);
        }

        return $run->fresh();
    }

    /**
     * Retry a failed run from the stage it reached.
     */
    public function retry(ClinicProvisioningRun $run): ClinicProvisioningRun
    {
        if ($run->status !== ProvisioningRunStatus::Failed) {
            throw new DomainException('Only a failed provisioning run can be retried.');
        }

        $this->dispatch($run);

        return $run->fresh();
    }

    private function dispatch(ClinicProvisioningRun $run): void
    {
        $run->update(['status' => ProvisioningRunStatus::Queued, 'queued_at' => now(), 'finished_at' => null]);

        ProvisionClinicFromDeal::dispatch($run->id)->afterCommit();
    }
}
