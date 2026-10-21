<?php

namespace App\Services\Treatment;

use App\Models\Branch;
use App\Models\CatalogPriceVersion;
use App\Models\CatalogStage;
use App\Models\CatalogTreatmentVersion;
use App\Models\Clinic;
use App\Models\ClinicalProcedure;
use App\Models\User;
use App\Services\Security\SecurityAudit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of `catalog_price_versions`, and the one place a price is
 * looked up. Setting a price never edits one: the open version is closed at
 * the moment the new one starts. A branch's own price wins over the
 * clinic-wide one.
 */
class CatalogPricing
{
    /** @var array<string, class-string> */
    private const TARGET_MODELS = [
        'catalog_treatment_version_id' => CatalogTreatmentVersion::class,
        'catalog_stage_id' => CatalogStage::class,
        'clinical_procedure_id' => ClinicalProcedure::class,
    ];

    public function __construct(
        protected SecurityAudit $audit,
        protected TreatmentAccess $access,
    ) {}

    /**
     * A staff member changing a price (`catalog.manage`). Seeders and
     * migrations call `set()` directly.
     *
     * @param  'catalog_treatment_version_id'|'catalog_stage_id'|'clinical_procedure_id'  $target
     */
    public function change(Clinic $clinic, string $target, int $targetId, float $amount, ?Branch $branch, User $by, ?CarbonInterface $from = null): CatalogPriceVersion
    {
        $this->access->assertCanManageCatalog($by, $clinic);
        if ($from !== null && $from->lt(now()->startOfDay())) {
            throw new InvalidArgumentException('A price cannot start in the past: what was charged then stays as it was.');
        }

        return $this->set($clinic, $target, $targetId, $amount, $branch, $by, $from);
    }

    /**
     * Every price version of one target, newest first (bounded).
     *
     * @param  'catalog_treatment_version_id'|'catalog_stage_id'|'clinical_procedure_id'  $target
     * @return Collection<int, CatalogPriceVersion>
     */
    public function history(int $clinicId, string $target, int $targetId, int $limit = 200): Collection
    {
        if (! array_key_exists($target, self::TARGET_MODELS)) {
            throw new InvalidArgumentException("Unknown price target “{$target}”.");
        }

        return CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where($target, $targetId)
            ->orderByDesc('effective_from')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * @param  'catalog_treatment_version_id'|'catalog_stage_id'|'clinical_procedure_id'  $target
     */
    public function set(Clinic $clinic, string $target, int $targetId, float $amount, ?Branch $branch = null, ?User $by = null, ?CarbonInterface $from = null): CatalogPriceVersion
    {
        $model = self::TARGET_MODELS[$target] ?? throw new InvalidArgumentException("Unknown price target “{$target}”.");
        if (! $model::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->whereKey($targetId)->exists()) {
            throw new InvalidArgumentException('Price something in this clinic\'s own catalog.');
        }
        if ($branch && $branch->clinic_id !== $clinic->id) {
            throw new InvalidArgumentException('Price at one of this clinic\'s own branches.');
        }
        if ($amount < 0) {
            throw new InvalidArgumentException('A price cannot be negative.');
        }
        $from ??= now();

        return DB::transaction(function () use ($clinic, $target, $targetId, $amount, $branch, $by, $from) {
            $open = CatalogPriceVersion::query()->withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)->where($target, $targetId)
                ->where('branch_id', $branch?->id)->whereNull('effective_to')
                ->lockForUpdate()->first();

            if ($open && (float) $open->amount === round($amount, 2)) {
                return $open;
            }
            $open?->update(['effective_to' => $from]);

            $price = CatalogPriceVersion::query()->withoutGlobalScopes()->create([
                'clinic_id' => $clinic->id,
                'branch_id' => $branch?->id,
                $target => $targetId,
                'amount' => round($amount, 2),
                'effective_from' => $from,
                'created_by' => $by?->id,
            ]);
            $this->audit->record('catalog.price.set', $price, [
                'target' => $target, 'target_id' => $targetId, 'branch_id' => $branch?->id,
                'from' => $open?->amount, 'to' => $price->amount,
            ], ['clinic_id' => $clinic->id]);

            return $price;
        });
    }

    /**
     * The price in force at a moment: the branch's own price when it has one,
     * else the clinic-wide price, else null (unpriced — never zero).
     *
     * @param  'catalog_treatment_version_id'|'catalog_stage_id'|'clinical_procedure_id'  $target
     */
    public function current(int $clinicId, string $target, int $targetId, ?int $branchId = null, ?CarbonInterface $at = null): ?CatalogPriceVersion
    {
        $at ??= now();

        return CatalogPriceVersion::query()->withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->where($target, $targetId)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchId, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->where('effective_from', '<=', $at)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $at))
            ->get()
            ->sortByDesc(fn (CatalogPriceVersion $price) => [$price->branch_id !== null, $price->effective_from])
            ->first();
    }

    /** Whether anything is priced for this target yet (in the new model). */
    public function hasAny(int $clinicId, string $target, int $targetId): bool
    {
        return CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->where($target, $targetId)->exists();
    }
}
