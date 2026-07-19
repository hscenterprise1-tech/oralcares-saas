<?php

namespace App\Services;

use App\Models\ClinicOperatingHour;
use App\Models\Leave;
use App\Models\Resource;
use App\Models\ResourceAllocation;
use App\Models\Shift;

class ScheduleValidationService
{
    /**
     * Verify if a resource assignment/shift has conflict (overlaps, closed hours, leave).
     */
    public function hasConflict(
        Resource $resource,
        string $date,
        string $startTime,
        string $endTime
    ): bool {
        // 1. Double-booked resource allocations
        $allocationConflict = ResourceAllocation::where('resource_id', $resource->id)
            ->where('date', $date)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($allocationConflict) {
            return true;
        }

        // 2. Overlapping shifts
        $shiftConflict = Shift::where('resource_id', $resource->id)
            ->where('date', $date)
            ->where('start_time', '<', $endTime)
            ->where('end_time', '>', $startTime)
            ->exists();

        if ($shiftConflict) {
            return true;
        }

        // 3. Resource is on approved leave
        if ($resource->employee_id) {
            $onLeave = Leave::where('employee_id', $resource->employee_id)
                ->where('status', 'approved')
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->exists();

            if ($onLeave) {
                return true;
            }
        }

        // 4. Closed branch operating hours
        if ($resource->branch_id) {
            $dayOfWeek = (int) date('w', strtotime($date));
            $operatingHour = ClinicOperatingHour::where('branch_id', $resource->branch_id)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if ($operatingHour) {
                if ($operatingHour->is_closed) {
                    return true;
                }

                // Check if target interval is outside open hours
                if ($startTime < $operatingHour->open_time || $endTime > $operatingHour->close_time) {
                    return true;
                }
            }
        }

        return false;
    }
}
