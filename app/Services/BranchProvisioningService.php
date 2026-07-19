<?php

namespace App\Services;

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicOperatingHour;
use App\Models\ClinicQueue;
use App\Models\Resource;
use Illuminate\Support\Str;

class BranchProvisioningService
{
    /**
     * Provision primary or additional branches.
     */
    public function provisionBranch(
        Clinic $clinic,
        string $branchName,
        int $chairsCount = 2,
        int $roomsCount = 2
    ): array {
        $entities = [];

        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'name' => $branchName,
            'slug' => Str::slug($branchName),
            'is_main' => ! $clinic->branches()->exists(),
            'status' => BranchStatus::Active,
        ]);
        $entities['branches'][] = $branch->id;

        // Set operating hours (0-6)
        for ($i = 0; $i < 7; $i++) {
            $h = ClinicOperatingHour::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'day_of_week' => $i,
                'open_time' => '09:00:00',
                'close_time' => '18:00:00',
                'is_closed' => ($i === 0), // Closed on Sundays
            ]);
            $entities['clinic_operating_hours'][] = $h->id;
        }

        // Set default generic resources
        for ($c = 1; $c <= $chairsCount; $c++) {
            $res = Resource::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'name' => "Dental Chair {$c}",
                'resource_type' => 'chair',
            ]);
            $entities['resources'][] = $res->id;
        }

        for ($r = 1; $r <= $roomsCount; $r++) {
            $res = Resource::create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'name' => "Consultation Room {$r}",
                'resource_type' => 'operatory',
            ]);
            $entities['resources'][] = $res->id;
        }

        // Set default Queue
        $queue = ClinicQueue::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch->id,
            'name' => 'General Reception Queue',
        ]);
        $entities['queues'][] = $queue->id;

        return $entities;
    }
}
