<?php

namespace App\Models;

use App\Traits\ClinicScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One exercise in a plan of care. Takes its clinic from the plan.
 */
class PhysioExercise extends Model
{
    use ClinicScoped;

    protected $fillable = [
        'patient_treatment_id',
        'clinic_id',
        'care_plan_id',
        'name',
        'sets',
        'reps',
        'hold_seconds',
        'frequency',
        'instructions',
        'is_home',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sets' => 'integer',
            'reps' => 'integer',
            'hold_seconds' => 'integer',
            'is_home' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected function performInsert(Builder $query)
    {
        $planClinic = PhysioCarePlan::query()->withoutGlobalScopes()->whereKey($this->getAttribute('care_plan_id'))->value('clinic_id');
        if ($planClinic !== null) {
            $this->setAttribute('clinic_id', $planClinic);
        }

        return parent::performInsert($query);
    }

    /**
     * A plan's exercises are its history: once the plan is superseded or
     * closed they never change (a change is a new revision).
     */
    protected function performUpdate(Builder $query)
    {
        $status = PhysioCarePlan::query()->withoutGlobalScopes()->whereKey($this->getAttribute('care_plan_id'))->value('status');
        if ($status !== null && $status !== PhysioCarePlan::ACTIVE) {
            throw new \LogicException('The exercises of a superseded or closed plan are kept as they were.');
        }

        return parent::performUpdate($query);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PhysioCarePlan::class, 'care_plan_id');
    }
}
