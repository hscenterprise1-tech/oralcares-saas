<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A course of root canal treatment on one tooth. Written through
 * `Dental\DentalSpecialtyRecords`; voided (never deleted) by an
 * owner-approved request.
 */
class EndodonticRecord extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const STAGES = ['diagnosis', 'access', 'cleaning_shaping', 'obturation', 'restoration', 'review'];

    public const STATUSES = ['planned', 'in_progress', 'completed', 'abandoned'];

    protected $fillable = [
        'patient_treatment_id',
        'clinic_id', 'patient_id', 'encounter_id', 'last_encounter_id', 'treatment_record_id', 'tooth_number', 'status', 'stage',
        'pulp_status', 'periapical_status', 'diagnosis', 'canals', 'instrumentation_notes', 'obturation_technique', 'obturation_material',
        'obturated_on', 'temporary_restoration', 'permanent_restoration', 'review_on', 'notes', 'created_by', 'completed_at',
        'voided_at', 'voided_by', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'tooth_number' => 'integer',
            'canals' => 'array',
            'obturated_on' => 'date',
            'review_on' => 'date',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'created_by');
    }
}
