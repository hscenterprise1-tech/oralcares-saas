<?php

namespace App;

/**
 * Whether a component adds to pay or subtracts from it.
 *
 * There is deliberately no statutory type here. PF, TDS, ESI and PT are
 * deductions a business configures; none of them is a rule this application
 * knows.
 */
enum CompensationComponentType: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::Deduction => 'Deduction',
        };
    }
}
