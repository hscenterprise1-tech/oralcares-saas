<?php

namespace App;

/**
 * What a person's day was.
 *
 * `Holiday` and `WeeklyOff` come from the working calendar rather than from a
 * rule encoded here — an unconfigured calendar produces neither, which is
 * correct: nothing in this system says which days a company works.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Partial = 'partial';
    case OnLeave = 'on_leave';
    case Holiday = 'holiday';
    case WeeklyOff = 'weekly_off';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Absent => 'Absent',
            self::Partial => 'Partial',
            self::OnLeave => 'On leave',
            self::Holiday => 'Holiday',
            self::WeeklyOff => 'Weekly off',
        };
    }

    /** Whether this day was expected to be worked at all. */
    public function isWorkingDay(): bool
    {
        return $this !== self::Holiday && $this !== self::WeeklyOff;
    }

    /** Whether the person actually turned up, in whole or in part. */
    public function counts(): bool
    {
        return $this === self::Present || $this === self::Partial;
    }
}
