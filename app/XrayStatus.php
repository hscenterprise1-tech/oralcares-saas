<?php

namespace App;

enum XrayStatus: string
{
    case Requested = 'requested';
    case Scheduled = 'scheduled';
    case Captured = 'captured';
    case Reviewed = 'reviewed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
