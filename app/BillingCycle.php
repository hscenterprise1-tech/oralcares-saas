<?php

namespace App;

/**
 * The tenure a quotation's `grand_total` is priced for.
 *
 * `annualFactor()` is what turns a cycle amount into a normalised ARR figure
 * comparable across quotations quoted at different tenures — a ₹12,000
 * quarterly quote and a ₹4,000 monthly quote both describe a ₹48,000/year
 * relationship, and nothing upstream should have to know the arithmetic to
 * see that.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Annual = 'annual';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::HalfYearly => 'Half-yearly',
            self::Annual => 'Annual',
        };
    }

    /** The short form the quotation pill and pipeline table render. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Monthly => '1M',
            self::Quarterly => '3M',
            self::HalfYearly => '6M',
            self::Annual => '1Y',
        };
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::HalfYearly => 6,
            self::Annual => 12,
        };
    }

    /** Multiply a cycle amount by this to normalise it to an annual figure. */
    public function annualFactor(): float
    {
        return 12 / $this->months();
    }
}
