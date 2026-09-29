<?php

namespace App;

enum DemoStatus: string
{
    case Requested = 'requested';
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Scheduled => 'Scheduled',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::NoShow => 'No Show',
            self::Rescheduled => 'Rescheduled',
            self::Cancelled => 'Cancelled',
        };
    }
}
