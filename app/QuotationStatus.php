<?php

namespace App;

enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Negotiation = 'negotiation';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Converted = 'converted';
    /*
     * Retired by a revision, not by anything that happened to the deal.
     * `reviseQuotation()` sets this on the version a new one replaces — the
     * quote itself was never sent, rejected, or allowed to lapse, it was
     * simply superseded by a newer number on the same deal.
     */
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Negotiation => 'In Negotiation',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Converted => 'Converted (Won)',
            self::Superseded => 'Superseded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'slate',
            self::Sent => 'indigo',
            self::Negotiation => 'amber',
            self::Approved => 'emerald',
            self::Rejected => 'rose',
            self::Expired => 'gray',
            self::Converted => 'emerald',
            self::Superseded => 'gray',
        };
    }

    /**
     * Whether a quotation at this status is the one still being worked —
     * as opposed to retired by a revision, a rejection, expiry, or having
     * already won the deal. A deal has at most one active quotation.
     */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Superseded, self::Rejected, self::Expired, self::Converted], true);
    }
}
