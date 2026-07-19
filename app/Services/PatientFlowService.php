<?php

namespace App\Services;

use App\Events\PatientCalled;
use App\Events\PatientCompleted;
use App\Events\PatientHeld;
use App\Events\PatientRecalled;
use App\Events\PatientResumed;
use App\Events\PatientSkipped;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueueItem;
use App\QueueItemStatus;
use Illuminate\Support\Facades\DB;

class PatientFlowService
{
    public function __construct(
        protected QueueHistoryService $historyService
    ) {}

    /**
     * Call the next patient in line.
     */
    public function callPatient(ClinicQueueItem $item, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($item, $performer) {
            $item->update([
                'status' => QueueItemStatus::Calling,
                'called_at' => now(),
            ]);

            $this->historyService->logAction($item, 'called', $performer);

            event(new PatientCalled($item));
        });
    }

    /**
     * Recall the current active patient.
     */
    public function recallPatient(ClinicQueueItem $item, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($item, $performer) {
            $item->update(['status' => QueueItemStatus::Recalled]);

            $this->historyService->logAction($item, 'recalled', $performer);

            event(new PatientRecalled($item));
        });
    }

    /**
     * Skip the patient due to absence or redirect.
     */
    public function skipPatient(ClinicQueueItem $item, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($item, $performer) {
            $item->update(['status' => QueueItemStatus::Skipped]);

            $this->historyService->logAction($item, 'skipped', $performer);

            event(new PatientSkipped($item));
        });
    }

    /**
     * Hold the patient session temporarily.
     */
    public function holdPatient(ClinicQueueItem $item, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($item, $performer) {
            $item->update(['status' => QueueItemStatus::Held]);

            $this->historyService->logAction($item, 'held', $performer);

            event(new PatientHeld($item));
        });
    }

    /**
     * Resume patient session from hold status.
     */
    public function resumePatient(ClinicQueueItem $item, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($item, $performer) {
            $item->update(['status' => QueueItemStatus::Called]);

            $this->historyService->logAction($item, 'resumed', $performer);

            event(new PatientResumed($item));
        });
    }

    /**
     * Complete the patient's queue step.
     */
    public function completePatient(ClinicQueueItem $item, ?ClinicEmployee $performer = null): void
    {
        DB::transaction(function () use ($item, $performer) {
            $item->update(['status' => QueueItemStatus::Completed]);

            $this->historyService->logAction($item, 'completed', $performer);

            event(new PatientCompleted($item));
        });
    }
}
