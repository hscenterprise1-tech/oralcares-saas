<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingAutomationEventService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Whether a campaign is finished is its own job rather than a COUNT run
 * inline by every SendCampaignMessageJob. At scale, thousands of those jobs
 * finish close together and would otherwise all race the same query at once;
 * WithoutOverlapping collapses that into one check at a time per campaign,
 * and completion itself only ever applies once because it re-checks the
 * campaign's own status before writing.
 */
class CheckCampaignCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [15, 30];

    public function __construct(
        public int $campaignId
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('campaign-completion:'.$this->campaignId))
                ->releaseAfter(5)
                ->expireAfter(60),
        ];
    }

    public function handle(): void
    {
        $campaign = MarketingCampaign::find($this->campaignId);

        if (! $campaign || $campaign->status !== 'sending') {
            return;
        }

        $remainingCount = $campaign->recipients()
            ->whereIn('status', ['pending', 'queued'])
            ->count();

        if ($remainingCount > 0) {
            return;
        }

        $campaign->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        if ($campaign->organization_id) {
            app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                organizationId: $campaign->organization_id,
                eventType: 'campaign.completed',
                eventKey: "campaign:{$campaign->id}:completed",
                payload: ['campaign_id' => $campaign->id],
                campaign: $campaign
            );
        }
    }
}
