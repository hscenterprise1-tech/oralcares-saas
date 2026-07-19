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
}
