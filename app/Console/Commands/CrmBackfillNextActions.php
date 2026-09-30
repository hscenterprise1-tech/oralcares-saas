<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\CRM\NextBestActionService;
use Illuminate\Console\Command;

/**
 * Recompute every lead's recommended next action.
 *
 * NextBestActionService was rewritten to be stage-aware, but the rewrite
 * shipped without a backfill, so leads kept whatever string the old engine had
 * last written. The result was advice the current code cannot produce — deals
 * sitting at Quotation Sent still telling the rep to qualify the lead.
 *
 * The recompute is idempotent: it runs each lead through the live service and
 * writes only where the answer differs, so it is safe to run repeatedly and
 * reports honestly when there is nothing to do.
 */
class CrmBackfillNextActions extends Command
{
    protected $signature = 'crm:backfill-next-actions {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute next_best_action for every lead through the current engine';

    public function handle(NextBestActionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $unchanged = 0;

        Lead::query()->chunkById(200, function ($leads) use ($service, $dryRun, &$changed, &$unchanged) {
            foreach ($leads as $lead) {
                $current = $lead->next_best_action;
                $fresh = $service->determineNextAction($lead);

                if ($current === $fresh) {
                    $unchanged++;

                    continue;
                }

                $this->line(sprintf(
                    '  #%d %s
    was:  %s
    now:  %s',
                    $lead->id,
                    $lead->company_name,
                    $current ?? '—',
                    $fresh ?? '— (no priority action)'
                ));

                $changed++;

                if (! $dryRun) {
                    // saveQuietly: the observer would recompute this same value
                    // and log an activity for a correction nobody performed.
                    $lead->next_best_action = $fresh;
                    $lead->saveQuietly();
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            '%s %d lead(s); %d already correct.',
            $dryRun ? 'Would update' : 'Updated',
            $changed,
            $unchanged
        ));

        return self::SUCCESS;
    }
}
