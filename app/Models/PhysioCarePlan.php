<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physiotherapy plan of care: goals, interventions, how many sessions how
 * often, and the exercise programme.
 */
class PhysioCarePlan extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const DISCONTINUED = 'discontinued';

    /** Replaced by a later revision of the same treatment's plan; kept exactly as it was. */
    public const SUPERSEDED = 'superseded';

    /** Closed states: the plan no longer guides care. */
    public const CLOSED = [self::COMPLETED, self::DISCONTINUED, self::SUPERSEDED];

    protected $fillable = [
        'patient_treatment_id',
        'revision',
        'supersedes_id',
        'effective_from',
        'clinic_id',
        'patient_id',
        'encounter_id',
        'assessment_id',
        'title',
        'goals',
        'interventions',
        'planned_sessions',
        'frequency',
        'starts_on',
        'status',
        'closed_at',
        'closing_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'interventions' => 'array',
            'planned_sessions' => 'integer',
            'revision' => 'integer',
            'starts_on' => 'date',
            'effective_from' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(PhysioExercise::class, 'care_plan_id')->orderBy('sort_order')->orderBy('id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PhysioSession::class, 'care_plan_id');
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(PhysioAssessment::class, 'assessment_id');
    }
}
