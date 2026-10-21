<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one clinic uses one catalogue item, or an item of the clinic's own
 * (`catalogue_item_id` null). Sparse: a row exists only for what the clinic
 * changed, and a null column means "as the catalogue has it".
 *
 * Written only through `ClinicalCatalogue::configure()`, which audits it.
 */
class ClinicCatalogueSetting extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'kind',
        'key',
        'catalogue_item_id',
        'name',
        'category',
        'severity',
        'specialty_keys',
        'practice_area_keys',
        'position',
        'is_enabled',
        'is_required',
        'archived_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'specialty_keys' => 'array',
            'practice_area_keys' => 'array',
            'position' => 'integer',
            'is_enabled' => 'boolean',
            'is_required' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ClinicalCatalogueItem::class, 'catalogue_item_id');
    }
}
