<?php

namespace App;

enum QueueItemStatus: string
{
    case Waiting = 'waiting';
    case Calling = 'calling';
    case Called = 'called';
    case InConsultation = 'in_consultation';
    case Recalled = 'recalled';
    case Skipped = 'skipped';
    case Held = 'held';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
