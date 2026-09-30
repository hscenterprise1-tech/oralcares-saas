<?php

namespace App\Services\Marketing;

use App\Models\MarketingAiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OpenAiContentService
{
    /**
     * Rotating fallback topic categories for a dental clinic, used both as the sandbox
     * topic-suggestion source and as the last-resort fallback if a real OpenAI topic
     * suggestion call fails. Kept broad and evergreen — occasion-specific posts (a
     * festival, a launch) are always the admin's manual "Generate Post" input instead.
     */
    protected const TOPIC_ROTATION = [
        'A simple oral hygiene tip patients can use today',
        'A before-and-after smile transformation story',
        'Meet the team — introduce a doctor or staff member',
        'Busting a common myth about dental treatment',
        'Why regular checkups matter, explained simply',
        'A patient care spotlight or thank-you post',
        'A fun, surprising fact about teeth or oral health',
        "This week's clinic highlight or service reminder",
        'What to expect during a first visit to the clinic',
        'Kids and dental care — a friendly tip for parents',
    ];

    protected function apiBase(): string
    {
        return config('services.openai.api_base', 'https://api.openai.com/v1');
    }

    /**
     * Pick the next topic for an auto-generated post, avoiding recent repeats.
     * Falls back to a rotating category list when no API key is configured or the call fails.
     */
    public function suggestTopic(array $recentTopics, ?MarketingAiSetting $settings): string
    {
        if ($settings && $settings->isConfigured()) {
            try {
                $response = Http::withToken($settings->api_key)
                    ->post($this->apiBase().'/chat/completions', [
                        'model' => $settings->text_model,
                        'messages' => [
                            ['role' => 'system', 'content' => 'You suggest a single short Instagram post topic (under 12 words) for a dental clinic marketing account. Reply with only the topic, no quotes, no numbering.'],
                            ['role' => 'user', 'content' => 'Recently posted about: '.(empty($recentTopics) ? 'nothing yet' : implode('; ', $recentTopics)).'. Suggest a fresh, different topic.'],
                        ],
                        'max_tokens' => 60,
                    ]);

                if ($response->successful()) {
                    $topic = trim($response->json()['choices'][0]['message']['content'] ?? '');
                    if (! empty($topic)) {
                        return $topic;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('OpenAI topic suggestion exception: '.$e->getMessage());
            }
        }

        $unused = array_values(array_diff(self::TOPIC_ROTATION, $recentTopics));
        $pool = ! empty($unused) ? $unused : self::TOPIC_ROTATION;

        return $pool[array_rand($pool)];
    }

    /**
     * Write an Instagram caption for the given topic. Falls back to a deterministic
     * sandbox caption when no API key is configured, so the whole approval flow is
     * testable before real OpenAI/Instagram credentials exist.
     */
    public function generateCaption(string $topic, ?MarketingAiSetting $settings): array
    {
        if (! $settings || ! $settings->isConfigured()) {
            return [
                'success' => true,
                'caption' => "✨ {$topic} ✨\n\nAt OralCares, we're bringing this to life for our patients. Book your visit today!\n\n#OralCares #DentalCare #SmileMore",
                'sandbox' => true,
            ];
        }

        try {
            $response = Http::withToken($settings->api_key)
                ->post($this->apiBase().'/chat/completions', [
                    'model' => $settings->text_model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You write short, warm, on-brand Instagram captions for a dental clinic marketing account. Keep it under 150 words and include 3-5 relevant hashtags.'],
                        ['role' => 'user', 'content' => "Write an Instagram caption about: {$topic}"],
                    ],
                    'max_tokens' => 300,
                ]);

            if (! $response->successful()) {
                Log::error('OpenAI caption generation failed', ['status' => $response->status(), 'body' => $response->body()]);

                return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'OpenAI caption generation failed.'];
            }

            $caption = trim($response->json()['choices'][0]['message']['content'] ?? '');

            return ['success' => true, 'caption' => $caption, 'sandbox' => false];
        } catch (\Throwable $e) {
            Log::error('OpenAI caption generation exception: '.$e->getMessage());

            return ['success' => false, 'error' => 'Network error connecting to OpenAI.'];
        }
    }

    /**
     * Generate an image for the given topic, watermark it with the brand logo (if one is
     * configured and the GD extension is available), and store it on the public disk.
     * Falls back to a bundled sandbox placeholder image when no API key is configured.
     *
     * @return array{success: bool, path?: string, url?: string, sandbox?: bool, error?: string}
     */
    public function generateImage(string $topic, ?MarketingAiSetting $settings, ?string $logoPath = null): array
    {
        $storagePath = 'instagram/'.date('Y/m').'/'.Str::uuid().'.png';

        if (! $settings || ! $settings->isConfigured()) {
            $bytes = $this->applyLogo($this->sandboxPlaceholderImage(), $logoPath);
            Storage::disk('public')->put($storagePath, $bytes);

            return [
                'success' => true,
                'path' => $storagePath,
                'url' => Storage::disk('public')->url($storagePath),
                'sandbox' => true,
            ];
        }

        try {
            $response = Http::withToken($settings->api_key)
                ->post($this->apiBase().'/images/generations', [
                    'model' => $settings->image_model,
                    'prompt' => "A clean, bright, professional social-media photo for a dental clinic Instagram post about: {$topic}. Warm, welcoming, high quality. Leave the bottom-right corner visually simple/uncluttered.",
                    'size' => '1024x1024',
                    'n' => 1,
                ]);

            if (! $response->successful()) {
                Log::error('OpenAI image generation failed', ['status' => $response->status(), 'body' => $response->body()]);

                return ['success' => false, 'error' => $response->json()['error']['message'] ?? 'OpenAI image generation failed.'];
            }

            $remoteUrl = $response->json()['data'][0]['url'] ?? null;
            if (! $remoteUrl) {
                return ['success' => false, 'error' => 'OpenAI did not return an image URL.'];
            }

            $imageBytes = Http::get($remoteUrl)->body();
            $imageBytes = $this->applyLogo($imageBytes, $logoPath);
            Storage::disk('public')->put($storagePath, $imageBytes);

            return [
                'success' => true,
                'path' => $storagePath,
                'url' => Storage::disk('public')->url($storagePath),
                'sandbox' => false,
            ];
        } catch (\Throwable $e) {
            Log::error('OpenAI image generation exception: '.$e->getMessage());

            return ['success' => false, 'error' => 'Network error connecting to OpenAI.'];
        }
    }

    /**
     * Composite the brand logo onto the bottom-right corner of a generated image using GD.
     * Requires the `gd` PHP extension — if it isn't installed, or no logo is configured,
     * the original image bytes are returned unchanged (never blocks post generation).
     */
    protected function applyLogo(string $imageBytes, ?string $logoPath): string
    {
        if (empty($logoPath) || ! extension_loaded('gd') || ! Storage::disk('public')->exists($logoPath)) {
            return $imageBytes;
        }

        try {
            $base = imagecreatefromstring($imageBytes);
            $logo = imagecreatefromstring(Storage::disk('public')->get($logoPath));

            if (! $base || ! $logo) {
                return $imageBytes;
            }

            imagesavealpha($base, true);
            imagealphablending($logo, true);
            imagesavealpha($logo, true);

            $baseWidth = imagesx($base);
            $baseHeight = imagesy($base);
            $logoWidth = imagesx($logo);
            $logoHeight = imagesy($logo);

            // Scale the logo to ~15% of the image width, preserving aspect ratio.
            $targetWidth = (int) ($baseWidth * 0.15);
            $targetHeight = (int) ($targetWidth * ($logoHeight / $logoWidth));

            $resizedLogo = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($resizedLogo, false);
            imagesavealpha($resizedLogo, true);
            imagecopyresampled($resizedLogo, $logo, 0, 0, 0, 0, $targetWidth, $targetHeight, $logoWidth, $logoHeight);

            $margin = (int) ($baseWidth * 0.03);
            $destX = $baseWidth - $targetWidth - $margin;
            $destY = $baseHeight - $targetHeight - $margin;

            imagealphablending($base, true);
            imagecopy($base, $resizedLogo, $destX, $destY, 0, 0, $targetWidth, $targetHeight);

            ob_start();
            imagepng($base);
            $result = ob_get_clean();

            imagedestroy($base);
            imagedestroy($logo);
            imagedestroy($resizedLogo);

            return $result !== false ? $result : $imageBytes;
        } catch (\Throwable $e) {
            Log::error('Logo compositing failed: '.$e->getMessage());

            return $imageBytes;
        }
    }

    /**
     * A minimal 1x1 transparent-free PNG so sandbox mode has a real, valid image file to store
     * (kept tiny and inline rather than shipping a binary asset).
     */
    protected function sandboxPlaceholderImage(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        );
    }
}
