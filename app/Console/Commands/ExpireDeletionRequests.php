<?php

namespace App\Console\Commands;

use App\Services\Governance\DeletionGovernance;
use Illuminate\Console\Command;

/**
 * Closes destructive-action requests the owner left undecided past their
 * lifetime, so a stale request can never be approved against a record that
 * has long moved on.
 */
class ExpireDeletionRequests extends Command
{
    protected $signature = 'clinic:expire-deletion-requests';

    protected $description = 'Expire undecided deletion requests past their lifetime';

    public function handle(DeletionGovernance $governance): int
    {
        $this->info("Expired {$governance->expireStale()} request(s).");

        return self::SUCCESS;
    }
}
