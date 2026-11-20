<?php

namespace App\Observers;

use App\Models\ClinicalEncounter;
use App\Models\PatientProcedure;
use App\Services\Communication\PatientMessaging;
use App\Services\Patient\PatientAutomation;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Patient-care automation on committed clinical records, without the writers
 * knowing about it:
 *
 * - a consultation is opened → the patient with no assigned doctor is
 *   assigned this one (`PatientAutomation::assignFirstDoctor`);
 * - a procedure is done (recorded completed, or moved to completed) → care
 *   advice for it (`PatientMessaging::procedureAftercare`). A planned
 *   procedure sends nothing.
 *
 * Runs only after the transaction commits, so a rolled-back change does nothing.
 */
class PatientCareObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void
    {
        match (true) {
            $model instanceof ClinicalEncounter => app(PatientAutomation::class)->assignFirstDoctor($model),
            $model instanceof PatientProcedure && $model->status === 'completed' => app(PatientMessaging::class)->procedureAftercare($model),
            default => null,
        };
    }

    public function updated(Model $model): void
    {
        if ($model instanceof PatientProcedure && $model->wasChanged('status') && $model->status === 'completed') {
            app(PatientMessaging::class)->procedureAftercare($model);
        }
    }
}
