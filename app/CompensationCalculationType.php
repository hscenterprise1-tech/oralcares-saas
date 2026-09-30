<?php

namespace App;

/**
 * How a component's stored value is read.
 *
 * A fixed amount, or a percentage of the base salary. The percentage basis is
 * always the base — no component is computed on top of another, because
 * cascading bases is a business rule nobody has stated.
 */
enum CompensationCalculationType: string
{
    case Fixed = 'fixed';
    case PercentageOfBase = 'percentage_of_base';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::PercentageOfBase => 'Percentage of base',
        };
    }
}
