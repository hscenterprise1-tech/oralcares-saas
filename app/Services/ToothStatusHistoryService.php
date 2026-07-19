<?php

namespace App\Services;

use App\Events\ToothStatusUpdated;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\PatientToothStatusHistory;
use App\Models\TreatmentRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ToothStatusHistoryService
{
    public function __construct(
        protected DentalChartService $chartService
    ) {}

    /**
     * Log a tooth status transition, preserving history and updating the dental chart cache.
     */
    public function logToothStatus(
        Patient $patient,
        int $toothNumber,
        string $status,
        ?string $notes = null,
        ?TreatmentRecord $txRecord = null,
        ?ClinicEmployee $doctor = null
    ): PatientToothStatusHistory {
        if (! $this->chartService->isValidFdiToothNumber($toothNumber)) {
            throw new \InvalidArgumentException("Tooth number '{$toothNumber}' is not a valid FDI identifier.");
        }

        return DB::transaction(function () use ($patient, $toothNumber, $status, $notes, $txRecord, $doctor) {
            // 1. Log immutable history entry
            $history = PatientToothStatusHistory::create([
                'patient_id' => $patient->id,
                'tooth_number' => $toothNumber,
                'status' => $status,
                'notes' => $notes,
                'treatment_record_id' => $txRecord?->id,
                'updated_by_doctor_id' => $doctor?->id,
            ]);

            // 2. Update/Sync cached row in patient_dental_charts
            $this->chartService->updateToothStatus($patient, $toothNumber, $status, $notes, $doctor);

            event(new ToothStatusUpdated($history));

            return $history;
        });
    }

    /**
     * Retrieve the chronological history of a specific tooth.
     */
    public function getToothStatusHistory(Patient $patient, int $toothNumber): Collection
    {
        return PatientToothStatusHistory::where('patient_id', $patient->id)
            ->where('tooth_number', $toothNumber)
            ->orderBy('created_at')
            ->get();
    }
}
