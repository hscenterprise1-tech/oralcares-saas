<?php

namespace App\Observers\Marketing;

use App\Models\MarketingContact;
use App\Services\Marketing\MarketingAutomationEventService;

class MarketingContactObserver
{
    /**
     * Handle the MarketingContact "created" event.
     */
    public function created(MarketingContact $contact): void
    {
        if ($contact->organization_id) {
            app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                organizationId: $contact->organization_id,
                eventType: 'contact.created',
                eventKey: "contact:{$contact->id}:created",
                payload: [
                    'contact_id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'marketing_status' => $contact->marketing_status,
                ],
                contact: $contact
            );
        }
    }

    /**
     * Handle the MarketingContact "updated" event.
     */
    public function updated(MarketingContact $contact): void
    {
        if ($contact->isDirty('marketing_status') && $contact->organization_id) {
            $oldStatus = $contact->getOriginal('marketing_status') ?? 'none';
            $newStatus = $contact->marketing_status;

            app(MarketingAutomationEventService::class)->dispatchTriggerEvent(
                organizationId: $contact->organization_id,
                eventType: 'contact.status_changed',
                eventKey: "contact:{$contact->id}:status_changed:{$newStatus}",
                payload: [
                    'contact_id' => $contact->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ],
                contact: $contact
            );
        }
    }
}
