<?php

namespace App;

enum LeadStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case Contacted = 'contacted';
    case DemoScheduled = 'demo_scheduled';
    case DemoCompleted = 'demo_completed';
    case TrialActive = 'trial_active';
    case Negotiation = 'negotiation';
    case QuotationSent = 'quotation_sent';
    case QuotationApproved = 'quotation_approved';
    case PaymentReceived = 'payment_received';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * Stages that are no longer part of the pipeline.
     *
     * `assigned` was a stage that meant "somebody owns this", which is now true
     * of every lead the moment it is created — see `LeadAssignmentService`. As a
     * stage it did nothing but sit between New and Contacted collecting deals
     * that had an owner and no conversation, and it made the first conversion
     * rate in the funnel measure paperwork rather than selling.
     *
     * The case is kept so historical rows, activity payloads and
     * `stage_durations` keys still hydrate. Nothing may transition *into* it —
     * `DealStageService::blockReason()` refuses — and it is absent from the
     * stepper, the queues and the rail.
     *
     * @var list<string>
     */
    public const RETIRED = ['assigned'];

    /** Whether this stage is still part of the working pipeline. */
    public function isRetired(): bool
    {
        return in_array($this->value, self::RETIRED, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            /* Retired — see `RETIRED`. Labelled for historical rows only. */
            self::Assigned => 'Assigned',
            self::Contacted => 'Contacted',
            self::DemoScheduled => 'Demo Scheduled',
            self::DemoCompleted => 'Demo Completed',
            self::TrialActive => 'Trial Active',
            self::Negotiation => 'Negotiation',
            self::QuotationSent => 'Quotation Sent',
            self::QuotationApproved => 'Quotation Approved',
            self::PaymentReceived => 'Payment Received',
            self::Won => 'Won',
            self::Lost => 'Lost',
        };
    }
}
