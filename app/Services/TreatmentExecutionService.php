<?php

namespace App\Services;

use App\Events\BillableItemCreated;
use App\Events\TreatmentCompleted;
use App\Events\TreatmentPaused;
use App\Events\TreatmentStarted;
use App\Models\BillingReference;
use App\Models\ClinicEmployee;
use App\Models\TreatmentRecord;
use App\TreatmentStatus;
use Illuminate\Support\Facades\DB;

class TreatmentExecutionService
{
    public function __construct(
        protected ClinicalProgressService $progressService,
        protected ToothStatusHistoryService $toothHistoryService,
        protected ClinicalIdentifierService $identifierService
    ) {}

    /**
     * Start a treatment record.
     */
    public function startTreatment(TreatmentRecord $txRecord): void
    {
        DB::transaction(function () use ($txRecord) {
            $txRecord->update(['status' => TreatmentStatus::Started]);
            $this->progressService->logProgress($txRecord, 'started', 'Treatment execution initiated.');

            event(new TreatmentStarted($txRecord));
        });
    }

    /**
     * Pause a treatment record.
     */
    public function pauseTreatment(TreatmentRecord $txRecord, ?string $reason = null): void
    {
        DB::transaction(function () use ($txRecord, $reason) {
            $txRecord->update(['status' => TreatmentStatus::Paused]);
            $this->progressService->logProgress($txRecord, 'paused', $reason ?? 'Treatment execution paused.');

            event(new TreatmentPaused($txRecord));
        });
    }

    /**
     * Resume a paused treatment record.
     */
    public function resumeTreatment(TreatmentRecord $txRecord): void
    {
        DB::transaction(function () use ($txRecord) {
            $txRecord->update(['status' => TreatmentStatus::Resumed]);
            $this->progressService->logProgress($txRecord, 'resumed', 'Treatment execution resumed.');
        });
    }

    /**
     * Complete a treatment record, updating tooth status and preparing billing.
     */
    public function completeTreatment(TreatmentRecord $txRecord, ?ClinicEmployee $doctor = null): void
    {
        DB::transaction(function () use ($txRecord, $doctor) {
            $txRecord->update(['status' => TreatmentStatus::Completed]);
            $this->progressService->logProgress($txRecord, 'completed', 'Treatment execution successfully completed.');

            // 1. Update tooth status history if a tooth is mapped
            if ($txRecord->tooth_number && is_numeric($txRecord->tooth_number)) {
                $this->toothHistoryService->logToothStatus(
                    $txRecord->patient,
                    (int) $txRecord->tooth_number,
                    'completed',
                    'Completed procedure: '.$txRecord->description,
                    $txRecord,
                    $doctor
                );
            }

            // 2. Prepare billing reference
            $clinic = $txRecord->encounter ? $txRecord->encounter->clinic : $txRecord->patient->clinic;
            $billingRefNumber = $this->identifierService->generateBillingReference($clinic);

            $billingRef = BillingReference::create([
                'patient_id' => $txRecord->patient_id,
                'encounter_id' => $txRecord->encounter_id,
                'treatment_record_id' => $txRecord->id,
                'billing_reference_number' => $billingRefNumber,
                'amount' => $txRecord->price,
                'status' => 'pending',
            ]);

            event(new BillableItemCreated($billingRef));
            event(new TreatmentCompleted($txRecord));
        });
    }

    /**
     * Abort a treatment record.
     */
    public function abortTreatment(TreatmentRecord $txRecord, ?string $reason = null): void
    {
        DB::transaction(function () use ($txRecord, $reason) {
            $txRecord->update(['status' => TreatmentStatus::Aborted]);
            $this->progressService->logProgress($txRecord, 'aborted', $reason ?? 'Treatment execution aborted.');
        });
    }
}
