<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event in a tooth's history: a finding charted, or a treatment done.
 * Clinic-owned (from the patient or consultation) and never edited.
 */
class PatientToothStatusHistory extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const FINDING = 'finding';

    public const TREATMENT = 'treatment';

    protected $table = 'patient_tooth_status_histories';

    protected $fillable = [
        'patient_treatment_id',
        'clinic_id',
        'patient_id',
        'encounter_id',
        'tooth_number',
        'kind',
        'status',
        'previous_status',
        'surfaces',
        'notes',
        'treatment_record_id',
        'patient_procedure_id',
        'updated_by_doctor_id',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'surfaces' => 'array',
            'tooth_number' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function treatmentRecord(): BelongsTo
    {
        return $this->belongsTo(TreatmentRecord::class, 'treatment_record_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'updated_by_doctor_id');
    }
}
