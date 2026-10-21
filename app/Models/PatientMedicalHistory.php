<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMedicalHistory extends Model
{
    use Auditable, ClinicScoped, InheritsClinicOwnership, OrganizationScoped;

    protected $fillable = [
        'clinic_id',
        'organization_id',
        'patient_id',
        // Legacy
        'pregnancy_status',
        'blood_disorder',
        'diabetes',
        'hypertension',
        'cardiac_condition',
        'tobacco_use',
        'alcohol_use',
        'allergies',
        'medical_conditions',
        'current_medications',
        'previous_surgeries',
        'notes',
        // Generic JSON. `medical_alerts` is deliberately not fillable: alerts
        // live only in `patient_alerts`; the old column keeps legacy data until retired.
        'lifestyle_factors',
        'chronic_conditions',
    ];

    protected function casts(): array
    {
        return [
            'pregnancy_status' => 'boolean',
            'blood_disorder' => 'boolean',
            'diabetes' => 'boolean',
            'hypertension' => 'boolean',
            'cardiac_condition' => 'boolean',
            'tobacco_use' => 'boolean',
            'alcohol_use' => 'boolean',
            'allergies' => 'array',
            'medical_conditions' => 'array',
            'current_medications' => 'array',
            'previous_surgeries' => 'array',
            'medical_alerts' => 'array',
            'lifestyle_factors' => 'array',
            'chronic_conditions' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * `medical_conditions` is the canonical list; `chronic_conditions` is a
     * legacy duplicate. Anything still written there is merged into the
     * canonical list (and kept in the old column until it is retired).
     *
     * @param  list<string>|string|null  $value
     */
    public function setChronicConditionsAttribute(array|string|null $value): void
    {
        $conditions = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);
        $this->attributes['chronic_conditions'] = json_encode(array_values($conditions));
        $merged = collect($this->medical_conditions ?? [])->merge($conditions)
            ->map(fn ($condition) => trim((string) $condition))->filter()->unique(fn ($condition) => mb_strtolower($condition))->values()->all();
        $this->attributes['medical_conditions'] = json_encode($merged);
    }
}
