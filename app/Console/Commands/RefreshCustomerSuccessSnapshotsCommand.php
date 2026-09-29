<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\CustomerSuccess\AccountSnapshotService;
use Illuminate\Console\Command;

class RefreshCustomerSuccessSnapshotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cs:snapshots:refresh
                            {--chunk=100 : Number of customer organizations per chunk}
                            {--organization= : Specific customer organization ID to refresh}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compute and refresh materialized Customer Success snapshots';

    /**
     * Execute the console command.
     */
    public function handle(AccountSnapshotService $snapshotService): int
    {
        $specificOrgId = $this->option('organization');

        if ($specificOrgId) {
            $org = Organization::find($specificOrgId);
            if (! $org) {
                $this->error("Organization ID {$specificOrgId} not found.");

                return self::FAILURE;
            }

            if ($org->isProvider()) {
                $this->warn("Organization ID {$specificOrgId} is a provider organization and cannot have a customer snapshot.");

                return self::SUCCESS;
            }

            $snapshot = $snapshotService->refresh($org);
            $this->info("Snapshot successfully created/refreshed for organization #{$org->id} ({$org->name}).");

            return self::SUCCESS;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $this->info("Starting chunked snapshot refresh with chunk size: {$chunkSize}...");

        $count = $snapshotService->refreshAllChunks($chunkSize, function (int $processed) {
            $this->output->write(" Processed {$processed} accounts...\r");
        });

        $this->newLine();
        $this->info("Successfully refreshed {$count} Customer Success snapshots.");

        return self::SUCCESS;
    }
}
