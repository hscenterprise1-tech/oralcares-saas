<?php

namespace App;

/**
 * How much of a day one leave day takes.
 *
 * A half day counts 0.5 — that is what "half" means, not a policy choice.
 *
 * A part day deliberately counts **zero days** and records hours instead.
 * Turning hours into a fraction of a day needs a standard working day, and
 * nothing in this system establishes one; inventing eight would quietly set
 * company policy from a constant in an enum.
 */
enum LeaveDayPortion: string
{
    case Full = 'full';
    case Half = 'half';
    case Part = 'part';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full day',
            self::Half => 'Half day',
            self::Part => 'Part day',
        };
    }

    /** The day value this portion contributes to a balance. */
    public function dayValue(): float
    {
        return match ($this) {
            self::Full => 1.0,
            self::Half => 0.5,
            self::Part => 0.0,
        };
    }
}
