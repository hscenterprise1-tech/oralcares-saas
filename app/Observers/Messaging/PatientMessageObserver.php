<?php

namespace App\Observers\Messaging;

use App\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ClinicalEncounter;
use App\Models\PatientBilling;
use App\Models\PatientBillingPayment;
use App\Models\PatientSessionPackage;
use App\Models\PatientTreatmentStage;
use App\Models\PhysioSession;
use App\Services\Communication\PatientMessaging;
use App\Services\Physio\SessionPackages;
use App\VisitStatus;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns committed changes to the canonical records into patient messages
 * (`PatientMessaging`), without the writers knowing about messaging:
 *
 * - appointment marked no-show → `appointment.no_show`
 * - consultation signed → `visit.completed` (feedback; dental after-visit check)
 * - treatment stage completed → `treatment.stage_completed` (dental)
 * - physiotherapy session completed → `physio.session.completed` (next session, low balance)
 * - bill raised → `bill.created`; payment recorded → `payment.completed`
 *
 * Runs only after the transaction commits, so a rolled-back change never
 * messages a patient. Event keys make every message once-only.
 */
class PatientMessageObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void
    {
        match (true) {
            $model instanceof PatientBilling => $this->messages()->billIssued($model->fresh() ?? $model),
            $model instanceof PatientBillingPayment => $this->messages()->paymentRecorded($model),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Appointment && $model->wasChanged('status') && $model->status === AppointmentStatus::NoShow) {
            $this->messages()->appointmentMissed($model);
        }
        if ($model instanceof ClinicalEncounter && $model->wasChanged('finalized_at') && $model->finalized_at !== null && $model->status === VisitStatus::Completed) {
            $this->messages()->visitCompleted($model);
        }
        if ($model instanceof PatientTreatmentStage && $model->wasChanged('status') && $model->status === PatientTreatmentStage::COMPLETED) {
            $this->messages()->treatmentStageCompleted($model);
        }
        if ($model instanceof PhysioSession && $model->wasChanged('status') && $model->status === 'completed') {
            $package = $model->package_id ? PatientSessionPackage::query()->withoutGlobalScopes()->find($model->package_id) : null;
            $this->messages()->physioSessionCompleted($model, $package, $package ? app(SessionPackages::class)->balance($package) : null);
        }
    }

    private function messages(): PatientMessaging
    {
        return app(PatientMessaging::class);
    }
}
