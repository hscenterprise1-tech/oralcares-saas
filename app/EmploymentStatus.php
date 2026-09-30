<?php

namespace App;

enum EmploymentStatus: string
{
    case PendingInvitation = 'pending_invitation';
    case Invited = 'invited';
    case Verified = 'verified';
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
    case Resigned = 'resigned';

    /**
     * Staff statuses that end the person's access to the clinic.
     *
     * @return list<string>
     */
    public static function accessEnding(): array
    {
        return [self::Inactive->value, self::Suspended->value, self::Resigned->value];
    }
}
