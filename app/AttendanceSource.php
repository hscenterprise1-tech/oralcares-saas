<?php

namespace App;

/**
 * Who put this attendance record there.
 *
 * Kept because a day the employee logged and a day HR entered on their behalf
 * are different facts, and a correction trail that cannot tell them apart is
 * not much of a trail.
 */
enum AttendanceSource: string
{
    case Employee = 'employee';
    case Manager = 'manager';
    case Hr = 'hr';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::Manager => 'Manager',
            self::Hr => 'HR',
            self::System => 'System',
        };
    }
}
