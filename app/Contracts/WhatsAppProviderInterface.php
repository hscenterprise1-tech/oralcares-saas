<?php

namespace App\Contracts;

use App\Models\MarketingWhatsAppAccount;

interface WhatsAppProviderInterface
{
    /**
     * Verify incoming webhook request signature or token challenge.
     */
    public function verifyWebhookChallenge(string $mode, string $token, string $challenge, string $expectedVerifyToken): ?string;

    /**
     * Send single outbound message to recipient phone number.
     */
    public function sendMessage(MarketingWhatsAppAccount $account, string $recipientPhone, string $messageBody, array $options = []): array;

    /**
     * Send an approved WhatsApp template message (required for business-initiated
     * outbound messages outside the 24h customer-service window).
     *
     * @param  array<int, array<string, mixed>>  $components  Meta template component payload, e.g. body variables.
     */
    public function sendTemplateMessage(MarketingWhatsAppAccount $account, string $recipientPhone, string $templateName, string $language, array $components = []): array;

    /**
     * Check/test connection with provider API using stored credentials.
     */
    public function testConnection(MarketingWhatsAppAccount $account): bool;

    /**
     * Parse raw webhook payload into standardized internal events structure.
     */
    public function parseWebhookPayload(array $payload): array;

    /**
     * Fetch message templates from provider.
     */
    public function getTemplates(MarketingWhatsAppAccount $account): array;
}
