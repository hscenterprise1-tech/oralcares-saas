<?php

namespace App\Models;

use App\TreatmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentRecord extends Model
{
    protected $fillable = [
        'patient_id',
        'encounter_id',
        'treatment_plan_id',
        'clinical_procedure_id',
        'code',
        'description',
        'tooth_number',
        'status',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'status' => TreatmentStatus::class,
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(ClinicalProcedure::class, 'clinical_procedure_id');
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
