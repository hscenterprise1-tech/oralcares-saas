<?php

namespace App;

enum SubscriptionStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Renewed = 'renewed';
    case Converted = 'converted';
}
