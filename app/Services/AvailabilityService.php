<?php

namespace App\Services;

use App\Models\Availability;
use App\Models\Resource;

class AvailabilityService
{
    /**
     * Set a recurring availability slot.
     */
    public function addAvailability(
        Resource $resource,
        int $dayOfWeek,
        string $startTime,
        string $endTime
    ): Availability {
        return Availability::create([
            'resource_id' => $resource->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }
}
