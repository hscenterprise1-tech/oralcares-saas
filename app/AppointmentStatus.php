<?php

namespace App;

/**
 * The 7-value set `AppointmentController::updateStatus()` already validated
 * against before this enum existed — codified here rather than changed,
 * since that's the set actually enforced in production today. (The
 * `create_reception_foundation_tables` migration's column comment lists a
 * narrower 5-value set that was never actually enforced anywhere; this enum
 * follows the controller, not the stale comment.)
 */
enum AppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Arrived = 'arrived';
    case CheckedIn = 'checked_in';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
