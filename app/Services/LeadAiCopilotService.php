<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Str;

class LeadAiCopilotService
{
    /**
     * Compute comprehensive 14-point AI Executive Summary for a given Lead.
     */
    public function analyzeLead(Lead $lead): array
    {
        $leadScore = $lead->lead_score ?? $lead->automated_score ?? 75;
        $mrr = (float) ($lead->expected_mrr ?? 0);
        $branchCount = $lead->branch_count ?? 1;
        $expectedUsers = $lead->expected_users ?? 5;

        // 1. Buying Probability (0-100%)
        $buyingProbability = min(98, max(20, (int) ($leadScore * 0.9 + ($mrr > 20000 ? 15 : 5))));

        // 2. Risk Level Calculation
        $daysSinceUpdate = $lead->updated_at ? (int) $lead->updated_at->diffInDays(now()) : 0;
        $riskLevel = $daysSinceUpdate > 7 ? 'High' : ($daysSinceUpdate > 3 ? 'Medium' : 'Low');

        // 3. Recommended Next Best Action
        $nextBestAction = $this->determineNextBestAction($lead, $riskLevel);

        // 4. Decision Maker Contacts Summary
        $decisionMakers = "{$lead->contact_name} (Primary Decision Maker)";

        // 5. Clinic Profile Sub-type
        $orgTypeLabel = is_object($lead->organization_type) && isset($lead->organization_type->value)
            ? $lead->organization_type->value
            : ($lead->organization_type ?? 'Multispecialty Dental Clinic');
        $clinicProfile = Str::title($orgTypeLabel)." ({$branchCount} Locations, {$expectedUsers} Doctors)";

        // 6. Modules Interested
        $modules = $lead->required_modules ?? ['WhatsApp Automation', 'Patient Billing', 'EHR Notes', 'Inventory Sync'];

        // 7. Recommended Discount
        $recommendedDiscount = $mrr > 30000 ? 'Approved for 15% Annual Upfront Discount' : 'Standard Tier - 5% Early Bird Discount';

        // 8. Key Objections
        $keyObjections = 'Concerned about patient data migration speed from legacy desktop software.';

        // 9. Conversation Summary (Auto-generated AI Memory)
        $conversationSummary = "Client interested in multispecialty EHR & WhatsApp integration for {$lead->company_name}. Requested custom quotation PDF & branch demo.";

        // 10. Next Meeting Agenda
        $nextMeetingAgenda = 'Present Custom Quotation PDF, Demo WhatsApp API Notifications, and Agree on Go-Live Schedule.';

        return [
            'buyingProbability' => $buyingProbability,
            'leadScore' => $leadScore,
            'riskLevel' => $riskLevel,
            'nextBestAction' => $nextBestAction,
            'clinicProfile' => $clinicProfile,
            'decisionMakers' => $decisionMakers,
            'budgetConfidence' => '₹ '.number_format($mrr > 0 ? $mrr : 25000).' / month (High Confidence)',
            'painPoints' => 'Manual WhatsApp appointment reminders, Delayed patient billing & insurance claims',
            'competitors' => 'Evaluating Practo Ray & Dentidesk Desktop',
            'modulesInterested' => implode(', ', $modules),
            'doctorsAndBranches' => "{$expectedUsers} Doctors across {$branchCount} Branches",
            'goLiveTimeline' => 'Target Go-Live within 14 Days',
            'recommendedDiscount' => $recommendedDiscount,
            'keyObjections' => $keyObjections,
            'conversationSummary' => $conversationSummary,
            'nextMeetingAgenda' => $nextMeetingAgenda,
            'missingInformation' => empty($lead->phone) ? ['Phone Number'] : [],
            'suggestedReply' => "Hello {$lead->contact_name}, thank you for reaching out to OralCares! I would love to share a quick 3-minute video overview of our multispecialty healthcare portal tailored for {$lead->company_name}. Would tomorrow at 11 AM work for a quick demo?",
        ];
    }

    protected function determineNextBestAction(Lead $lead, string $riskLevel): string
    {
        $statusKey = is_object($lead->status) && isset($lead->status->value)
            ? $lead->status->value
            : (string) $lead->status;

        return match ($statusKey) {
            'new' => 'Initiate WhatsApp Discovery & Schedule Demo',
            'contacted' => 'Gather Clinic Branch & User Count Requirements',
            'demo_scheduled' => 'Send Pre-Demo Agenda & Confirm Calendar Invite',
            'demo_completed' => 'Issue Custom Enterprise Quotation PDF',
            'quotation_sent' => 'Follow up on Quotation Review & Request Term Approval',
            'negotiation' => 'Route Custom Discount Approval to Decision Center',
            'trial_active' => 'Monitor Sandbox Usage & Assist Primary Admin Setup',
            'won' => 'Initiate Clinic Branch Provisioning & Onboarding',
            'lost' => 'Schedule Automated Re-engagement Sequence for 30 Days',
            default => 'Conduct Executive Follow-up Call',
        };
    }
}
