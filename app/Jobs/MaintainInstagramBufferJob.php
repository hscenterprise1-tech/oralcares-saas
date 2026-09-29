<?php

namespace App\Jobs;

use App\Models\MarketingInstagramAccount;
use App\Models\MarketingInstagramPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MaintainInstagramBufferJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Statuses that still count toward the "ready" buffer — anything not yet
     * resolved to published, rejected, or failed.
     */
    protected const IN_FLIGHT_STATUSES = ['generating', 'pending_approval', 'approved', 'scheduled'];

    public function __construct(
        public int $accountId
    ) {}

    public function handle(): void
    {
        $account = MarketingInstagramAccount::find($this->accountId);

        if (! $account || ! $account->auto_fill_enabled) {
            return;
        }

        // Scoped to this account, not the whole organization: the dispatching
        // command already loops per connected account, and each account's
        // own buffer_target should only be measured against its own posts —
        // an org-wide count would let a second account's queue silently
        // count toward (or crowd out) this one's target.
        $inFlightCount = MarketingInstagramPost::where('instagram_account_id', $account->id)
            ->whereIn('status', self::IN_FLIGHT_STATUSES)
            ->count();

        $missing = max(0, $account->buffer_target - $inFlightCount);

        for ($i = 0; $i < $missing; $i++) {
            $post = MarketingInstagramPost::create([
                'organization_id' => $account->organization_id,
                'instagram_account_id' => $account->id,
                'topic' => null,
                'topic_source' => 'auto',
                'status' => 'generating',
            ]);

            GenerateInstagramPostJob::dispatch($post->id);
        }
    }
}
