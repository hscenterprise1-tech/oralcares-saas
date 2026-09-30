<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OrganizationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientMedicalHistory extends Model
{
    use Auditable, OrganizationScoped;

    protected $fillable = [
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
        // Generic JSON
        'medical_alerts',
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
}
