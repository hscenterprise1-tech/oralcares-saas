<?php

namespace App\Services\CRM;

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Trial;
use App\QuotationStatus;

/**
 * What a deal may do next, and what is stopping it.
 *
 * Two different questions live here, and conflating them is what let a won deal
 * be dragged back to Contacted:
 *
 *   LEGALITY     does this edge exist in the pipeline at all? Won is terminal;
 *                Lost is reopened deliberately or not at all; a deal in an
 *                active trial does not slide backwards. No data satisfies these
 *                — there is nothing a rep can attach to a won deal that makes
 *                regressing it correct. Enforced inside
 *                `LeadWorkspaceService::transitionStage()`, where no caller can
 *                get around it. See `blockReason()`.
 *
 *   PREREQUISITE is the evidence for this stage present? A stage named after a
 *                thing must have that thing: Contacted needs a logged contact,
 *                Demo Scheduled needs a booking somebody can attend, Won needs
 *                money. These are answerable — "not yet, and here is what to
 *                do" — so they are checked at the request boundary, where the
 *                answer can be rendered against the field that caused it, and
 *                the UI can open the form that satisfies it. See
 *                `requirementMet()` and `REQUIREMENTS`.
 *
 * The prerequisites are deliberately not re-asserted inside the service. The
 * automated callers — `scheduleDemo()`, `activateTrial()`, `approveQuotation()`
 * — create the evidence one line before they move the stage, so re-checking it
 * there buys nothing and breaks the flows that are already correct by
 * construction.
 */
class DealStageService
{
    /**
     * Preconditions per target stage.
     *
     * `check` returns true when the requirement is satisfied. `action` names
     * the thing the user must do, so the UI can offer it inline.
     *
     * @var array<string, array{requirement: string, action: string}>
     */
    public const REQUIREMENTS = [
        /*
         * A stage named after a thing must have that thing.
         *
         * `assignLead()` has always set this stage when an owner is given, but
         * nothing stopped the reverse — a plain stage transition could move a
         * deal to "Assigned" with `assigned_user_id` still null, and the list
         * then showed "Assigned" in one column and "Unassigned" in the next.
         * Bulk stage moves inherited the same hole.
         */
        /*
         * Contacted means somebody was contacted.
         *
         * This stage had no precondition at all, so a deal could be marked
         * Contacted the second it was created — and the pipeline's second
         * column, the one every conversion rate is measured from, filled with
         * deals nobody had spoken to. The evidence is an activity of a kind
         * that represents reaching a person.
         */
        'contacted' => [
            'requirement' => 'a call, WhatsApp, email or note must be logged against it first',
            'action' => 'log_contact',
        ],
        /*
         * A booked demo nobody can attend is not a booking.
         *
         * Also previously ungated. The three facts are the ones that decide
         * whether the meeting happens: when, who presents, and how to get in —
         * a join link for an online demo, an address for a visit.
         */
        'demo_scheduled' => [
            'requirement' => 'a demo must be booked with a time, a presenter and a way to join',
            'action' => 'schedule_demo',
        ],
        'quotation_sent' => [
            'requirement' => 'a quotation must exist for this deal',
            'action' => 'create_quotation',
        ],
        'demo_completed' => [
            'requirement' => 'a demo must have been scheduled',
            'action' => 'schedule_demo',
        ],
        /*
         * A trial is a plan running on a real tenant.
         *
         * The old check was `a Trial row exists`, which a draft with no package
         * and no tenant satisfies — so the stage that drives every usage
         * report could be set on a deal with nothing to measure.
         */
        'trial_active' => [
            'requirement' => 'a trial must be running on a chosen package and a provisioned clinic',
            'action' => 'start_trial',
        ],
        /*
         * The closing stages had no preconditions at all, while the Convert
         * card on the same screen demanded a settled commercial. A deal could
         * therefore be marked Won and then refuse to become a customer, and the
         * page told two stories about one deal. Won now asks for exactly what
         * Convert asks for, stated once: an approved quotation settled in full.
         */
        'quotation_approved' => [
            'requirement' => 'a quotation must be approved',
            'action' => 'approve_quotation',
        ],
        'payment_received' => [
            'requirement' => 'a payment must have settled',
            'action' => 'record_payment',
        ],
        'won' => [
            'requirement' => 'an approved quotation settled in full is needed',
            'action' => 'complete_commercials',
        ],
        'lost' => [
            'requirement' => 'a reason is required',
            'action' => 'provide_lost_reason',
        ],
    ];

