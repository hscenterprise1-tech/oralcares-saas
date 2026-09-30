<?php

namespace App\Jobs;

use App\Models\MarketingInstagramPost;
use App\Services\Marketing\InstagramManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishInstagramPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 180, 300];

    public function __construct(
        public int $postId
    ) {}

    public function handle(InstagramManager $instagramManager): void
    {
        $post = MarketingInstagramPost::with('instagramAccount')->find($this->postId);

        if (! $post || ! in_array($post->status, ['approved', 'scheduled', 'publishing'], true)) {
            return;
        }

        // Strong duplicate guard: a post that already has a provider media ID
        // has already gone out — whatever caused this job to run again (a
        // stale duplicate dispatch, a re-queued attempt) must never call the
        // Graph API a second time for the same post.
        if (! empty($post->provider_media_id)) {
            return;
        }

        $account = $post->instagramAccount;
        if (! $account) {
            $post->update(['status' => 'failed', 'error_message' => 'No Instagram account connected.']);

            return;
        }

        $post->update(['status' => 'publishing']);

        $result = $instagramManager->driver()->publish($account, $post->image_url, $post->caption ?? '');

        if (! $result['success']) {
            $post->update(['status' => 'failed', 'error_message' => $result['error'] ?? 'Instagram publish failed.']);

            return;
        }

        $post->update([
            'status' => 'published',
            'provider_media_id' => $result['provider_media_id'] ?? null,
            'published_at' => now(),
        ]);
    }
}
