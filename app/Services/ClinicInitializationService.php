<?php

namespace App\Services;

use App\ClinicStatus;
use App\Models\Clinic;

class ClinicInitializationService
{
    /**
     * Safely initialize a clinic, returning existing one if already created.
     */
    public function initializeClinic(string $name, string $slug): Clinic
    {
        return Clinic::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'status' => ClinicStatus::Active,
            ]
        );
    }
}
