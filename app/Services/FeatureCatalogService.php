<?php

namespace App\Services;

use App\Models\CompanyFeature;

class FeatureCatalogService
{
    /**
     * Register a feature in the catalog.
     */
    public function registerFeature(string $name, string $key, ?string $description = null): CompanyFeature
    {
        return CompanyFeature::updateOrCreate(
            ['key' => $key],
            [
                'name' => $name,
                'description' => $description,
                'is_active' => true,
            ]
        );
    }
}
