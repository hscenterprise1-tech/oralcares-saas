<?php

namespace App\Services;

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;

class BranchInitializationService
{
    /**
     * Initialize primary branch for a clinic, avoiding duplicates.
     */
    public function initializePrimaryBranch(Clinic $clinic, string $name): Branch
    {
        $existing = $clinic->branches()->where('is_main', true)->first();

        if ($existing) {
            return $existing;
        }

        return $clinic->branches()->create([
            'name' => $name,
            'slug' => 'main',
            'is_main' => true,
            'status' => BranchStatus::Active,
        ]);
    }
}
