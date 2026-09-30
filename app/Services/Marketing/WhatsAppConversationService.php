<?php

namespace App\Services\Marketing;

use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppConversation;

class WhatsAppConversationService
{
    /**
     * Find or create a WhatsApp conversation thread for a given phone number.
     * Reuses M-1 PhoneNormalizer. Enforces conversation key: organization_id + whatsapp_account_id + normalized_phone.
     * Unknown contacts keep contact_id = null (does NOT pollute contact or CRM database).
     */
    public function resolveConversation(MarketingWhatsAppAccount $account, string $rawPhone): MarketingWhatsAppConversation
    {
        // Reuse M-1 PhoneNormalizer (Correction #6)
        $normalized = PhoneNormalizer::normalize($rawPhone);
        if (empty($normalized)) {
            $normalized = preg_replace('/[^0-9]/', '', $rawPhone);
        }

        $orgId = $account->organization_id;

        // Look up existing Marketing Contact using PhoneNormalizer
        $contact = MarketingContact::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('normalized_phone', $normalized)
            ->first();

        // Conversation lookup MANDATES whatsapp_account_id (Correction #5)
        $conversation = MarketingWhatsAppConversation::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('whatsapp_account_id', $account->id)
            ->where('normalized_phone', $normalized)
            ->first();

        if (! $conversation) {
            $conversation = MarketingWhatsAppConversation::create([
                'organization_id' => $orgId,
                'whatsapp_account_id' => $account->id,
                'contact_id' => $contact?->id, // Keeps null for unknown contacts (Correction #7)
                'phone' => $rawPhone,
                'normalized_phone' => $normalized,
                'status' => 'open',
                'unread_count' => 0,
            ]);
        } elseif ($contact && ! $conversation->contact_id) {
            $conversation->update(['contact_id' => $contact->id]);
        }

        return $conversation;
    }

    /**
     * Mark conversation as read and reset unread counter.
     *
     * Scoped column update rather than $conversation->update() — the passed-in
     * instance may already carry a non-column runtime attribute (e.g. the
     * controller's "campaign_context" annotation), and Eloquent's save()
     * would try to persist every dirty attribute, not just the one given
     * here, and fail on the unknown column.
     */
    public function markAsRead(MarketingWhatsAppConversation $conversation): void
    {
        MarketingWhatsAppConversation::whereKey($conversation->id)->update([
            'unread_count' => 0,
        ]);
    }
}
