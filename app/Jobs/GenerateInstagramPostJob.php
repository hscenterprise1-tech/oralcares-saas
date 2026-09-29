<?php

namespace App\Jobs;

use App\Models\MarketingAiSetting;
use App\Models\MarketingInstagramPost;
use App\Services\Marketing\OpenAiContentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInstagramPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public int $postId
    ) {}

    public function handle(OpenAiContentService $contentService): void
    {
        $post = MarketingInstagramPost::with('instagramAccount')->find($this->postId);
        if (! $post || $post->status !== 'generating') {
            return;
        }

        $settings = MarketingAiSetting::where('organization_id', $post->organization_id)
            ->where('provider', 'openai')
            ->first();

        // Buffer-fill posts are created without a topic — the AI picks one itself,
        // avoiding whatever the last few auto-generated posts already covered.
        if (empty($post->topic)) {
            $recentTopics = MarketingInstagramPost::where('organization_id', $post->organization_id)
                ->where('topic_source', 'auto')
                ->whereNotNull('topic')
                ->latest()
                ->limit(8)
                ->pluck('topic')
                ->all();

            $post->topic = $contentService->suggestTopic($recentTopics, $settings);
            $post->save();
        }

        $captionResult = $contentService->generateCaption($post->topic, $settings);
        if (! $captionResult['success']) {
            $post->update(['status' => 'failed', 'error_message' => $captionResult['error']]);

            return;
        }

        $logoPath = $post->instagramAccount?->logo_path;
        $imageResult = $contentService->generateImage($post->topic, $settings, $logoPath);
        if (! $imageResult['success']) {
            $post->update(['status' => 'failed', 'error_message' => $imageResult['error']]);

            return;
        }

        $post->update([
            'caption' => $captionResult['caption'],
            'image_path' => $imageResult['path'],
            'image_url' => $imageResult['url'],
            'status' => 'pending_approval',
        ]);
    }
}
