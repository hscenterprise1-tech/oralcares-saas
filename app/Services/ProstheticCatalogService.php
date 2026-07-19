<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\LabProsthetic;

class ProstheticCatalogService
{
    /**
     * Register a new prosthetic definition in the catalog.
     */
    public function createProsthetic(string $name, ?string $category = null, ?Clinic $clinic = null): LabProsthetic
    {
        return LabProsthetic::create([
            'clinic_id' => $clinic?->id,
            'name' => $name,
            'category' => $category,
        ]);
    }
}
