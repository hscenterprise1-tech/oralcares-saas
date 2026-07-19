<?php

namespace App\Services;

use App\Models\ClinicQueueItem;

class QueueRoutingService
{
    /**
     * Route patient queue step to target floors and specialized departments.
     */
    public function routePatient(ClinicQueueItem $item, string $floor, string $department): void
    {
        $item->update([
            'current_floor' => $floor,
            'current_department' => $department,
        ]);
    }
}
