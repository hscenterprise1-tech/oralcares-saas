<?php

namespace App;

enum TreatmentStatus: string
{
    case Proposed = 'proposed';
    case Planned = 'planned';
    case Started = 'started';
    case Paused = 'paused';
    case Resumed = 'resumed';
    case Completed = 'completed';
    case Aborted = 'aborted';
}
