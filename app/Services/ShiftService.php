<?php

namespace App\Services;

use App\Events\ShiftCreated;
use App\Events\ShiftUpdated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Resource;
use App\Models\Shift;

class ShiftService
{
    /**
     * Create a new scheduled shift slot.
     */
    public function createShift(
        Clinic $clinic,
        Branch $branch,
        Resource $resource,
        string $name,
        string $date,
        string $startTime,
        string $endTime
    ): Shift {
        $shift = Shift::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'resource_id' => $resource->id,
            'name' => $name,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        event(new ShiftCreated($shift));

        return $shift;
    }

    /**
     * Update an existing shift slot.
     */
    public function updateShift(Shift $shift, array $data): Shift
    {
        $shift->update($data);

        event(new ShiftUpdated($shift));

        return $shift;
    }
}
