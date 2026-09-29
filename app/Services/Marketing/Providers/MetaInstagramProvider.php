<?php

namespace App\Services\Marketing\Providers;

use App\Contracts\InstagramProviderInterface;
use App\Models\MarketingInstagramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaInstagramProvider implements InstagramProviderInterface
{
    protected function getApiVersion(): string
    {
        return config('services.meta_instagram.api_version', 'v18.0');
    }

    protected function isSandbox(MarketingInstagramAccount $account): bool
    {
        $token = $account->access_token;

        return empty($token) || empty($account->instagram_business_account_id) || str_contains($token, 'test_token');
    }

    public function testConnection(MarketingInstagramAccount $account): bool
    {
        if ($this->isSandbox($account)) {
            return ! empty($account->access_token) && str_contains($account->access_token, 'test_token');
        }

        try {
            $version = $this->getApiVersion();
            $url = "https://graph.facebook.com/{$version}/{$account->instagram_business_account_id}";
            $response = Http::withToken($account->access_token)->get($url, ['fields' => 'username']);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function publish(MarketingInstagramAccount $account, string $imageUrl, string $caption): array
    {
        if ($this->isSandbox($account)) {
            return [
                'success' => true,
                'provider_media_id' => 'ig_sandbox_'.Str::random(16),
            ];
        }

        try {
            $version = $this->getApiVersion();
            $igUserId = $account->instagram_business_account_id;

            // Step 1: create a media container
            $containerResponse = Http::withToken($account->access_token)
                ->post("https://graph.facebook.com/{$version}/{$igUserId}/media", [
                    'image_url' => $imageUrl,
                    'caption' => $caption,
                ]);

            if (! $containerResponse->successful()) {
                Log::error('Instagram media container creation failed', ['error' => $containerResponse->json()['error'] ?? null]);

                return ['success' => false, 'error' => $containerResponse->json()['error']['message'] ?? 'Failed to create Instagram media container.'];
            }

            $creationId = $containerResponse->json()['id'] ?? null;
            if (! $creationId) {
                return ['success' => false, 'error' => 'Instagram did not return a media container ID.'];
            }

            // Step 2: publish the container
            $publishResponse = Http::withToken($account->access_token)
                ->post("https://graph.facebook.com/{$version}/{$igUserId}/media_publish", [
                    'creation_id' => $creationId,
                ]);

            if (! $publishResponse->successful()) {
                Log::error('Instagram media publish failed', ['error' => $publishResponse->json()['error'] ?? null]);

                return ['success' => false, 'error' => $publishResponse->json()['error']['message'] ?? 'Failed to publish Instagram post.'];
            }

            return [
                'success' => true,
                'provider_media_id' => $publishResponse->json()['id'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('Instagram publish exception: '.$e->getMessage());

            return ['success' => false, 'error' => 'Network error connecting to Instagram Graph API.'];
        }
    }
}
