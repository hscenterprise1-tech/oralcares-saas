<?php

namespace App\Services\Marketing;

use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppMessage;

class WhatsAppMessageService
{
    public function __construct(
        protected WhatsAppManager $whatsappManager,
        protected WhatsAppConversationService $conversationService
    ) {}

    /**
     * Send single outbound reply / message.
     */
    public function sendOutboundMessage(MarketingWhatsAppAccount $account, string $recipientPhone, string $messageBody): array
    {
        $conversation = $this->conversationService->resolveConversation($account, $recipientPhone);
        $provider = $this->whatsappManager->driver($account->provider);

        $result = $provider->sendMessage($account, $recipientPhone, $messageBody);

        if (! $result['success']) {
            return $result;
        }

        $message = MarketingWhatsAppMessage::create([
            'organization_id' => $account->organization_id,
            'whatsapp_account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'provider_message_id' => $result['provider_message_id'],
            'direction' => 'outbound',
            'message_type' => 'text',
            'body' => $messageBody,
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => $result['payload'] ?? null,
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_outbound_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    /**
     * Send an approved WhatsApp template message (required for business-initiated sends).
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    public function sendOutboundTemplateMessage(
        MarketingWhatsAppAccount $account,
        string $recipientPhone,
        string $templateName,
        string $language,
        array $components,
        string $idempotencyKey
    ): array {
        // Idempotency guard: if this exact automation step already sent, don't send twice.
        $existing = MarketingWhatsAppMessage::where('whatsapp_account_id', $account->id)
            ->whereJsonContains('payload->idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return ['success' => true, 'message' => $existing, 'message_id' => $existing->id];
        }

        $conversation = $this->conversationService->resolveConversation($account, $recipientPhone);
        $provider = $this->whatsappManager->driver($account->provider);

        $result = $provider->sendTemplateMessage($account, $recipientPhone, $templateName, $language, $components);

        if (! $result['success']) {
            return $result;
        }

        $renderedBody = collect($components)
            ->firstWhere('type', 'body')['parameters'] ?? [];
        $bodyPreview = collect($renderedBody)->pluck('text')->implode(' · ') ?: "Template: {$templateName}";

        $message = MarketingWhatsAppMessage::create([
            'organization_id' => $account->organization_id,
            'whatsapp_account_id' => $account->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'provider_message_id' => $result['provider_message_id'],
            'direction' => 'outbound',
            'message_type' => 'template',
            'body' => $bodyPreview,
            'status' => 'sent',
            'sent_at' => now(),
            'payload' => array_merge($result['payload'] ?? [], [
                'template_name' => $templateName,
                'idempotency_key' => $idempotencyKey,
            ]),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_outbound_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => $message,
            'message_id' => $message->id,
        ];
    }

    /**
     * Store incoming inbound message from webhook payload.
     */
    public function storeInboundMessage(MarketingWhatsAppAccount $account, array $event): MarketingWhatsAppMessage
    {
        $phone = $event['phone'] ?? '';
        $conversation = $this->conversationService->resolveConversation($account, $phone);

        // Composite lookup for provider message ID per account (Correction #4)
        $message = MarketingWhatsAppMessage::updateOrCreate(
            [
                'whatsapp_account_id' => $account->id,
                'provider_message_id' => $event['provider_message_id'],
            ],
            [
                'organization_id' => $account->organization_id,
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'direction' => 'inbound',
                'message_type' => $event['message_type'] ?? 'text',
                'body' => $event['body'] ?? '',
                'status' => 'received', // Inbound status (Correction #3)
                'sent_at' => now(),
                'payload' => $event['raw'] ?? null,
            ]
        );

        $conversation->increment('unread_count');
        $conversation->update([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        return $message;
    }

    /**
     * Update outbound message status adhering to strict state machine rules (Correction #3).
     * Flow: queued -> sent -> delivered -> read OR sent/delivered -> failed. Cannot transition to failed after read!
     */
    public function updateMessageStatus(MarketingWhatsAppAccount $account, string $providerMsgId, string $status, ?string $failureReason = null): bool
    {
        // Composite lookup by account & provider message ID (Correction #4)
        $message = MarketingWhatsAppMessage::where('whatsapp_account_id', $account->id)
            ->where('provider_message_id', $providerMsgId)
            ->first();

        if (! $message) {
            return false;
        }

        // State Machine Rule: Disallow transitioning to 'failed' if already marked 'read'
        if ($status === 'failed' && $message->status === 'read') {
            return false;
        }

        $updates = ['status' => $status];
        if ($status === 'delivered') {
            $updates['delivered_at'] = now();
        } elseif ($status === 'read') {
            $updates['read_at'] = now();
        } elseif ($status === 'failed') {
            $updates['failed_at'] = now();
            $updates['failure_reason'] = $failureReason ?? 'Failed to deliver';
        }

        $message->update($updates);

        return true;
    }
}