    /**
     * The stage a deal naturally advances to, for the primary action.
     *
     * Terminal stages have none. Negotiation is skipped on the happy path —
     * it is a state a rep moves into deliberately, not one the product should
     * push every deal through.
     */
    public const NEXT_STAGE = [
        /*
         * Straight to Contacted, because Assigned is not a stage any more.
         * Every lead has an owner the moment it exists, so the step that used
         * to sit here was a formality the pipeline had to be walked through.
         */
        'new' => 'contacted',
        'contacted' => 'demo_scheduled',
        'demo_scheduled' => 'demo_completed',
        'demo_completed' => 'quotation_sent',
        /*
         * A finished trial goes to Negotiation, not back to quoting.
         *
         * The quotation is normally already out by the time a trial starts —
         * the flow is demo, quote, trial, negotiate — so a trial ending means
         * terms are the next conversation. This is only the suggested next
         * step; every other transition remains available, and going back to
         * revise or resend a quotation is expected rather than exceptional.
         */
        'trial_active' => 'negotiation',
        'negotiation' => 'quotation_sent',
        'quotation_sent' => 'quotation_approved',
        'quotation_approved' => 'payment_received',
        'payment_received' => 'won',
    ];

    /**
     * Probability of closing, by stage, as a percentage.
     *
     * `probability_to_close` is a real column that nothing ever wrote, so every
     * open deal carried 0 and the weighted forecast on the Overview was
     * structurally ₹0 no matter how much pipeline existed. The stage is the
     * evidence we actually have about a deal's likelihood, so the column is
     * derived from it here — one map, one writer (LeadObserver), rather than a
     * number invented at each call site.
     *
     * Terminal stages are certainties, not forecasts: Won is 100, Lost is 0.
     *
     * @var array<string, int>
     */
    public const PROBABILITY = [
        'new' => 5,
        /* Retired as a stage; kept so historical rows still score. */
        'assigned' => 10,
        'contacted' => 20,
        'demo_scheduled' => 30,
        'demo_completed' => 45,
        'trial_active' => 55,
        'negotiation' => 65,
        'quotation_sent' => 70,
        'quotation_approved' => 85,
        'payment_received' => 95,
        'won' => 100,
        'lost' => 0,
    ];

    /**
     * Activity kinds that count as having contacted a person.
     *
     * Deliberately excludes the system kinds — a stage change, a record edit or
     * a provisioning event is something the product did, and a pipeline that
     * accepted those as contact would mark every deal Contacted the moment it
     * was created.
     *
     * @var list<string>
     */
    public const CONTACT_ACTIVITY_TYPES = ['call', 'whatsapp', 'email', 'meeting', 'note', 'demo'];

    /**
     * Stages that mean the deal has not been qualified yet.
     *
     * @var list<string>
     */
    /*
     * `assigned` is retired as a destination — nothing transitions into it —
     * but rows that still carry it are intake, not qualified pipeline. Leaving
     * it out here would have every legacy row read as a worked deal.
     */
    public const UNQUALIFIED = ['new', 'assigned'];

    /**
     * Whether a lead has been qualified — i.e. someone decided it is real and
     * worth working. Contacted is the moment that happens; no new column or
     * status is needed to express it.
     */
    public function isQualified(Lead $lead): bool
    {
        return ! in_array($this->currentValue($lead), self::UNQUALIFIED, true)
            && $this->currentValue($lead) !== LeadStatus::Lost->value;
    }

    /**
     * The close probability this deal's stage implies, as a percentage.
     */
    public function probabilityFor(Lead $lead): int
    {
        return self::PROBABILITY[$this->currentValue($lead)] ?? 0;
    }

    /**
     * Whether a lead was closed out without ever being qualified.
     */
    public function isDisqualified(Lead $lead): bool
    {
        return $this->currentValue($lead) === LeadStatus::Lost->value
            && $lead->qualified_at === null;
    }

    /**
     * The pipeline in order, so "forwards" and "backwards" mean something.
     *
     * Lost is deliberately absent: it is not a point on the road, it is leaving
     * it. A deal can be lost from anywhere, and rank comparisons against it
     * would be meaningless.
     *
     * @var array<string, int>
     */
    public const RANK = [
        'new' => 0,
        'contacted' => 1,
        'demo_scheduled' => 2,
        'demo_completed' => 3,
        'trial_active' => 4,
        'negotiation' => 5,
        'quotation_sent' => 6,
        'quotation_approved' => 7,
        'payment_received' => 8,
        'won' => 9,
    ];

