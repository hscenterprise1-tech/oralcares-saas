<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\LabShade;

class ShadeService
{
    /**
     * Register a new shade code in the shade system catalog.
     */
    public function createShade(string $system, string $code, ?Clinic $clinic = null): LabShade
    {
        return LabShade::create([
            'clinic_id' => $clinic?->id,
            'system' => $system,
            'code' => $code,
        ]);
    }
}
