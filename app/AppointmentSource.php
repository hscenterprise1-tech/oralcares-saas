<?php

namespace App;

/**
 * Where a booking came from. System metadata, set on the server from who
 * booked it and how; never picked on the booking form. Walk-ins are not
 * appointments: they are Reception check-ins.
 */
enum AppointmentSource: string
{
    case Reception = 'reception';
    case Doctor = 'doctor';
    case Online = 'online';
    case FollowUp = 'follow_up';
    case Recurring = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::Reception => 'Reception',
            self::Doctor => 'Doctor',
            self::Online => 'Online',
            self::FollowUp => 'Follow-up',
            self::Recurring => 'Recurring',
        };
    }
}
