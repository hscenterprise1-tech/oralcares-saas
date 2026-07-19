<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicOperatingHour;
use App\Models\Resource;

class ResourceService
{
    /**
     * Register a new generic clinic resource.
     */
    public function createResource(
        Clinic $clinic,
        ?Branch $branch,
        string $name,
        string $resourceType,
        ?ClinicEmployee $employee = null,
        ?array $metadata = null
    ): Resource {
        return Resource::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch?->id,
            'employee_id' => $employee?->id,
            'name' => $name,
            'resource_type' => $resourceType,
            'is_active' => true,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Set operating hours for a specific branch and day.
     */
    public function setOperatingHours(
        Branch $branch,
        int $dayOfWeek,
        string $openTime,
        string $closeTime,
        bool $isClosed = false
    ): ClinicOperatingHour {
        return ClinicOperatingHour::updateOrCreate(
            [
                'clinic_id' => $branch->clinic_id,
                'branch_id' => $branch->id,
                'day_of_week' => $dayOfWeek,
            ],
            [
                'open_time' => $openTime,
                'close_time' => $closeTime,
                'is_closed' => $isClosed,
            ]
        );
    }
}
