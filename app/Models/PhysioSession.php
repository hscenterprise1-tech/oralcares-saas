<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use App\Traits\InheritsClinicOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One physiotherapy treatment session: a SOAP note on its consultation, with
 * pain before and after, modalities used, the bed or room, and the package
 * the session was punched against.
 */
class PhysioSession extends Model
{
    use ClinicScoped, InheritsClinicOwnership;

    /** Being written; consumes nothing. */
    public const DRAFT = 'draft';

    /** Delivered: numbered within its treatment, and the moment a package is consumed. */
    public const COMPLETED = 'completed';

    /** A completed session undone later; its consumption is reversed in the ledger. */
    public const REVERSED = 'reversed';

    protected $fillable = [
        'status',
        'completed_at',
        'completed_by',
        'reversed_at',
        'reversal_reason',
        'patient_treatment_id',
        'patient_procedure_id',
        'clinic_id',
        'patient_id',
        'encounter_id',
        'care_plan_id',
        'session_number',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'pain_before',
        'pain_after',
        'modalities',
        'given',
        'session_type',
        'addons',
        'charge',
        'room_id',
        'package_id',
        'therapist_id',
        'treatment_performed',
        'exercises_performed',
        'patient_response',
        'home_program',
        'next_step',
    ];

    protected function casts(): array
    {
        return [
            'exercises_performed' => 'array',
            'modalities' => 'array',
            'given' => 'array',
            'addons' => 'array',
            'charge' => 'decimal:2',
            'pain_before' => 'integer',
            'pain_after' => 'integer',
            'session_number' => 'integer',
            'completed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** What was actually done, against the plan. */
    public function performedExercises(): HasMany
    {
        return $this->hasMany(PhysioSessionExercise::class, 'physio_session_id')->orderBy('position')->orderBy('id');
    }

    /** The course (treatment) this session belongs to. */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'encounter_id');
    }

    public function carePlan(): BelongsTo
    {
        return $this->belongsTo(PhysioCarePlan::class, 'care_plan_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(ClinicRoom::class, 'room_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PatientSessionPackage::class, 'package_id');
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(ClinicEmployee::class, 'therapist_id');
    }
}
