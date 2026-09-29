<?php

namespace App\Events;

use App\Models\ClinicQueueItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once for every queue move `QueueStateMachine` makes — check-in, call,
 * start, complete, skip, requeue, cancel, priority change. The one event the
 * TV display (Phase A3) will broadcast on.
 */
class QueueItemTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicQueueItem $queueItem,
        public string $action,
        public ?string $fromStatus,
        public string $toStatus,
    ) {}
}
