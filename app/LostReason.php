<?php

namespace App;

enum LostReason: string
{
    case Price = 'Price';
    case Competitor = 'Competitor';
    case NoAuthority = 'No Authority';
    case Timing = 'Timing';
    case FeatureMissing = 'Feature Missing';
    case Unresponsive = 'Unresponsive';
    case Other = 'Other';
}
