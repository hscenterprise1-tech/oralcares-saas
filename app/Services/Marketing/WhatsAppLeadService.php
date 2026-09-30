<?php

namespace App\Services\Marketing;

use App\ActivityType;
use App\LeadPriority;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppConversation;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WhatsAppLeadService
{
    /**
     * Find existing CRM Lead associated with a Marketing Contact.
     */
    public function findLeadForContact(MarketingContact $contact): ?Lead
    {
        if ($contact->crmLead) {
            return $contact->crmLead;
        }

        $normalized = $contact->normalized_phone ?: PhoneNormalizer::normalize($contact->phone);

        return Lead::query()
            ->when($contact->organization_id, fn ($q) => $q->where('organization_id', $contact->organization_id))
            ->where(function ($q) use ($normalized, $contact) {
                $q->where('marketing_contact_id', $contact->id)
                    ->orWhere('normalized_phone', $normalized)
                    ->orWhere('phone', 'like', "%{$normalized}%");
            })
            ->latest()
            ->first();
    }

    /**
     * Find existing CRM Lead associated with a WhatsApp Conversation.
     */
    public function findLeadForConversation(MarketingWhatsAppConversation $conversation): ?Lead
    {
        if ($conversation->crmLead) {
            return $conversation->crmLead;
        }

        if ($conversation->contact) {
            $lead = $this->findLeadForContact($conversation->contact);
            if ($lead) {
                // Auto associate in memory/database
                $conversation->update(['crm_lead_id' => $lead->id]);

                return $lead;
            }
        }

        $normalized = $conversation->normalized_phone ?: PhoneNormalizer::normalize($conversation->phone);

        $lead = Lead::query()
            ->when($conversation->organization_id, fn ($q) => $q->where('organization_id', $conversation->organization_id))
            ->where(function ($q) use ($normalized) {
                $q->where('normalized_phone', $normalized)
                    ->orWhere('phone', 'like', "%{$normalized}%");
            })
            ->latest()
            ->first();

        if ($lead) {
            $conversation->update(['crm_lead_id' => $lead->id]);
        }

        return $lead;
    }

    /**
     * Create a new CRM Lead from a WhatsApp Conversation.
     */
    public function createLeadFromConversation(MarketingWhatsAppConversation $conversation, array $leadData, User $user): Lead
    {
        // Tenant check
        if ($conversation->organization_id && $conversation->organization_id !== $user->organization_id) {
            throw new InvalidArgumentException('Conversation belongs to another tenant organization.');
        }

        // Check for existing active lead to prevent duplicates
        $existingLead = $this->findLeadForConversation($conversation);
        if ($existingLead && ! in_array($existingLead->status, [LeadStatus::Won, LeadStatus::Lost])) {
            return $existingLead;
        }

        return DB::transaction(function () use ($conversation, $leadData, $user) {
            $contact = $conversation->contact;
            $companyName = $leadData['company_name'] ?? $contact?->organization ?? $contact?->name ?? "Lead {$conversation->phone}";
            $contactName = $leadData['contact_name'] ?? $contact?->name ?? "Contact {$conversation->phone}";
            $normalizedPhone = $conversation->normalized_phone ?: PhoneNormalizer::normalize($conversation->phone);

            // Campaign attribution check
            $campaignRecipient = null;
            if ($contact) {
                $campaignRecipient = MarketingCampaignRecipient::where('contact_id', $contact->id)
                    ->latest()
                    ->first();
            }

            $campaignId = $leadData['marketing_campaign_id'] ?? $campaignRecipient?->campaign_id;
            $source = $campaignId ? 'whatsapp' : ($leadData['source'] ?? 'whatsapp');

            // Marketing-originated leads run through the same duplicate check
            // as manual creation — an inbound WhatsApp conversation is one of
            // the likeliest sources of a repeat enquiry.
            $workspaceService = app(LeadWorkspaceService::class);
            $duplicate = $workspaceService->findDuplicateLead(
                $user->organization_id,
                $conversation->phone,
                $leadData['email'] ?? null,
                null,
                $companyName
            );

            $lead = Lead::create([
                'company_name' => $companyName,
                'slug' => Str::slug($companyName.'-'.Str::random(5)),
                'contact_name' => $contactName,
                'email' => $leadData['email'] ?? null,
                'phone' => $conversation->phone,
                'normalized_phone' => $normalizedPhone,
                'status' => $leadData['status'] ?? LeadStatus::New,
                'priority' => $leadData['priority'] ?? LeadPriority::Medium,
                'source' => $source,
                'organization_id' => $user->organization_id,
                'assigned_user_id' => $leadData['assigned_user_id'] ?? $user->id,
                'marketing_contact_id' => $contact?->id,
                'marketing_campaign_id' => $campaignId,
            ]);

            $workspaceService->flagDuplicate($lead, $duplicate, $user);

            // Link conversation
            $conversation->update(['crm_lead_id' => $lead->id]);

            // If campaign recipient exists, link crm_lead_id
            if ($campaignRecipient) {
                $campaignRecipient->update(['crm_lead_id' => $lead->id]);
            }

            // Log CRM Lead Activity
            $description = $campaignId
                ? 'Lead created from WhatsApp campaign conversation.'
                : 'Lead created from WhatsApp conversation.';

            Activity::create([
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->getKey(),
                'type' => ActivityType::RecordCreated,
                'description' => $description,
                'user_id' => $user->id,
            ]);

            return $lead;
        });
    }

    /**
     * Link an existing CRM Lead to a WhatsApp Conversation.
     */
    public function linkExistingLead(MarketingWhatsAppConversation $conversation, Lead $lead, User $user): void
    {
        // Tenant Authorization Check
        if ($lead->organization_id && $lead->organization_id !== $user->organization_id) {
            throw new InvalidArgumentException('Cannot link lead from a different tenant organization.');
        }

        if ($conversation->organization_id && $conversation->organization_id !== $user->organization_id) {
            throw new InvalidArgumentException('Conversation belongs to another tenant organization.');
        }

        DB::transaction(function () use ($conversation, $lead, $user) {
            $normalizedPhone = $conversation->normalized_phone ?: PhoneNormalizer::normalize($conversation->phone);

            $conversation->update(['crm_lead_id' => $lead->id]);

            $lead->update([
                'marketing_contact_id' => $conversation->contact_id ?: $lead->marketing_contact_id,
                'normalized_phone' => $normalizedPhone,
            ]);

            Activity::create([
                'subject_type' => $lead->getMorphClass(),
                'subject_id' => $lead->getKey(),
                'type' => ActivityType::RecordUpdated,
                'description' => 'WhatsApp conversation linked to existing lead.',
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Handle Lead status change to WON: Suppress Marketing Contact while preserving opted_out status.
     */
    public function handleLeadWon(Lead $lead): void
    {
        $normalized = $lead->normalized_phone ?: PhoneNormalizer::normalize($lead->phone);

        $contact = null;
        if ($lead->marketing_contact_id) {
            $contact = MarketingContact::find($lead->marketing_contact_id);
        }

        if (! $contact && $normalized) {
            $contact = MarketingContact::query()
                ->when($lead->organization_id, fn ($q) => $q->where('organization_id', $lead->organization_id))
                ->where(function ($q) use ($normalized, $lead) {
                    $q->where('normalized_phone', $normalized)
                        ->orWhere('phone', $lead->phone);
                })
                ->first();
        }

        if ($contact) {
            app(MarketingSuppressionService::class)->suppressContact(
                contact: $contact,
                reason: 'lead_won',
                notes: "Associated CRM Lead #{$lead->id} ({$lead->company_name}) transitioned to Won status.",
                changedBy: auth()->user(),
                source: 'crm_lead'
            );
        }
    }

    /**
     * Search CRM Leads by term for linking modal.
     */
    public function searchLeads(string $term, ?int $orgId)
    {
        if (empty($term)) {
            return collect();
        }

        $clean = trim($term);
        $normalized = PhoneNormalizer::normalize($clean);

        return Lead::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where(function ($q) use ($clean, $normalized) {
                $q->where('company_name', 'like', "%{$clean}%")
                    ->orWhere('contact_name', 'like', "%{$clean}%")
                    ->orWhere('phone', 'like', "%{$clean}%")
                    ->orWhere('normalized_phone', 'like', "%{$normalized}%");
            })
            ->latest()
            ->limit(10)
            ->get();
    }
}
