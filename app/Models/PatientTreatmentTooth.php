<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dental extension of a patient treatment: a tooth (FDI) it targets, with
 * surfaces when they matter. Tooth state and history stay in the dental
 * chart tables; this only says which teeth the treatment is for.
 */
class PatientTreatmentTooth extends Model
{
    use ClinicScoped;

    protected $table = 'patient_treatment_teeth';

    protected $fillable = [
        'clinic_id',
        'patient_treatment_id',
        'tooth_number',
        'surfaces',
    ];

    protected function casts(): array
    {
        return ['tooth_number' => 'integer', 'surfaces' => 'array'];
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }
}
