<?php

namespace App\Services\Marketing\Analytics;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppConversation;
use App\Models\MarketingWhatsAppMessage;

class MarketingJourneyService
{
    /**
     * Build chronological customer journey timeline from canonical database models.
     */
    public function buildContactTimeline(MarketingContact $contact): array
    {
        $events = [];

        // 1. Contact Created Event
        if ($contact->created_at) {
            $events[] = [
                'type' => 'contact_created',
                'title' => 'Added to Marketing Contacts',
                'description' => "Contact created with phone {$contact->phone}",
                'timestamp' => $contact->created_at->toIso8601String(),
                'icon' => 'user-plus',
            ];
        }

        // 2. Tags Added Event
        foreach ($contact->tags as $tag) {
            $events[] = [
                'type' => 'tag_added',
                'title' => "Tagged: {$tag->name}",
                'description' => 'Marketing tag attached to contact',
                'timestamp' => $tag->pivot->created_at ? $tag->pivot->created_at->toIso8601String() : $contact->created_at->toIso8601String(),
                'icon' => 'tag',
            ];
        }

        // 3. Campaign Recipient Events
        $recipients = MarketingCampaignRecipient::where('contact_id', $contact->id)
            ->with(['campaign'])
            ->get();

        foreach ($recipients as $rec) {
            $campName = $rec->campaign?->name ?? 'Marketing Campaign';
            if ($rec->created_at) {
                $events[] = [
                    'type' => 'campaign_included',
                    'title' => "Included in Campaign: {$campName}",
                    'description' => "Snapshot taken for campaign #{$rec->campaign_id}",
                    'timestamp' => $rec->created_at->toIso8601String(),
                    'icon' => 'send',
                ];
            }
            if ($rec->delivered_at) {
                $events[] = [
                    'type' => 'campaign_delivered',
                    'title' => "Campaign Delivered: {$campName}",
                    'description' => 'WhatsApp message delivered to handset',
                    'timestamp' => $rec->delivered_at->toIso8601String(),
                    'icon' => 'check-check',
                ];
            }
            if ($rec->read_at) {
                $events[] = [
                    'type' => 'campaign_read',
                    'title' => "Campaign Read: {$campName}",
                    'description' => 'Customer read campaign WhatsApp message',
                    'timestamp' => $rec->read_at->toIso8601String(),
                    'icon' => 'eye',
                ];
            }
            if ($rec->replied_at) {
                $events[] = [
                    'type' => 'campaign_replied',
                    'title' => "Customer Replied to Campaign: {$campName}",
                    'description' => 'Customer initiated sales response',
                    'timestamp' => $rec->replied_at->toIso8601String(),
                    'icon' => 'message-square',
                ];
            }
        }

        // 4. WhatsApp Conversation & Messages
        $conversations = MarketingWhatsAppConversation::where('contact_id', $contact->id)->get();
        foreach ($conversations as $conv) {
            if ($conv->created_at) {
                $events[] = [
                    'type' => 'conversation_created',
                    'title' => 'WhatsApp Thread Created',
                    'description' => "Conversation thread started with {$conv->phone}",
                    'timestamp' => $conv->created_at->toIso8601String(),
                    'icon' => 'message-circle',
                ];
            }

            $messages = MarketingWhatsAppMessage::where('conversation_id', $conv->id)->latest()->take(20)->get();
            foreach ($messages as $msg) {
                $events[] = [
                    'type' => "whatsapp_msg_{$msg->direction}",
                    'title' => $msg->direction === 'inbound' ? 'Inbound Customer Message' : 'Outbound Reply Sent',
                    'description' => $msg->body,
                    'timestamp' => $msg->created_at->toIso8601String(),
                    'icon' => $msg->direction === 'inbound' ? 'arrow-down-left' : 'arrow-up-right',
                ];
            }
        }

        // 5. CRM Leads & Lead Activities
        $leads = Lead::where('marketing_contact_id', $contact->id)->get();
        foreach ($leads as $lead) {
            if ($lead->created_at) {
                $events[] = [
                    'type' => 'crm_lead_created',
                    'title' => "CRM Lead Created: {$lead->company_name}",
                    'description' => "Lead ingested into pipeline stage: {$lead->status->value}",
                    'timestamp' => $lead->created_at->toIso8601String(),
                    'icon' => 'target',
                ];
            }

            $activities = Activity::where('subject_type', $lead->getMorphClass())
                ->where('subject_id', $lead->getKey())
                ->get();

            foreach ($activities as $act) {
                $events[] = [
                    'type' => 'lead_activity',
                    'title' => 'CRM Pipeline Activity',
                    'description' => $act->description,
                    'timestamp' => $act->created_at->toIso8601String(),
                    'icon' => 'activity',
                ];
            }
        }

        // 6. Automation Executions
        $executions = MarketingAutomationExecution::where('contact_id', $contact->id)
            ->with(['automation'])
            ->get();

        foreach ($executions as $exec) {
            $events[] = [
                'type' => 'automation_executed',
                'title' => "Automation Run: {$exec->automation?->name}",
                'description' => "Status: {$exec->status}".($exec->stop_reason ? " (Stopped: {$exec->stop_reason})" : ''),
                'timestamp' => $exec->created_at->toIso8601String(),
                'icon' => 'zap',
            ];
        }

        // 7. Status Suppressed / Opted Out Safety Event
        if (in_array($contact->marketing_status, ['opted_out', 'suppressed'])) {
            $events[] = [
                'type' => 'safety_status',
                'title' => "Marketing Status: {$contact->marketing_status}",
                'description' => "Contact status updated to {$contact->marketing_status}. Excluded from future campaign dispatch.",
                'timestamp' => $contact->updated_at ? $contact->updated_at->toIso8601String() : now()->toIso8601String(),
                'icon' => 'shield',
            ];
        }

        // Sort chronologically ascending
        usort($events, fn ($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        return $events;
    }
}
