<?php

namespace App\Services\Marketing;

use App\Jobs\ProcessMarketingAutomationExecution;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppConversation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class MarketingAutomationEventService
{
    /**
     * Dispatch a domain trigger event into the automation engine.
     */
    public function dispatchTriggerEvent(
        int $organizationId,
        string $eventType,
        string $eventKey,
        array $payload = [],
        ?MarketingContact $contact = null,
        ?MarketingWhatsAppConversation $conversation = null,
        mixed $lead = null,
        mixed $campaign = null
    ): ?MarketingAutomationEvent {
        // Enforce event_key idempotency (Section 10)
        $existingEvent = MarketingAutomationEvent::where('organization_id', $organizationId)
            ->where('event_key', $eventKey)
            ->first();

        if ($existingEvent) {
            return $existingEvent;
        }

        try {
            return $this->createEventAndExecutions($organizationId, $eventType, $eventKey, $payload, $contact, $conversation, $lead, $campaign);
        } catch (UniqueConstraintViolationException) {
            // Two callers raced past the check above for the same event_key
            // (a provider redelivering the same webhook is the realistic
            // case) — the DB's own unique constraint is what actually
            // decides this, not the check above, which is why this is
            // caught rather than prevented. Whoever lost the race returns
            // the row the winner committed, the same as the early-return
            // above; no second set of executions is created either way.
            return MarketingAutomationEvent::where('organization_id', $organizationId)
                ->where('event_key', $eventKey)
                ->first();
        }
    }

    /**
     * @param  mixed  $lead
     * @param  mixed  $campaign
     */
    protected function createEventAndExecutions(
        int $organizationId,
        string $eventType,
        string $eventKey,
        array $payload,
        ?MarketingContact $contact,
        ?MarketingWhatsAppConversation $conversation,
        $lead,
        $campaign
    ): ?MarketingAutomationEvent {
        return DB::transaction(function () use (
            $organizationId,
            $eventType,
            $eventKey,
            $payload,
            $contact,
            $conversation,
            $lead,
            $campaign
        ) {
            $event = MarketingAutomationEvent::create([
                'organization_id' => $organizationId,
                'event_key' => $eventKey,
                'event_type' => $eventType,
                'contact_id' => $contact?->id,
                'conversation_id' => $conversation?->id,
                'lead_id' => is_object($lead) ? $lead->id : $lead,
                'campaign_id' => is_object($campaign) ? $campaign->id : $campaign,
                'payload' => $payload,
                'occurred_at' => now(),
            ]);

            // Find matching active automations for this tenant
            $automations = MarketingAutomation::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->where('trigger_type', $eventType)
                ->get();

            foreach ($automations as $automation) {
                // Check optional cooldown rules (Section 25)
                if ($this->isCooldownActive($automation, $contact)) {
                    continue;
                }

                $execution = MarketingAutomationExecution::create([
                    'organization_id' => $organizationId,
                    'automation_id' => $automation->id,
                    'contact_id' => $contact?->id,
                    'conversation_id' => $conversation?->id,
                    'lead_id' => is_object($lead) ? $lead->id : $lead,
                    'campaign_id' => is_object($campaign) ? $campaign->id : $campaign,
                    'trigger_event_id' => $event->id,
                    'status' => 'queued',
                ]);

                // Dispatch Queue Job (Section 22)
                ProcessMarketingAutomationExecution::dispatch($execution->id);
            }

            $event->update(['processed_at' => now()]);

            return $event;
        });
    }

    /**
     * Check if a contact is currently within an automation cooldown period.
     */
    protected function isCooldownActive(MarketingAutomation $automation, ?MarketingContact $contact): bool
    {
        if (! $contact) {
            return false;
        }

        $definition = $automation->definition;
        $cooldown = $definition['cooldown'] ?? null;
        if (! $cooldown || empty($cooldown['enabled'])) {
            return false;
        }

        $duration = (int) ($cooldown['duration'] ?? 24);
        $unit = $cooldown['unit'] ?? 'hours';

        $cutoff = match ($unit) {
            'days' => now()->subDays($duration),
            default => now()->subHours($duration),
        };

        return MarketingAutomationExecution::where('automation_id', $automation->id)
            ->where('contact_id', $contact->id)
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }
}
