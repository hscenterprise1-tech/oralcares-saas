<?php

namespace App\Services;

use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\Models\QueueHistory;

class QueueHistoryService
{
    /**
     * Write a new immutable history record for a queue item.
     */
    public function logAction(
        ClinicQueueItem $item,
        string $action,
        ?ClinicEmployee $performer = null,
        ?string $notes = null
    ): QueueHistory {
        return QueueHistory::create([
            'clinic_id' => $item->queue->clinic_id,
            'branch_id' => $item->queue->branch_id,
            'clinic_queue_item_id' => $item->id,
            'action' => $action,
            'performed_by' => $performer?->id,
            'notes' => $notes,
        ]);
    }
}
