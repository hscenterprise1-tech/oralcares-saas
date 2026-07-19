<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\Patient;
use App\VisitPriority;
use App\VisitStatus;

class ReceptionService
{
    public function __construct(
        protected VisitCheckInService $checkInService,
        protected VisitLifecycleService $lifecycleService,
        protected QueueService $queueService
    ) {}

    /**
     * Perform check-in for a walk-in patient.
     */
    public function checkInWalkIn(
        Clinic $clinic,
        Branch $branch,
        Patient $patient,
        ?ClinicEmployee $doctor = null
    ): ClinicalEncounter {
        return $this->checkInService->checkIn($clinic, $branch, $patient, $doctor);
    }

    /**
     * Cancel a checked-in patient's encounter.
     */
    public function cancelCheckIn(ClinicalEncounter $encounter): void
    {
        $this->lifecycleService->transitionStatus($encounter, VisitStatus::Cancelled);
    }

    /**
     * Reopen a completed or cancelled encounter.
     */
    public function reopenVisit(ClinicalEncounter $encounter): void
    {
        $this->lifecycleService->transitionStatus($encounter, VisitStatus::CheckedIn);
    }

    /**
     * Complete the reception check-in and push the encounter into the waiting queue.
     */
    public function completeReceptionStage(
        ClinicalEncounter $encounter,
        ClinicQueue $queue,
        VisitPriority $priority = VisitPriority::Normal
    ): void {
        $this->queueService->enqueue($queue, $encounter, $priority);
    }
}
