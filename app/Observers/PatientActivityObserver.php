<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\PatientBilling;
use App\Models\PatientVisit;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Patient\PatientSpecialties;
use Illuminate\Database\Eloquent\Model;

/**
 * Patient activity at a branch — a visit, an appointment, an encounter, a
 * bill — records the branch in `patient_branch_activity`, and an encounter in
 * a specialty moves that enrolment's `last_activity_at`. Neither creates a
 * specialty enrolment. A write that skips model events (saveQuietly) is
 * caught up by `patients:backfill-foundation`.
 */
class PatientActivityObserver
{
    /** @var array<class-string<Model>, string> */
    public const SUBJECTS = [
        PatientVisit::class => 'visit',
        Appointment::class => 'appointment',
        ClinicalEncounter::class => 'encounter',
        PatientBilling::class => 'billing',
    ];

    public function __construct(
        protected PatientBranchActivityRecorder $recorder,
        protected PatientSpecialties $specialties,
    ) {}

    public function created(Model $model): void
    {
        $clinicId = (int) $model->getAttribute('clinic_id');
        $patientId = (int) $model->getAttribute('patient_id');
        $branchId = (int) $model->getAttribute('branch_id');
        if ($clinicId === 0 || $patientId === 0) {
            return;
        }

        $at = now();
        if ($branchId !== 0) {
            $this->recorder->record($clinicId, $patientId, $branchId, $at, self::SUBJECTS[$model::class] ?? 'visit');
        }

        if ($model instanceof ClinicalEncounter && filled($model->specialty)) {
            $this->specialties->touch($clinicId, $patientId, (string) $model->specialty, $at);
        }
    }
}
