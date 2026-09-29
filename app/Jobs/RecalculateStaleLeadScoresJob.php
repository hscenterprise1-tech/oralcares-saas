<?php

namespace App\Jobs;

use App\LeadStatus;
use App\Models\Lead;
use App\Services\CRM\LeadScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Lets an open lead's score actually reflect that nobody has touched it.
 *
 * `LeadScoringService::calculateScore()` — the one live scoring formula,
 * wired into `LeadObserver::saving()` — already includes an activity-recency
 * component that falls to zero once a lead goes quiet. The gap was never the
 * formula: a score is only ever recomputed on write, so a lead nobody saves
 * again keeps whatever figure it earned the day it was last touched,
 * however many months of silence follow.
 *
 * This does not invent a second scoring rule. It reruns the existing one for
 * every open lead not written today, so a lead's own recency component keeps
 * decaying the same way the formula already says it should. Runs daily and
 * writes through the query builder rather than `$lead->save()`, on purpose:
 * a model save would re-enter `LeadObserver`, which is the write path this
 * job exists to cover for, not to recurse through — and it would touch
 * `updated_at`, which `Lead::scopeStaleFor()` reads as a staleness signal in
 * its own right and must not see moved by a job that only recomputes a score.
 * Naturally idempotent: recomputing the same inputs on a later run produces
 * the same figure, so running this twice in a day changes nothing further.
 */
class RecalculateStaleLeadScoresJob implements ShouldQueue
{
    use Queueable;

    public function handle(LeadScoringService $scoring): void
    {
        Lead::query()
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->where('updated_at', '<', now()->startOfDay())
            ->chunkById(200, function ($leads) use ($scoring) {
                foreach ($leads as $lead) {
                    $this->recompute($lead, $scoring);
                }
            });
    }

    protected function recompute(Lead $lead, LeadScoringService $scoring): void
    {
        $freshScore = $scoring->calculateScore($lead);

        if ((int) $lead->automated_score === $freshScore) {
            return;
        }

        DB::table('leads')->where('id', $lead->id)->update([
            'automated_score' => $freshScore,
            'lead_score' => $freshScore,
        ]);
    }
}
