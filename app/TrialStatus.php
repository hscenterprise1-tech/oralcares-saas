<?php

namespace App;

/**
 * Where a trial is in its life.
 *
 * Three corrections from the audit, all of them about the enum describing
 * something real:
 *
 *   - `requested` is gone. Nothing assigned it and no row held it, so it was a
 *     state the product could describe but never reach.
 *   - `converted` is now `quotation_requested`, which is what `completeTrial()`
 *     actually means when it sets it — the customer asked for a price. That is
 *     a good sign, not a sale. Conversion happens at Won, gated on an accepted
 *     agreement and a settled payment, and every report counting "converted
 *     trials" was counting the wrong thing.
 *   - `in_grace` is new: the seven days after the end date in which the
 *     customer keeps full access. The phase existed in the brief and nowhere in
 *     the code, so nothing could list, filter or act on it.
 *
 * `ending_soon` and `cancelled` are kept and get writers in later phases —
 * today both have readers and none.
 */
enum TrialStatus: string
{
    case Preparing = 'preparing';
    case Active = 'active';
    case EndingSoon = 'ending_soon';
    case Extended = 'extended';
    case InGrace = 'in_grace';
    case Completed = 'completed';
    case QuotationRequested = 'quotation_requested';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => 'Preparing Setup',
            self::Active => 'Active Trial',
            self::EndingSoon => 'Ending Soon',
            self::Extended => 'Trial Extended',
            self::InGrace => 'In Grace Period',
            self::Completed => 'Trial Completed',
            self::QuotationRequested => 'Quotation Requested',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Preparing => 'purple',
            self::Active => 'green',
            self::EndingSoon => 'orange',
            self::Extended => 'teal',
            self::InGrace => 'amber',
            self::Completed => 'emerald',
            self::QuotationRequested => 'indigo',
            self::Cancelled => 'slate',
            self::Expired => 'red',
        };
    }

    /**
     * Statuses where the trial is still running and the tenant has access.
     *
     * Grace counts: full access is the whole point of it. Collected here
     * because the audit found the same list spelled three different ways
     * across the metrics service, the scope helpers and the workspace payload.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Active, self::EndingSoon, self::Extended, self::InGrace];
    }

    /** Whether the tenant behind this trial should still be able to log in. */
    public function grantsAccess(): bool
    {
        return in_array($this, self::live(), true);
    }
}
