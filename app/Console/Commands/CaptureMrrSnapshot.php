<?php

namespace App\Console\Commands;

use App\Services\Finance\MrrService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Write down what recurring revenue is worth this month.
 *
 * Revenue's growth figure and trend chart read these rows. Without them the
 * page has no history to show — which is the honest state, and what it says.
 */
class CaptureMrrSnapshot extends Command
{
    protected $signature = 'finance:capture-mrr {--month= : The YYYY-MM to record against, defaulting to this month}';

    protected $description = 'Record the current MRR position as this month\'s snapshot';

    public function handle(MrrService $mrr): int
    {
        $month = $this->option('month') ? Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth() : null;

        $snapshot = $mrr->capture($month);

        $this->info("Recorded {$snapshot->period}: MRR {$snapshot->mrr} across {$snapshot->active_subscriptions} active subscriptions.");

        if ($snapshot->unrecorded_subscriptions > 0) {
            /*
             * Said out loud rather than buried. A month missing some
             * subscriptions' MRR understates itself, and whoever reads the
             * chart later needs to know which months are partial.
             */
            $this->warn("{$snapshot->unrecorded_subscriptions} active subscription(s) have no MRR on record, so this month is incomplete.");
        }

        return self::SUCCESS;
    }
}
