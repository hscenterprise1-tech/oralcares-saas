<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\LabMaterial;

class MaterialService
{
    /**
     * Register a new lab material definition in the catalog.
     */
    public function createMaterial(string $name, ?Clinic $clinic = null): LabMaterial
    {
        return LabMaterial::create([
            'clinic_id' => $clinic?->id,
            'name' => $name,
        ]);
    }
}
