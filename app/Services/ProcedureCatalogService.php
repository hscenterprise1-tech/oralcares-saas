<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use InvalidArgumentException;

class ProcedureCatalogService
{
    public function createProcedure(array $data): ClinicalProcedure
    {
        return ClinicalProcedure::create($data);
    }

    /**
     * Set the clinic-wide price for a procedure, or a branch's own price when
     * a branch is given.
     */
    public function setClinicPricing(
        Clinic $clinic,
        ClinicalProcedure $procedure,
        float $price,
        ?int $duration = null,
        bool $available = true,
        ?Branch $branch = null,
    ): ClinicalProcedurePrice {
        $this->assertBelongsTo($clinic, $procedure, $branch);

        return ClinicalProcedurePrice::withoutGlobalScopes()->updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'clinical_procedure_id' => $procedure->id,
                'branch_id' => $branch?->id,
            ],
            [
                'price' => $price,
                'duration_minutes' => $duration,
                'is_available' => $available,
            ]
        );
    }

    /**
     * The price at a branch: the branch's own price when it has one, else the
     * clinic-wide price, else 0.
     */
    public function getEffectivePrice(Clinic $clinic, ClinicalProcedure $procedure, ?Branch $branch = null): float
    {
        $prices = ClinicalProcedurePrice::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->where('clinical_procedure_id', $procedure->id)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branch, fn ($query) => $query->orWhere('branch_id', $branch->id)))
            ->get();

        $override = ($branch ? $prices->firstWhere('branch_id', $branch->id) : null)
            ?? $prices->firstWhere('branch_id', null);

        return $override ? (float) $override->price : 0.00;
    }

    private function assertBelongsTo(Clinic $clinic, ClinicalProcedure $procedure, ?Branch $branch): void
    {
        if ($procedure->clinic_id !== null && $procedure->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('Cannot price another clinic\'s procedure.');
        }

        if ($branch && $branch->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('Cannot price a procedure at another clinic\'s branch.');
        }
    }
}
