<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppWebhookEvent;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    public function __construct(
        protected WhatsAppManager $whatsappManager,
        protected WhatsAppMessageService $messageService
    ) {}

    /**
     * Handle Webhook Verification Challenge with decrypted verify tokens (Correction #2).
     */
    public function handleChallenge(string $mode, string $token, string $challenge, ?string $provider = 'meta'): ?string
    {
        /*
         * The challenge passes only against a token a configured account
         * actually holds.
         *
         * This used to fall back to a literal written into this file, so anyone
         * reading the source could pass the verification challenge against a
         * system with no account configured at all. There is no fallback now: no
         * matching account means no challenge.
         */
        $expectedToken = null;

        foreach (MarketingWhatsAppAccount::whereNotNull('webhook_verify_token_encrypted')->get() as $acc) {
            if ($acc->webhook_verify_token !== null && hash_equals((string) $acc->webhook_verify_token, $token)) {
                $expectedToken = $token;
                break;
            }
        }

        if ($expectedToken === null) {
            return null;
        }

        $driver = $this->whatsappManager->driver($provider);

        return $driver->verifyWebhookChallenge($mode, $token, $challenge, $expectedToken);
    }

    /**
     * Process incoming webhook payload securely and idempotently with composite uniqueness (Correction #4).
     */
    public function handlePayload(array $payload, ?string $providerName = 'meta', ?string $signatureResult = null): array
    {
        $driver = $this->whatsappManager->driver($providerName);
        $parsedEvents = $driver->parseWebhookPayload($payload);

        $processedCount = 0;
        $skippedCount = 0;

        foreach ($parsedEvents as $evt) {
            $eventUniqueId = $evt['provider_event_id'] ?? null;
            if (! $eventUniqueId) {
                continue;
            }

            // Find matching account by phone_number_id — never fall back to
            // "the first account", which would misattribute an event to a
            // tenant/number it has nothing to do with. An unmatched event is
            // still logged below (with a null account/organization) so it is
            // never silently lost, but no processing happens against it.
            $phoneNumId = $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
            $account = $phoneNumId ? MarketingWhatsAppAccount::where('phone_number_id', $phoneNumId)->first() : null;

            if (! $account) {
                Log::warning('WhatsApp webhook event dropped: no account matches phone_number_id', [
                    'phone_number_id' => $phoneNumId,
                    'event_type' => $evt['event_type'] ?? null,
                ]);
            }

            $accountId = $account?->id;

            // 1. Composite Idempotency Check: Skip duplicate events per account (Correction #4)
            $existing = MarketingWhatsAppWebhookEvent::query()
                ->when($accountId, fn ($q) => $q->where('whatsapp_account_id', $accountId))
                ->where('provider_event_id', $eventUniqueId)
                ->first();

            if ($existing) {
                $skippedCount++;

                continue;
            }

            // 2. Log Raw Webhook Event
            $eventRecord = MarketingWhatsAppWebhookEvent::create([
                'organization_id' => $account?->organization_id,
                'whatsapp_account_id' => $accountId,
                'provider' => $providerName,
                'provider_event_id' => $eventUniqueId,
                'event_type' => $evt['event_type'],
                'payload' => $evt['raw'] ?? $payload,
                /*
                 * Whether Meta's signature was proven, and if not, why. An
                 * unverified event is processed while no app secret is set, but
                 * it is never indistinguishable from a signed one afterwards.
                 */
                'signature_result' => $signatureResult,
                'processing_status' => 'pending',
            ]);

            if ($account) {
                $account->update(['last_webhook_at' => now()]);
            }

            // 3. Process Event Logic
            try {
                if ($evt['event_type'] === 'message.received') {
                    if ($account) {
                        $messageRecord = $this->messageService->storeInboundMessage($account, $evt);

                        // Check for tenant opt-out keyword auto-suppression
                        if ($messageRecord && $account && $account->organization_id && $messageRecord->contact_id) {
                            $settingsService = app(MarketingSettingsService::class);
                            if ($settingsService->isOptOutKeyword($account->organization_id, $messageRecord->body ?? '')) {
                                $contact = $messageRecord->conversation?->contact ?: MarketingContact::find($messageRecord->contact_id);
                                if ($contact) {
                                    app(MarketingSuppressionService::class)->optOutContact(
                                        contact: $contact,
                                        reason: 'customer_request',
                                        notes: "Inbound opt-out keyword detected: {$messageRecord->body}",
                                        changedBy: null,
                                        source: 'whatsapp_provider'
                                    );
                                }
                            }
                        }

                        // Check if conversation originated from a campaign recipient and mark
                        // replied. Scoped to campaigns sent from THIS account — the same
                        // contact can be a recipient of campaigns on different WhatsApp
                        // numbers, and a reply on one number must never be attributed to a
                        // campaign sent from another.
                        if ($messageRecord && $messageRecord->contact_id) {
                            MarketingCampaignRecipient::where('contact_id', $messageRecord->contact_id)
                                ->whereIn('status', ['sent', 'delivered', 'read'])
                                ->whereHas('campaign', fn ($q) => $q->where('whatsapp_account_id', $account->id))
                                ->latest()
                                ->first()
                                ?->markReplied();
                        }

                        // Dispatch Automation Trigger Events
                        if ($messageRecord && $account && $account->organization_id) {
                            $eventService = app(MarketingAutomationEventService::class);

                            $eventService->dispatchTriggerEvent(
                                organizationId: $account->organization_id,
                                eventType: 'whatsapp.reply_received',
                                eventKey: "whatsapp:{$messageRecord->provider_message_id}:reply",
                                payload: ['message_id' => $messageRecord->id, 'body' => $messageRecord->body],
                                contact: $messageRecord->conversation?->contact,
                                conversation: $messageRecord->conversation
                            );

                            $eventService->dispatchTriggerEvent(
                                organizationId: $account->organization_id,
                                eventType: 'whatsapp.message_received',
                                eventKey: "whatsapp:{$messageRecord->provider_message_id}:msg_recv",
                                payload: ['message_id' => $messageRecord->id, 'body' => $messageRecord->body],
                                contact: $messageRecord->conversation?->contact,
                                conversation: $messageRecord->conversation
                            );

                            // A tap on a quick-reply button attached to a template we sent —
                            // fired as its own trigger so automations can branch on which
                            // button was tapped without parsing free-text replies.
                            if (! empty($evt['button_id'])) {
                                $eventService->dispatchTriggerEvent(
                                    organizationId: $account->organization_id,
                                    eventType: 'whatsapp.button_reply',
                                    eventKey: "whatsapp:{$messageRecord->provider_message_id}:button",
                                    payload: [
                                        'message_id' => $messageRecord->id,
                                        'button_id' => $evt['button_id'],
                                        'button_title' => $evt['button_title'] ?? null,
                                    ],
                                    contact: $messageRecord->conversation?->contact,
                                    conversation: $messageRecord->conversation
                                );
                            }
                        }
                    }
                } elseif (str_starts_with($evt['event_type'], 'message.')) {
                    $status = str_replace('message.', '', $evt['event_type']);
                    if ($account) {
                        $this->messageService->updateMessageStatus(
                            $account,
                            $evt['provider_message_id'],
                            $status,
                            $evt['failure_reason'] ?? null
                        );

                        // Update Campaign Recipient Status via correlation key provider_message_id
                        $recipient = MarketingCampaignRecipient::where('provider_message_id', $evt['provider_message_id'])->first();
                        if ($recipient) {
                            if ($status === 'delivered') {
                                $recipient->markDelivered();
                            } elseif ($status === 'read') {
                                $recipient->markRead();
                            } elseif ($status === 'failed') {
                                $recipient->markFailed($evt['failure_reason'] ?? 'Meta Delivery Failure');
                            }
                        }
                    }
                }

                if ($account) {
                    $eventRecord->update([
                        'processing_status' => 'processed',
                        'processed_at' => now(),
                    ]);
                    $processedCount++;
                } else {
                    $eventRecord->update(['processing_status' => 'skipped']);
                    $skippedCount++;
                }
            } catch (\Throwable $e) {
                $eventRecord->update([
                    'processing_status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'processed' => $processedCount,
            'skipped' => $skippedCount,
        ];
    }
}
