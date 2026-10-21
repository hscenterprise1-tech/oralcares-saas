<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An immutable price: for a treatment version (charged upfront), a stage
 * (charged per stage) or a procedure (charged per procedure/session),
 * clinic-wide or for one branch, from `effective_from` until a newer price
 * closes it (`effective_to`). A price is never edited or deleted — patient
 * treatments, procedures and bill lines point at the exact row they used.
 */
class CatalogPriceVersion extends Model
{
    use ClinicScoped;

    public const TARGETS = ['catalog_treatment_version_id', 'catalog_stage_id', 'clinical_procedure_id'];

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'catalog_treatment_version_id',
        'catalog_stage_id',
        'clinical_procedure_id',
        'amount',
        'currency',
        'effective_from',
        'effective_to',
        'legacy_price_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    protected function performInsert(Builder $query)
    {
        $targets = array_filter(self::TARGETS, fn (string $column) => $this->getAttribute($column) !== null);
        if (count($targets) !== 1) {
            throw new LogicException('A price belongs to exactly one of a treatment version, a stage or a procedure.');
        }

        return parent::performInsert($query);
    }

    /** Only closing an open price (setting `effective_to` once) is allowed. */
    protected function performUpdate(Builder $query)
    {
        $changed = array_diff(array_keys($this->getDirty()), ['effective_to', 'updated_at']);
        if ($changed !== [] || $this->getRawOriginal('effective_to') !== null) {
            throw new LogicException('A price version cannot be changed. Set a new price instead.');
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('A price version is never deleted.');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
    }
}
