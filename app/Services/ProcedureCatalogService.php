<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;

class ProcedureCatalogService
{
    /**
     * Create a new procedure in the catalog.
     */
    public function createProcedure(array $data): ClinicalProcedure
    {
        return ClinicalProcedure::create($data);
    }

    /**
     * Override procedure pricing and duration for a specific clinic.
     */
    public function setClinicPricing(
        Clinic $clinic,
        ClinicalProcedure $procedure,
        float $price,
        ?int $duration = null,
        bool $available = true
    ): ClinicalProcedurePrice {
        return ClinicalProcedurePrice::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'clinical_procedure_id' => $procedure->id,
            ],
            [
                'price' => $price,
                'duration_minutes' => $duration,
                'is_available' => $available,
            ]
        );
    }

    /**
     * Get the effective price of a procedure for a clinic.
     */
    public function getEffectivePrice(Clinic $clinic, ClinicalProcedure $procedure): float
    {
        $override = ClinicalProcedurePrice::where('clinic_id', $clinic->id)
            ->where('clinical_procedure_id', $procedure->id)
            ->first();

        // If override exists, return it, otherwise fallback to the procedure's estimated/base price if present.
        return $override ? (float) $override->price : 0.00;
    }
}
