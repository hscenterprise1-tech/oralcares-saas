<?php

namespace App;

enum TrialStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Extended = 'extended';
    case Expired = 'expired';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
}
