<?php

namespace App;

enum TrialOutcome: string
{
    case HighlyInterested = 'highly_interested';
    case Interested = 'interested';
    case NeedsFollowup = 'needs_followup';
    case QuotationRequested = 'quotation_requested';
    case NeedsExtension = 'needs_extension';
    case NotInterested = 'not_interested';
    case NoDecision = 'no_decision';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::HighlyInterested => 'Highly Interested',
            self::Interested => 'Interested',
            self::NeedsFollowup => 'Needs Follow-up',
            self::QuotationRequested => 'Quotation Requested',
            self::NeedsExtension => 'Extension Needed',
            self::NotInterested => 'Not Interested',
            self::NoDecision => 'No Decision',
            self::Lost => 'Lost',
        };
    }
}
