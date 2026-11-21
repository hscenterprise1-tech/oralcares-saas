<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One visit of a multi-visit dental treatment: which stage it covered, what the doctor noted, what was charged.
 */
class TreatmentRecordSitting extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'clinic_id',
        'treatment_record_id',
        'encounter_id',
        'patient_procedure_id',
        'number',
        'stage',
        'note',
        'charged',
        'done_at',
    ];

    protected function casts(): array
    {
        return ['charged' => 'decimal:2', 'done_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }
}
