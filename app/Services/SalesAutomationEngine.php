<?php

namespace App\Services;

use App\ActivityType;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\CRM\DealConversationService;
use App\UserType;

class SalesAutomationEngine
{
    public function __construct(protected LeadAiCopilotService $aiCopilotService) {}

    /**
     * Ingest a new lead with phone/email de-duplication and a welcome WhatsApp message.
     *
     * @return array{isDuplicate: bool, lead: Lead}
     */
    public function ingestLead(array $data, ?User $creator = null): array
    {
        $phone = $data['phone'] ?? null;
        $email = $data['email'] ?? null;

        $existing = Lead::query()
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->first();

        if ($existing) {
            $existing->activities()->create([
                'user_id' => $creator?->id,
                'type' => ActivityType::SystemUpdate,
                'description' => "Duplicate lead ingestion attempt blocked (matched existing lead #{$existing->id}).",
                'properties' => ['attempted_source' => $data['source'] ?? null],
            ]);

            return ['isDuplicate' => true, 'lead' => $existing];
        }

        $lead = Lead::create([
            'company_name' => $data['company_name'] ?? 'Unnamed Prospect',
            'contact_name' => $data['contact_name'] ?? 'Unknown',
            'email' => $email,
            'phone' => $phone,
            'source' => $data['source'] ?? 'manual_entry',
            'expected_mrr' => $data['expected_mrr'] ?? null,
            'assigned_user_id' => User::query()->where('user_type', '!=', UserType::ClinicUser)->value('id'),
        ]);

        /* Through the Marketing account, so the welcome actually arrives. */
        app(DealConversationService::class)->sendAutomated(
            $lead,
            "Welcome to OralCares! Thanks for your interest, {$lead->contact_name}. Our team will reach out shortly.",
            'welcome_v1',
        );

        $lead->activities()->create([
            'user_id' => $creator?->id,
            'type' => ActivityType::RecordCreated,
            'description' => "Lead ingested via Sales Automation Engine: {$lead->company_name}",
            'properties' => ['source' => $lead->source],
        ]);

        return ['isDuplicate' => false, 'lead' => $lead];
    }

    /**
     * Log a call disposition, auto-advancing the lead's stage.
     */
    public function logCallDisposition(Lead $lead, array $data, ?User $user = null): Lead
    {
        $disposition = $data['disposition'] ?? 'interested';

        $lead->callLogs()->create([
            'user_id' => $user?->id,
            'call_duration_seconds' => $data['duration_seconds'] ?? 0,
            'disposition' => $disposition,
            'notes' => $data['notes'] ?? null,
        ]);

        if (in_array($disposition, ['interested', 'connected'], true)) {
            $lead->update(['status' => LeadStatus::Contacted]);
        } elseif (in_array($disposition, ['demo_scheduled', 'demo_requested'], true)) {
            $lead->update(['status' => LeadStatus::DemoScheduled]);
        }

        $lead->activities()->create([
            'user_id' => $user?->id,
            'type' => ActivityType::Call,
            'description' => "Call logged — disposition: {$disposition}.",
            'properties' => ['disposition' => $disposition],
        ]);

        return $lead->fresh();
    }
}
