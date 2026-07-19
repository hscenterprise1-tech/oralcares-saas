<?php

namespace App;

enum LabCaseStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Accepted = 'accepted';
    case InProduction = 'in_production';
    case QualityCheck = 'quality_check';
    case ReadyForDelivery = 'ready_for_delivery';
    case Delivered = 'delivered';
    case Fitted = 'fitted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