    /**
     * Stages a deal does not move out of by ordinary means.
     *
     * Won is absolute: the deal is an active customer with a provisioned
     * tenant, and a stage change would desynchronise the pipeline from the
     * thing it is meant to describe. Lost is recoverable, but only through
     * `reopen()`, which asks why — a deal that quietly un-loses itself takes
     * its loss reason and its win-back date with it, and the loss report it was
     * counted in silently changes shape.
     *
     * @var list<string>
     */
    public const TERMINAL = ['won', 'lost'];

    /**
     * Why this deal may not move to that stage, or null if it may.
     *
     * Legality only — this answers "does the edge exist", never "is the
     * evidence present". A null here means the move is structurally fine and
     * `requirementMet()` gets the next word.
     */
    public function blockReason(Lead $lead, string $target): ?string
    {
        $current = $this->currentValue($lead);

        if ($current === $target) {
            return null;
        }

        /*
         * Won is the end of the pipeline, not a stage in it.
         *
         * Previously `availableTransitions()` offered New, Contacted, Demo
         * Scheduled and Negotiation on a won deal, and the endpoint accepted
         * all four, because none of those stages had a precondition and nothing
         * anywhere compared the current stage to the target.
         */
        /*
         * A retired stage is not a destination.
         *
         * Legacy rows still sitting on one can move out of it — they are read
         * as intake — but nothing goes back in.
         */
        if (in_array($target, LeadStatus::RETIRED, true)) {
            return LeadStatus::from($target)->label().' is no longer a pipeline stage.';
        }

        if ($current === LeadStatus::Won->value) {
            return 'This deal is won and the customer is live. It cannot be moved back into the pipeline.';
        }

        if ($current === LeadStatus::Lost->value) {
            return 'This deal is lost. Reopen it with a reason before moving it again.';
        }

        /*
         * A running trial does not slide backwards.
         *
         * The clinic is using the product. Moving the deal back to Contacted or
         * Demo Scheduled would say the evaluation had not started, while the
         * telemetry on the same page counts patients being seen in it.
         */
        if ($current === LeadStatus::TrialActive->value
            && isset(self::RANK[$target])
            && self::RANK[$target] < self::RANK[$current]) {
            return 'A trial is running. Finish or cancel it before moving this deal back.';
        }

        /*
         * Qualification does not un-happen.
         *
         * Intake is where a deal waits to be judged real. Once somebody has
         * judged it, sending it back there hides it from the working queues and
         * from every conversion rate measured out of them.
         */
        if ($lead->qualified_at !== null && in_array($target, self::UNQUALIFIED, true)) {
            return 'This deal has already been qualified. It cannot go back to intake.';
        }

        return null;
    }

    /** Whether the pipeline permits this edge at all. */
    public function transitionAllowed(Lead $lead, string $target): bool
    {
        return $this->blockReason($lead, $target) === null;
    }

