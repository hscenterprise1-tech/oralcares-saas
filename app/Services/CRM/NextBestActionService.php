<?php

namespace App\Services\CRM;

use App\LeadStatus;
use App\Models\Lead;

/**
 * The single recommended next action for a lead.
 *
 * Two defects made the old output worthless in practice:
 *
 *   1. It matched on `LeadStatus::Qualified` and `LeadStatus::ProposalSent`,
 *      neither of which exists, so a fully-qualified lead past Contacted threw
 *      a fatal from LeadObserver::saving() on every write.
 *   2. A data-completeness prompt outranked every stage-aware branch and fired
 *      whenever `healthcare_specialty` — an optional field almost nobody fills
 *      — was blank. Twelve of fourteen leads showed the identical sentence
 *      "Qualify Lead: Gather Organization Profile", which is noise, not advice.
 *
 * The contract now:
 *
 *   - stage drives the recommendation; qualification prompts appear only while
 *     the lead is actually being qualified;
 *   - optional fields never generate a prompt — missing data is not a signal;
 *   - null means "no priority action", an intentional state the UI can render
 *     as empty rather than a repeated sentence.
 */
class NextBestActionService
{
    /** Days without activity before re-engagement outranks stage advice. */
    private const STALE_DAYS = 14;

    /** Stages at which qualification is genuinely the job. */
    private const QUALIFYING_STAGES = [LeadStatus::New, LeadStatus::Assigned];

    public function determineNextAction(Lead $lead): ?string
    {
        $status = $lead->status instanceof LeadStatus
            ? $lead->status
            : LeadStatus::tryFrom((string) $lead->status);

        if ($status === null) {
            return null;
        }

        if ($status === LeadStatus::Won) {
            return 'Won — hand over to onboarding.';
        }

        if ($status === LeadStatus::Lost) {
            return $lead->winback_scheduled_at
                ? null
                : 'Lost — schedule a win-back or close it out.';
        }

        // A deal nobody has touched in a fortnight needs contact more than it
        // needs whatever its stage would otherwise suggest.
        if ($this->daysSinceActivity($lead) > self::STALE_DAYS) {
            return 'No activity for over '.self::STALE_DAYS.' days — re-engage.';
        }

        // Qualification prompts belong to the qualification stages only, and
        // only for the one fact that actually decides whether a deal is worth
        // working: its size. Organisation profile and speciality are optional
        // enrichment and never produce a recommendation.
        if (in_array($status, self::QUALIFYING_STAGES, true) && $this->sizeUnknown($lead)) {
            return 'Qualify — capture expected users or monthly value.';
        }

        return match ($status) {
            LeadStatus::New => 'Make first contact.',
            LeadStatus::Assigned => 'Make first contact.',
            LeadStatus::Contacted => 'Book a demo.',
            LeadStatus::DemoScheduled => 'Run the demo.',
            LeadStatus::DemoCompleted => 'Send a quotation, or start a trial.',
            LeadStatus::TrialActive => 'Check in on trial usage.',
            LeadStatus::Negotiation => 'Agree terms and send the quotation.',
            LeadStatus::QuotationSent => 'Chase the quotation.',
            LeadStatus::QuotationApproved => 'Collect payment.',
            LeadStatus::PaymentReceived => 'Mark it won and hand over.',
            default => null,
        };
    }

    /**
     * Whether the deal's size is still unknown.
     *
     * Either signal is enough — a rep who knows the seat count does not also
     * need to have entered a monthly figure.
     */
    protected function sizeUnknown(Lead $lead): bool
    {
        return empty($lead->expected_mrr) && empty($lead->expected_users);
    }

    /**
     * Days since the last logged activity. Zero when the lead has none yet —
     * a brand-new lead is not stale.
     */
    protected function daysSinceActivity(Lead $lead): int
    {
        if (! $lead->exists) {
            return 0;
        }

        $latest = $lead->activities()->latest()->first();

        return $latest ? (int) $latest->created_at->diffInDays(now()) : 0;
    }
}
