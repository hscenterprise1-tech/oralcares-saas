<?php

namespace App;

enum DemoOutcome: string
{
    case Interested = 'interested';
    case HighlyInterested = 'highly_interested';
    case NeedsFollowup = 'needs_followup';
    case TrialRequested = 'trial_requested';
    case QuotationRequested = 'quotation_requested';
    case NotInterested = 'not_interested';
    case NoDecision = 'no_decision';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Interested => 'Interested',
            self::HighlyInterested => 'Highly Interested',
            self::NeedsFollowup => 'Needs Follow-up',
            self::TrialRequested => 'Trial Requested',
            self::QuotationRequested => 'Quotation Requested',
            self::NotInterested => 'Not Interested',
            self::NoDecision => 'No Decision',
            self::Lost => 'Lost / Disqualified',
        };
    }
}
