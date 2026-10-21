<?php

namespace App\Console\Commands;

use App\Services\Business\ClinicExpenses;
use App\Support\ClinicClock;
use Illuminate\Console\Command;

/** Record each clinic's recurring expenses that have fallen due, for approval. */
class RaiseRecurringExpenses extends Command
{
    protected $signature = 'clinic:raise-recurring-expenses';

    protected $description = 'Record recurring clinic expenses that have fallen due';

    public function handle(ClinicExpenses $expenses): int
    {
        $raised = $expenses->raiseDue(ClinicClock::now());
        $this->info("Recorded {$raised} recurring expenses.");

        return self::SUCCESS;
    }
}
