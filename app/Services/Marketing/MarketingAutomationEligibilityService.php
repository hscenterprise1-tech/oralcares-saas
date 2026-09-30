<?php

namespace App\Services\Marketing;

use App\LeadStatus;
use App\Models\Lead;
use App\Models\MarketingAutomation;
use App\Models\MarketingAutomationExecution;
use App\Models\MarketingContact;
use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;

class MarketingAutomationEligibilityService
{
    /**
     * Re-verify contact and execution safety right before executing an automated action.
     *
     * @return array ['eligible' => bool, 'reason' => string|null]
     */
    public function checkEligibility(
        MarketingAutomationExecution $execution,
        ?MarketingAutomation $automation = null,
        ?MarketingContact $contact = null
    ): array {
        $automation = $automation ?: $execution->automation;

        // 0. Check Tenant Governance Settings (Emergency Freeze & Quiet Hours)
        $settingsService = app(MarketingSettingsService::class);
        $orgId = $execution->organization_id;

        if ($orgId && $settingsService->isEmergencyFreezeActive($orgId)) {
            return [
                'eligible' => false,
                'reason' => 'emergency_freeze_active',
            ];
        }

        if ($orgId && $settingsService->isInQuietHours($orgId)) {
            return [
                'eligible' => false,
                'reason' => 'quiet_hours_active',
            ];
        }

        // 1. Verify Automation status
        if (! $automation || $automation->status !== 'active') {
            return [
                'eligible' => false,
                'reason' => 'automation_not_active',
            ];
        }

        // 2. Verify Execution status
        if (in_array($execution->status, ['stopped', 'completed', 'failed', 'cancelled'], true)) {
            return [
                'eligible' => false,
                'reason' => "execution_already_{$execution->status}",
            ];
        }

        // 3. Resolve & Verify Contact
        $contact = $contact ?: ($execution->contact ?: ($execution->contact_id ? MarketingContact::find($execution->contact_id) : null));
        if ($contact) {
            // Tenant Check
            if ($contact->organization_id !== $execution->organization_id) {
                return [
                    'eligible' => false,
                    'reason' => 'tenant_mismatch',
                ];
            }

            // Opt-out / suppression protection — reads the same constant
            // SendCampaignMessageJob and Conversations' reply guard read, so
            // this can never drift from the one place that rule lives.
            if (in_array($contact->marketing_status, MarketingContact::COMPLIANCE_STATUSES, true)) {
                return [
                    'eligible' => false,
                    'reason' => $contact->marketing_status,
                ];
            }

            // Associated CRM Lead Won Check (Section 18)
            $activeLead = $execution->lead ?: ($contact->crmLeads()->latest()->first());
            if ($activeLead && $activeLead->status === LeadStatus::Won) {
                return [
                    'eligible' => false,
                    'reason' => 'lead_won',
                ];
            }
        }

        return [
            'eligible' => true,
            'reason' => null,
        ];
    }

    /**
     * Check if a specific WhatsApp Template Action is eligible for dispatch.
     */
    public function checkTemplateActionEligibility(
        MarketingAutomationExecution $execution,
        int $templateId
    ): array {
        $generalCheck = $this->checkEligibility($execution);
        if (! $generalCheck['eligible']) {
            return $generalCheck;
        }

        $template = MarketingWhatsAppTemplate::where('id', $templateId)
            ->where('organization_id', $execution->organization_id)
            ->first();

        if (! $template) {
            return [
                'eligible' => false,
                'reason' => 'template_not_found',
            ];
        }

        if (! $template->isApproved()) {
            return [
                'eligible' => false,
                'reason' => "template_not_approved_{$template->status}",
            ];
        }

        $account = MarketingWhatsAppAccount::where('id', $template->whatsapp_account_id)
            ->where('organization_id', $execution->organization_id)
            ->first();

        if (! $account || $account->status !== 'connected') {
            return [
                'eligible' => false,
                'reason' => 'whatsapp_account_disconnected',
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
        ];
    }
}
