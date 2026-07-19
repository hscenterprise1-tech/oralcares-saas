<?php

namespace App;

enum DemoOutcome: string
{
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Rescheduled = 'rescheduled';
    case Cancelled = 'cancelled';
}
