<?php

namespace App;

enum ClinicMembershipStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
