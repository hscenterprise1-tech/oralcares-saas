<?php

namespace App\Observers\Marketing;

use App\Models\MarketingWhatsAppConversation;
use App\Services\Marketing\MarketingAutomationEventService;

class MarketingWhatsAppConversationObserver
{
    /**
     * Handle the MarketingWhatsAppConversation "created" event.
     */
    public function created(MarketingWhatsAppConversation $conversation): void
    {
        if ($conversation->organization_id) {
            app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                organizationId: $conversation->organization_id,
                eventType: 'whatsapp.conversation_started',
                eventKey: "whatsapp:conversation:{$conversation->id}:started",
                payload: [
                    'conversation_id' => $conversation->id,
                    'phone' => $conversation->phone,
                ],
                contact: $conversation->contact,
                conversation: $conversation
            );
        }
    }
}
