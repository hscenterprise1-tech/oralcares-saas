<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One patient merge: the duplicate, the patient kept, the approved request,
 * and exactly which rows moved and which facts clashed. Written once by
 * `Services\Patient\PatientMergeService`; never changed.
 */
class PatientMerge extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'source_patient_id',
        'survivor_patient_id',
        'deletion_request_id',
        'moved',
        'conflicts',
        'merged_by',
        'merged_at',
    ];

    protected function casts(): array
    {
        return [
            'moved' => 'array',
            'conflicts' => 'array',
            'merged_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('A merge record is never changed.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('A merge record is never deleted.');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'source_patient_id');
    }

    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'survivor_patient_id');
    }
}