    /**
     * Whether the requirement for a target stage is satisfied.
     */
    public function requirementMet(Lead $lead, string $stage, ?string $reason = null): bool
    {
        return match ($stage) {

            /*
             * Somebody reached out, and it is on the record.
             *
             * Any activity that represents contact with a person counts — a
             * call disposition, a WhatsApp message, an email, a meeting, or a
             * note written after speaking to them. System rows (stage changes,
             * record edits, provisioning) deliberately do not: they are things
             * the product did, not things a rep did.
             */
            'contacted' => $lead->activities()
                ->whereIn('type', self::CONTACT_ACTIVITY_TYPES)
                ->exists(),

            /*
             * A time, a presenter, and a way in.
             *
             * `meeting_link` for an online demo or `location` for a visit —
             * one or the other, never neither, or the booking is invisible
             * until the morning of and nobody can attend it.
             */
            'demo_scheduled' => Demo::where('lead_id', $lead->id)
                ->whereNotNull('scheduled_at')
                ->whereNotNull('presenter_id')
                ->where(fn ($q) => $q->whereNotNull('meeting_link')->orWhereNotNull('location'))
                ->exists(),
            'quotation_sent' => CompanyQuotation::where('lead_id', $lead->id)->exists(),
            'demo_completed' => Demo::where('lead_id', $lead->id)->exists(),
            /* A chosen package, running on a real tenant. */
            'trial_active' => Trial::where('lead_id', $lead->id)
                ->whereNotNull('company_plan_id')
                ->whereNotNull('clinic_id')
                ->exists(),
            'quotation_approved' => CompanyQuotation::where('lead_id', $lead->id)
                ->where('status', QuotationStatus::Approved->value)
                ->exists(),
            'payment_received' => $lead->hasSuccessfulPayment(),

            /*
             * The same two facts the conversion gate reads, so a deal is never
             * closable in one place and blocked in the other.
             *
             * Deals already sitting on Won keep their stage: this gates the
             * transition into Won, and nothing re-checks a row that is already
             * there. Historical records are left exactly as they are.
             */
            /*
             * Approved and settled in full. The legacy check stays beside it so
             * a deal closed under the old agreement flow can still be moved —
             * those records are real and re-gating them would strand them.
             */
            'won' => $lead->hasApprovedPaidQuotation()
                || ($lead->hasAcceptedAgreement() && $lead->hasSuccessfulPayment()),
            'lost' => filled($reason) || filled($lead->lost_reason),
            default => true,
        };
    }

    /**
     * Every stage this deal could be moved to, with whether it is currently
     * possible and what is missing when it is not.
     *
     * All twelve statuses are offered — the board could only ever express
     * seven, because each column wrote one hard-coded default.
     *
     * @return list<array{value:string, label:string, allowed:bool, requirement:?string, action:?string, is_current:bool}>
     */
    public function availableTransitions(Lead $lead): array
    {
        $current = $this->currentValue($lead);

        return collect(LeadStatus::cases())
            /*
             * A retired stage is absent from the rail entirely — not shown
             * blocked. A deal still sitting on one keeps it as its current
             * stage; it simply has no step of its own to return to.
             */
            ->reject(fn (LeadStatus $status) => $status->isRetired() && $status->value !== $current)
            ->map(function (LeadStatus $status) use ($lead, $current) {
                $isCurrent = $status->value === $current;

                /*
                 * Legality first, and it is final.
                 *
                 * A blocked edge reports no action, because there is nothing
                 * the user could do to unblock it — offering "assign an owner"
                 * beside a won deal would invite somebody to try.
                 */
                $blocked = $isCurrent ? null : $this->blockReason($lead, $status->value);

                if ($blocked !== null) {
                    return [
                        'value' => $status->value,
                        'label' => $status->label(),
                        'is_current' => false,
                        'allowed' => false,
                        'blocked' => true,
                        'requirement' => $blocked,
                        'action' => null,
                    ];
                }

                $met = $this->requirementMet($lead, $status->value);
                $spec = self::REQUIREMENTS[$status->value] ?? null;

                return [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'is_current' => $isCurrent,
                    'allowed' => $met && ! $isCurrent,
                    'blocked' => false,
                    /*
                     * `$spec` can legitimately be absent — Negotiation asks for
                     * nothing — and indexing it unconditionally was a fatal
                     * waiting for the first ungated stage to grow a check.
                     */
                    'requirement' => $met ? null : ($spec['requirement'] ?? null),
                    'action' => $met ? null : ($spec['action'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The contextual primary action: the stage this deal advances to next.
     *
     * @return array{value:string, label:string, allowed:bool, requirement:?string, action:?string}|null
     */
    public function nextStage(Lead $lead): ?array
    {
        $next = self::NEXT_STAGE[$this->currentValue($lead)] ?? null;

        if ($next === null) {
            return null;
        }

        $status = LeadStatus::from($next);
        $blocked = $this->blockReason($lead, $next);
        $met = $blocked === null && $this->requirementMet($lead, $next);
        $spec = self::REQUIREMENTS[$next] ?? null;

        return [
            'value' => $next,
            'label' => $status->label(),
            'allowed' => $met,
            'requirement' => $blocked ?? ($met ? null : ($spec['requirement'] ?? null)),
            'action' => $blocked !== null || $met ? null : ($spec['action'] ?? null),
        ];
    }

    protected function currentValue(Lead $lead): string
    {
        return $lead->status instanceof LeadStatus
            ? $lead->status->value
            : (string) $lead->status;
    }
}
