<?php

namespace App;

enum DemoConfirmationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case NoResponse = 'no_response';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Confirmation',
            self::Confirmed => 'Client Confirmed',
            self::Declined => 'Declined',
            self::NoResponse => 'No Response',
        };
    }
}
