<?php

namespace App\Services;

use App\Events\ResourceAssigned;
use App\Models\Resource;
use App\Models\ResourceAllocation;

class ResourceAllocationService
{
    /**
     * Allocate a resource slot for specific task types (encounter, xray, lab case, chair).
     */
    public function allocateResource(
        Resource $resource,
        string $type,
        ?int $id,
        string $date,
        string $startTime,
        string $endTime
    ): ResourceAllocation {
        $allocation = ResourceAllocation::create([
            'clinic_id' => $resource->clinic_id,
            'resource_id' => $resource->id,
            'allocated_to_type' => $type,
            'allocated_to_id' => $id,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        event(new ResourceAssigned($allocation));

        return $allocation;
    }
}
