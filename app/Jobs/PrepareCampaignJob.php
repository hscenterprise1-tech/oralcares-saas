<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\Marketing\CampaignPreparationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The recipient-generation loop, off the HTTP thread. The controller flips
 * the campaign to `preparing` synchronously (a single guarded UPDATE — see
 * MarketingCampaignController::initiateLaunch()) before dispatching this, so
 * the request returns immediately regardless of recipient count and a
 * double-click can never queue this twice for the same campaign. This job's
 * own WithoutOverlapping lock is the second layer, for anything that isn't
 * gated by that HTTP-level guard — a manual queue retry, a redelivered job.
 */
class PrepareCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public int $campaignId
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('campaign-prepare:'.$this->campaignId))
                ->releaseAfter(10)
                ->expireAfter(600),
        ];
    }

    public function handle(CampaignPreparationService $service): void
    {
        $campaign = MarketingCampaign::find($this->campaignId);

        if (! $campaign) {
            return;
        }

        try {
            $service->prepareAndLaunchCampaign($campaign);
        } catch (\InvalidArgumentException $e) {
            // Not launchable — a permanent condition (wrong status, missing
            // account/template/audience). Retrying will not change that.
            Log::warning('Campaign preparation could not proceed', [
                'campaign_id' => $campaign->id,
                'reason' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }
}
