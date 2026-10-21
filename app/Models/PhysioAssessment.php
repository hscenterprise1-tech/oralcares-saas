<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physiotherapy assessment, one per assessment consultation: body chart,
 * pain, range of motion, strength, posture, function and goals.
 */
class PhysioAssessment extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    protected $fillable = [
        'clinic_id',
        'patient_id',
        'encounter_id',
        'presenting_problem',
        'body_chart',
        'pain_at_rest',
        'pain_on_activity',
        'range_of_motion',
        'strength',
        'posture',
        'functional_measures',
        'functional_notes',
        'goals',
        'assessed_by',
        'kind',
        'pain_location',
        'onset',
        'aggravating_factors',
        'relieving_factors',
        'objective_findings',
        'gait',
        'special_tests',
        'clinical_impression',
        'diagnosis',
    ];

    public const INITIAL = 'initial';

    public const REASSESSMENT = 'reassessment';

    protected function casts(): array
    {
        return [
            'special_tests' => 'array',
            'body_chart' => 'array',
            'range_of_motion' => 'array',
            'strength' => 'array',
            'functional_measures' => 'array',
            'pain_at_rest' => 'integer',
            'pain_on_activity' => 'integer',
        ];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'assessed_by');
    }
}
