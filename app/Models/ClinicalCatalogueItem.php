<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One item of the shared OralCares clinical catalogue: a condition, allergy,
 * alert type, medication or visit type. Global reference data, the same for
 * every clinic; a clinic changes how it uses an item through a sparse
 * `ClinicCatalogueSetting`, never by editing this row.
 *
 * Read through `ClinicalCatalogue`, which caches it.
 */
class ClinicalCatalogueItem extends Model
{
    protected $fillable = [
        'kind',
        'key',
        'name',
        'category',
        'default_severity',
        'specialty_keys',
        'practice_area_keys',
        'position',
        'is_active',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'specialty_keys' => 'array',
            'practice_area_keys' => 'array',
            'position' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }
}
