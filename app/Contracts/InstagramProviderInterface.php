<?php

namespace App\Contracts;

use App\Models\MarketingInstagramAccount;

interface InstagramProviderInterface
{
    /**
     * Check/test connection with provider API using stored credentials.
     */
    public function testConnection(MarketingInstagramAccount $account): bool;

    /**
     * Publish an image + caption to the connected Instagram Business Account.
     * Two-step Graph API flow: create a media container, then publish it.
     *
     * @return array{success: bool, provider_media_id?: string, error?: string}
     */
    public function publish(MarketingInstagramAccount $account, string $imageUrl, string $caption): array;
}
