<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One condition, allergy or medication on a patient: the canonical record of
 * all three. Written only through `PatientClinicalEntries`, which also keeps
 * the patient's derived safety alerts in step.
 */
class PatientClinicalEntry extends Model
{
    use ClinicScoped;

    public const ACTIVE = 'active';

    public const RESOLVED = 'resolved';

    public const SOURCES = ['registration', 'clinical', 'backfill'];

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'kind',
        'catalogue_key',
        'label',
        'dedupe_key',
        'severity',
        'status',
        'details',
        'source',
        'recorded_by',
        'recorded_at',
        'resolved_at',
        'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'recorded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }
}
