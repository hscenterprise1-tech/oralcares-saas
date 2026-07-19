<?php

namespace App\Services;

use App\Events\ConsultationCompleted;
use App\Events\ConsultationStarted;
use App\Models\ClinicalConsultation;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\VisitStatus;
use Illuminate\Support\Facades\DB;

class ConsultationService
{
    public function __construct(
        protected VisitLifecycleService $lifecycleService
    ) {}

    /**
     * Start consultation for a clinical encounter.
     */
    public function startConsultation(ClinicalEncounter $encounter, ClinicEmployee $doctor): ClinicalConsultation
    {
        return DB::transaction(function () use ($encounter, $doctor) {
            $consultation = ClinicalConsultation::create([
                'encounter_id' => $encounter->id,
                'doctor_id' => $doctor->id,
                'status' => 'started',
                'started_at' => now(),
            ]);

            $this->lifecycleService->transitionStatus($encounter, VisitStatus::InConsultation);

            event(new ConsultationStarted($consultation));

            return $consultation;
        });
    }

    /**
     * Pause the active consultation.
     */
    public function pauseConsultation(ClinicalEncounter $encounter): void
    {
        $consultation = ClinicalConsultation::where('encounter_id', $encounter->id)->latest()->firstOrFail();
        $consultation->update([
            'status' => 'paused',
            'paused_at' => now(),
        ]);
    }

    /**
     * Resume the paused consultation.
     */
    public function resumeConsultation(ClinicalEncounter $encounter): void
    {
        $consultation = ClinicalConsultation::where('encounter_id', $encounter->id)->latest()->firstOrFail();
        $consultation->update([
            'status' => 'resumed',
            'paused_at' => null,
        ]);
    }

    /**
     * Complete the consultation.
     */
    public function finishConsultation(ClinicalEncounter $encounter): void
    {
        DB::transaction(function () use ($encounter) {
            $consultation = ClinicalConsultation::where('encounter_id', $encounter->id)->latest()->firstOrFail();
            $consultation->update([
                'status' => 'completed',
                'ended_at' => now(),
            ]);

            $this->lifecycleService->transitionStatus($encounter, VisitStatus::Completed);

            event(new ConsultationCompleted($consultation));
        });
    }
}
