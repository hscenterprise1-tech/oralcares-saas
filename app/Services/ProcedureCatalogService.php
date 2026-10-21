<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\ClinicalProcedurePrice;
use App\Services\Treatment\CatalogPricing;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Procedure catalog pricing. Prices are immutable `catalog_price_versions`
 * (see `Treatment\CatalogPricing`); the old `clinical_procedure_prices`
 * table is still written alongside them so its remaining readers stay
 * correct, and is read only for a procedure the foundation backfill has
 * not migrated yet.
 */
class ProcedureCatalogService
{
    public function __construct(protected CatalogPricing $pricing) {}

    public function createProcedure(array $data): ClinicalProcedure
    {
        return ClinicalProcedure::create($data);
    }

    /**
     * Set the clinic-wide price for a procedure, or a branch's own price when
     * a branch is given: a new price version (the old one is closed, never
     * edited), mirrored into the legacy table.
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

        return DB::transaction(function () use ($clinic, $procedure, $price, $duration, $available, $branch) {
            // A legacy shared (clinic-less) procedure cannot carry a clinic's
            // price version; it keeps the legacy row only.
            if ($procedure->clinic_id !== null) {
                $this->pricing->set($clinic, 'clinical_procedure_id', $procedure->id, $price, $branch, auth()->user());
            }

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
        });
    }

    /**
     * The price at a branch: the branch's own price when it has one, else the
     * clinic-wide price, else 0.
     */
    public function getEffectivePrice(Clinic $clinic, ClinicalProcedure $procedure, ?Branch $branch = null): float
    {
        return $this->effectivePrice($clinic->id, $procedure, $branch?->id) ?? 0.00;
    }

    /** As `getEffectivePrice()`, but null when the procedure is unpriced. */
    public function effectivePrice(int $clinicId, ClinicalProcedure $procedure, ?int $branchId = null): ?float
    {
        $version = $this->pricing->current($clinicId, 'clinical_procedure_id', $procedure->id, $branchId);
        if ($version) {
            return (float) $version->amount;
        }
        if ($this->pricing->hasAny($clinicId, 'clinical_procedure_id', $procedure->id)) {
            return null;
        }

        // Not migrated yet: the legacy table.
        $prices = ClinicalProcedurePrice::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)
            ->where('clinical_procedure_id', $procedure->id)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchId, fn ($query) => $query->orWhere('branch_id', $branchId)))
            ->get();
        $override = ($branchId ? $prices->firstWhere('branch_id', $branchId) : null) ?? $prices->firstWhere('branch_id', null);

        return $override ? (float) $override->price : null;
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
