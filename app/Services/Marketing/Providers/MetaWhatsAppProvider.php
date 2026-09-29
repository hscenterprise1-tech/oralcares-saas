<?php

namespace App\Services\Marketing\Providers;

use App\Contracts\WhatsAppProviderInterface;
use App\Models\MarketingWhatsAppAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * Get configurable API version from config or fallback.
     */
    protected function getApiVersion(): string
    {
        return config('services.meta_whatsapp.api_version', 'v18.0');
    }

    /**
     * Verify Meta Webhook Hub Challenge.
     */
    public function verifyWebhookChallenge(string $mode, string $token, string $challenge, string $expectedVerifyToken): ?string
    {
        if ($mode === 'subscribe' && ! empty($token) && ! empty($expectedVerifyToken) && $token === $expectedVerifyToken) {
            return $challenge;
        }

        return null;
    }

    /**
     * Send outbound message via Meta Cloud API using configured API version.
     */
    public function sendMessage(MarketingWhatsAppAccount $account, string $recipientPhone, string $messageBody, array $options = []): array
    {
        $token = $account->access_token;
        $phoneNumberId = $account->phone_number_id;

        // Sandbox/Test mode fallback if credentials are test placeholders
        if (empty($token) || empty($phoneNumberId) || str_contains($token, 'test_token')) {
            $fakeMessageId = 'wamid.HBgL'.Str::random(24);

            return [
                'success' => true,
                'provider_message_id' => $fakeMessageId,
                'status' => 'sent',
                'payload' => ['fake' => true, 'body' => $messageBody],
            ];
        }

        try {
            $version = $this->getApiVersion();
            $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

            $response = Http::withToken($token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipientPhone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $messageBody,
                    ],
                ]);

            if ($response->successful()) {
                $json = $response->json();
                $providerMsgId = $json['messages'][0]['id'] ?? ('wamid.'.Str::random(20));

                return [
                    'success' => true,
                    'provider_message_id' => $providerMsgId,
                    'status' => 'sent',
                    'payload' => $json,
                ];
            }

            if ($response->status() === 429 || ($response->json()['error']['code'] ?? null) == 130429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 60);

                Log::warning('Meta WhatsApp API Rate Limit hit', [
                    'account_id' => $account->id,
                    'phone' => $recipientPhone,
                    'retry_after' => $retryAfter,
                ]);

                return [
                    'success' => false,
                    'rate_limited' => true,
                    'retry_after' => $retryAfter,
                    'error' => 'Meta WhatsApp API rate limit reached (HTTP 429)',
                ];
            }

            Log::error('Meta WhatsApp API error response', [
                'status' => $response->status(),
                'error' => $response->json()['error'] ?? 'Unknown error',
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Failed to send message via Meta WhatsApp API',
            ];
        } catch (\Throwable $e) {
            Log::error('Meta WhatsApp API Exception: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Network error connecting to Meta WhatsApp API',
            ];
        }
    }

    /**
     * Send an approved WhatsApp template message via Meta Cloud API.
     */
    public function sendTemplateMessage(MarketingWhatsAppAccount $account, string $recipientPhone, string $templateName, string $language, array $components = []): array
    {
        $token = $account->access_token;
        $phoneNumberId = $account->phone_number_id;

        // Sandbox/Test mode fallback if credentials are test placeholders
        if (empty($token) || empty($phoneNumberId) || str_contains($token, 'test_token')) {
            $fakeMessageId = 'wamid.HBgL'.Str::random(24);

            return [
                'success' => true,
                'provider_message_id' => $fakeMessageId,
                'status' => 'sent',
                'payload' => ['fake' => true, 'template' => $templateName],
            ];
        }

        try {
            $version = $this->getApiVersion();
            $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

            $response = Http::withToken($token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipientPhone,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => $language],
                        'components' => $components,
                    ],
                ]);

            if ($response->successful()) {
                $json = $response->json();
                $providerMsgId = $json['messages'][0]['id'] ?? ('wamid.'.Str::random(20));

                return [
                    'success' => true,
                    'provider_message_id' => $providerMsgId,
                    'status' => 'sent',
                    'payload' => $json,
                ];
            }

            if ($response->status() === 429 || ($response->json()['error']['code'] ?? null) == 130429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 60);

                return [
                    'success' => false,
                    'rate_limited' => true,
                    'retry_after' => $retryAfter,
                    'error' => 'Meta WhatsApp API rate limit reached (HTTP 429)',
                ];
            }

            Log::error('Meta WhatsApp template send error response', [
                'status' => $response->status(),
                'error' => $response->json()['error'] ?? 'Unknown error',
            ]);

            return [
                'success' => false,
                'error' => $response->json()['error']['message'] ?? 'Failed to send template message via Meta WhatsApp API',
            ];
        } catch (\Throwable $e) {
            Log::error('Meta WhatsApp template send exception: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Network error connecting to Meta WhatsApp API',
            ];
        }
    }

    /**
     * Test connection credentials against Meta Graph API.
     */
    public function testConnection(MarketingWhatsAppAccount $account): bool
    {
        $token = $account->access_token;
        $phoneNumberId = $account->phone_number_id;

        if (empty($token) || empty($phoneNumberId)) {
            return false;
        }

        if (str_contains($token, 'test_token')) {
            return true;
        }

        try {
            $version = $this->getApiVersion();
            $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}";
            $response = Http::withToken($token)->get($url);

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse raw Meta WhatsApp webhook payload into standardized events.
     */
    public function parseWebhookPayload(array $payload): array
    {
        $events = [];

        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];

                // 1. Incoming Messages
                $messages = $value['messages'] ?? [];
                foreach ($messages as $msg) {
                    $senderPhone = $msg['from'] ?? '';
                    $msgType = $msg['type'] ?? 'text';
                    $msgId = $msg['id'] ?? ('wamid.inc.'.Str::random(16));

                    $buttonId = null;
                    $buttonTitle = null;

                    if ($msgType === 'interactive' && ($msg['interactive']['type'] ?? null) === 'button_reply') {
                        // Tap on a quick-reply button attached to a template we sent.
                        $buttonId = $msg['interactive']['button_reply']['id'] ?? null;
                        $buttonTitle = $msg['interactive']['button_reply']['title'] ?? null;
                        $body = $buttonTitle ?? '';
                    } else {
                        $body = $msg['text']['body'] ?? ($msg['caption'] ?? '');
                    }

                    $events[] = [
                        'event_type' => 'message.received',
                        'provider_event_id' => 'evt_'.$msgId,
                        'provider_message_id' => $msgId,
                        'phone' => $senderPhone,
                        'message_type' => $msgType,
                        'body' => $body,
                        'button_id' => $buttonId,
                        'button_title' => $buttonTitle,
                        'timestamp' => $msg['timestamp'] ?? time(),
                        'raw' => $msg,
                    ];
                }

                // 2. Status Updates (sent, delivered, read, failed)
                $statuses = $value['statuses'] ?? [];
                foreach ($statuses as $st) {
                    $msgId = $st['id'] ?? '';
                    $status = $st['status'] ?? 'sent';
                    $recipientPhone = $st['recipient_id'] ?? '';
                    $timestamp = $st['timestamp'] ?? time();
                    $errors = $st['errors'] ?? [];

                    $events[] = [
                        'event_type' => 'message.'.$status,
                        'provider_event_id' => 'evt_st_'.$msgId.'_'.$status,
                        'provider_message_id' => $msgId,
                        'phone' => $recipientPhone,
                        'status' => $status,
                        'failure_reason' => ! empty($errors) ? ($errors[0]['title'] ?? 'Failed') : null,
                        'timestamp' => $timestamp,
                        'raw' => $st,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * Fetch message templates from Meta Graph API or return sample templates in sandbox test mode.
     */
    public function getTemplates(MarketingWhatsAppAccount $account): array
    {
        $token = $account->access_token;
        $wabaId = $account->business_account_id;

        // Test/Sandbox fallback
        if (empty($token) || empty($wabaId) || str_contains($token, 'test_token')) {
            return [
                [
                    'id' => 'tmpl_101_intro',
                    'name' => 'oralcares_intro_demo',
                    'language' => 'en',
                    'category' => 'MARKETING',
                    'status' => 'approved',
                    'body' => 'Hello {{1}}, welcome to OralCares Enterprise Clinic Software. Schedule your live demo today!',
                    'header_type' => 'text',
                    'header_content' => 'Special Clinic Offer',
                    'footer' => 'OralCares SaaS',
                    'variables' => ['1'],
                    'buttons' => [
                        ['type' => 'QUICK_REPLY', 'text' => 'Interested'],
                        ['type' => 'QUICK_REPLY', 'text' => 'Not Interested'],
                    ],
                ],
                [
                    'id' => 'tmpl_102_followup',
                    'name' => 'dental_clinic_followup',
                    'language' => 'en',
                    'category' => 'MARKETING',
                    'status' => 'approved',
                    'body' => 'Dear Dr. {{1}}, thank you for discussing {{2}} with OralCares. Click here to upgrade your practice!',
                    'header_type' => null,
                    'header_content' => null,
                    'footer' => 'OralCares Team',
                    'variables' => ['1', '2'],
                ],
            ];
        }

        try {
            $version = $this->getApiVersion();
            $url = "https://graph.facebook.com/{$version}/{$wabaId}/message_templates";
            $response = Http::withToken($token)->get($url);

            if ($response->successful()) {
                $data = $response->json()['data'] ?? [];
                $templates = [];

                foreach ($data as $t) {
                    $bodyText = '';
                    $variables = [];
                    $headerType = null;
                    $headerContent = null;
                    $footer = null;
                    $buttons = [];

                    foreach ($t['components'] ?? [] as $comp) {
                        if ($comp['type'] === 'BODY') {
                            $bodyText = $comp['text'] ?? '';
                            preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $matches);
                            $variables = array_values(array_unique($matches[1] ?? []));
                        } elseif ($comp['type'] === 'HEADER') {
                            $headerType = strtolower($comp['format'] ?? 'text');
                            $headerContent = $comp['text'] ?? null;
                        } elseif ($comp['type'] === 'FOOTER') {
                            $footer = $comp['text'] ?? null;
                        } elseif ($comp['type'] === 'BUTTONS') {
                            foreach ($comp['buttons'] ?? [] as $btn) {
                                $buttons[] = [
                                    'type' => $btn['type'] ?? 'QUICK_REPLY',
                                    'text' => $btn['text'] ?? '',
                                ];
                            }
                        }
                    }

                    $templates[] = [
                        'id' => $t['id'] ?? null,
                        'name' => $t['name'] ?? '',
                        'language' => $t['language'] ?? 'en',
                        'category' => $t['category'] ?? 'MARKETING',
                        'status' => strtolower($t['status'] ?? 'approved'),
                        'body' => $bodyText,
                        'header_type' => $headerType,
                        'header_content' => $headerContent,
                        'footer' => $footer,
                        'buttons' => $buttons,
                        'variables' => $variables,
                        'raw' => $t,
                    ];
                }

                return $templates;
            }

            return [];
        } catch (\Throwable $e) {
            Log::error('Failed to fetch Meta WhatsApp templates: '.$e->getMessage());

            return [];
        }
    }
}
