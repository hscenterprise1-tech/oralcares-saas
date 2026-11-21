<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\TreatmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentRecord extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'treatment_plan_id',
        'clinical_procedure_id',
        'code',
        'description',
        'tooth_number',
        'surfaces',
        'status',
        'price',
        'sittings_total',
        'sort_order',
        'completed_at',
        'patient_procedure_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TreatmentStatus::class,
            'surfaces' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** The procedure recorded in the consultation where this item was done. */
    public function performedProcedure(): BelongsTo
    {
        return $this->belongsTo(PatientProcedure::class, 'patient_procedure_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
    }

    public function sittings(): HasMany
    {
        return $this->hasMany(TreatmentRecordSitting::class)->orderBy('number');
    }

    public function progressNotes(): HasMany
    {
        return $this->hasMany(TreatmentProgressNote::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(TreatmentMaterial::class);
    }
}
