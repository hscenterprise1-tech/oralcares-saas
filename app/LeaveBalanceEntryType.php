<?php

namespace App;

/**
 * Why a balance changed.
 *
 * Every movement is one of these rows, which is what makes accrual and
 * carry-forward a foundation rather than a guess: no rate, frequency or cap is
 * encoded anywhere, and when one is eventually decided it writes entries here
 * without disturbing the balances that came before it.
 */
enum LeaveBalanceEntryType: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case CarryForward = 'carry_forward';
    case Adjustment = 'adjustment';
    case Consumption = 'consumption';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Accrual => 'Accrued',
            self::CarryForward => 'Carried forward',
            self::Adjustment => 'Adjustment',
            self::Consumption => 'Taken',
            self::Reversal => 'Returned',
        };
    }

    /** Which balance column this entry rolls up into. */
    public function column(): string
    {
        return match ($this) {
            self::Opening => 'opening_balance',
            self::Accrual => 'accrued',
            self::CarryForward => 'carried_forward',
            self::Adjustment => 'adjusted',
            self::Consumption, self::Reversal => 'used',
        };
    }

    /**
     * Whether the entry is recorded against `used` rather than an entitlement.
     *
     * Consumption adds to `used`; a reversal takes back off it.
     */
    public function affectsUsed(): bool
    {
        return $this === self::Consumption || $this === self::Reversal;
    }
}
