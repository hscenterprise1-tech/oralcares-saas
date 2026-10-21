<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A treatment the clinic offers (Root Canal Treatment, Lower Back
 * Rehabilitation). Its configuration lives in versions; `current_version_id`
 * is the published one new patients start on. Written only through
 * `Services\Treatment\TreatmentCatalog`.
 */
class CatalogTreatment extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'specialty_key',
        'required_capability',
        'code',
        'name',
        'category',
        'description',
        'is_active',
        'current_version_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CatalogTreatmentVersion::class)->orderBy('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogTreatmentVersion::class, 'current_version_id');
    }
}
