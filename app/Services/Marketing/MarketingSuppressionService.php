<?php

namespace App\Services\Marketing;

use App\ActivityType;
use App\LeadStatus;
use App\Models\Activity;
use App\Models\MarketingContact;
use App\Models\MarketingContactSuppressionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MarketingSuppressionService
{
    /**
     * Suppress a contact while respecting Opt-Out priority.
     */
    public function suppressContact(
        MarketingContact $contact,
        string $reason = 'manual_suppression',
        ?string $notes = null,
        User|int|null $changedBy = null,
        string $source = 'user_action'
    ): MarketingContact {
        return DB::transaction(function () use ($contact, $reason, $notes, $changedBy, $source) {
            $userId = $changedBy instanceof User ? $changedBy->id : $changedBy;
            $oldStatus = $contact->marketing_status;

            // RULE: Opt-Out has higher priority than suppression!
            // If contact is already opted_out and a system/lead_won event fires, preserve opted_out.
            if ($oldStatus === 'opted_out' && in_array($reason, ['lead_won', 'manual_suppression'])) {
                // Do not demote opted_out to suppressed. Log audit note if status was unchanged.
                $this->logStatusChange(
                    contact: $contact,
                    oldStatus: $oldStatus,
                    newStatus: 'opted_out',
                    reason: $reason,
                    source: $source,
                    changedByUserId: $userId,
                    notes: ($notes ? $notes.' | ' : '').'Opt-Out priority preserved. Status remained opted_out.'
                );

                return $contact;
            }

            $newStatus = 'suppressed';
            $now = now();

            $contact->update([
                'marketing_status' => $newStatus,
                'suppression_reason' => $reason,
                'suppression_source' => $source,
                'status_changed_at' => $now,
            ]);

            $this->logStatusChange(
                contact: $contact,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                reason: $reason,
                source: $source,
                changedByUserId: $userId,
                notes: $notes
            );

            // Log CRM activity if contact has associated leads
            $lead = $contact->crmLeads()->latest()->first();
            if ($lead) {
                Activity::create([
                    'subject_type' => $lead->getMorphClass(),
                    'subject_id' => $lead->getKey(),
                    'type' => ActivityType::SystemUpdate,
                    'description' => "Marketing contact eligibility updated to Suppressed (Reason: {$reason}).",
                    'user_id' => $userId,
                ]);
            }

            return $contact;
        });
    }

    /**
     * Unsuppress a contact to restore active marketing eligibility.
     */
    public function unsuppressContact(
        MarketingContact $contact,
        string $reason = 'manual_unsuppress',
        ?string $notes = null,
        User|int|null $changedBy = null,
        string $source = 'user_action'
    ): MarketingContact {
        return DB::transaction(function () use ($contact, $reason, $notes, $changedBy, $source) {
            $userId = $changedBy instanceof User ? $changedBy->id : $changedBy;
            $oldStatus = $contact->marketing_status;

            // Business Rule: Prevent manual unsuppression if contact has an active Won CRM lead unless authorized
            $hasWonLead = $contact->crmLeads()->where('status', LeadStatus::Won)->exists();
            if ($hasWonLead && $reason !== 'authorized_override') {
                throw new InvalidArgumentException('Cannot unsuppress contact with a Won CRM Lead without explicit authorized override.');
            }

            $newStatus = 'active';
            $now = now();

            $contact->update([
                'marketing_status' => $newStatus,
                'suppression_reason' => null,
                'suppression_source' => null,
                'status_changed_at' => $now,
            ]);

            $this->logStatusChange(
                contact: $contact,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                reason: $reason,
                source: $source,
                changedByUserId: $userId,
                notes: $notes
            );

            return $contact;
        });
    }

    /**
     * Mark a contact as Opted-Out (Highest Priority Status).
     */
    public function optOutContact(
        MarketingContact $contact,
        string $reason = 'customer_request',
        ?string $notes = null,
        User|int|null $changedBy = null,
        string $source = 'user_action'
    ): MarketingContact {
        return DB::transaction(function () use ($contact, $reason, $notes, $changedBy, $source) {
            $userId = $changedBy instanceof User ? $changedBy->id : $changedBy;
            $oldStatus = $contact->marketing_status;
            $newStatus = 'opted_out';
            $now = now();

            $contact->update([
                'marketing_status' => $newStatus,
                'suppression_reason' => $reason,
                'suppression_source' => $source,
                'status_changed_at' => $now,
            ]);

            $this->logStatusChange(
                contact: $contact,
                oldStatus: $oldStatus,
                newStatus: $newStatus,
                reason: $reason,
                source: $source,
                changedByUserId: $userId,
                notes: $notes
            );

            return $contact;
        });
    }

    /**
     * Log a compliance status change entry to single audit log table.
     */
    protected function logStatusChange(
        MarketingContact $contact,
        ?string $oldStatus,
        string $newStatus,
        string $reason,
        string $source,
        ?int $changedByUserId,
        ?string $notes
    ): MarketingContactSuppressionLog {
        return MarketingContactSuppressionLog::create([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'source' => $source,
            'changed_by_user_id' => $changedByUserId,
            'notes' => $notes,
        ]);
    }

    /**
     * Get tenant-scoped compliance & suppression safety metrics.
     */
    public function getComplianceSummary(?int $orgId = null): array
    {
        $contacts = MarketingContact::query()->when($orgId, fn ($q) => $q->where('organization_id', $orgId));

        return [
            'total_contacts' => (clone $contacts)->count(),
            'active_contacts' => (clone $contacts)->where('marketing_status', 'active')->count(),
            'opted_out_contacts' => (clone $contacts)->where('marketing_status', 'opted_out')->count(),
            'suppressed_contacts' => (clone $contacts)->where('marketing_status', 'suppressed')->count(),
            'lead_won_suppressions' => (clone $contacts)->where('suppression_reason', 'lead_won')->count(),
            'manual_suppressions' => (clone $contacts)->where('suppression_reason', 'manual_suppression')->count(),
            'invalid_blocked_contacts' => (clone $contacts)->whereIn('suppression_reason', ['invalid_number', 'whatsapp_blocked'])->count(),
        ];
    }
}
