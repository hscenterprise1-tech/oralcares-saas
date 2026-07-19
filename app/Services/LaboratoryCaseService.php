<?php

namespace App\Services;

use App\Events\LaboratoryCaseCreated;
use App\LabCaseStatus;
use App\LabTrialType;
use App\Models\Branch;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\LabCaseTrial;
use App\Models\LabOrder;
use App\Models\LabOrderAttachment;
use App\Models\Patient;
use App\Models\TreatmentRecord;
use Illuminate\Support\Facades\DB;

class LaboratoryCaseService
{
    public function __construct(
        protected ClinicalIdentifierService $identifierService
    ) {}

    /**
     * Create a new laboratory prosthetic case.
     */
    public function createCase(
        Patient $patient,
        ClinicalEncounter $encounter,
        TreatmentRecord $txRecord,
        ClinicEmployee $doctor,
        Branch $branch,
        array $caseDetails
    ): LabOrder {
        return DB::transaction(function () use ($patient, $encounter, $txRecord, $doctor, $branch, $caseDetails) {
            $caseNumber = $this->identifierService->generateLabOrderNumber($encounter->clinic);

            $labCase = LabOrder::create([
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'treatment_record_id' => $txRecord->id,
                'requesting_doctor_id' => $doctor->id,
                'branch_id' => $branch->id,
                'lab_order_number' => $caseNumber,

                'status' => LabCaseStatus::Requested,
                'laboratory_name' => $caseDetails['laboratory_name'] ?? null,
                'is_internal' => $caseDetails['is_internal'] ?? false,
                'lab_prosthetic_id' => $caseDetails['prosthetic_id'] ?? null,
                'lab_material_id' => $caseDetails['material_id'] ?? null,
                'lab_shade_id' => $caseDetails['shade_id'] ?? null,
                'requested_teeth' => $caseDetails['requested_teeth'] ?? [],
                'delivery_due_date' => $caseDetails['delivery_due_date'] ?? null,
                'notes' => $caseDetails['notes'] ?? null,
            ]);

            event(new LaboratoryCaseCreated($labCase));

            return $labCase;
        });
    }

    /**
     * Record a trial or intermediate fitting stage.
     */
    public function recordTrial(
        LabOrder $labCase,
        LabTrialType $trialType,
        string $scheduledAt,
        ?string $notes = null,
        ?string $outcome = null
    ): LabCaseTrial {
        return LabCaseTrial::create([
            'lab_order_id' => $labCase->id,
            'trial_type' => $trialType,
            'scheduled_at' => $scheduledAt,
            'notes' => $notes,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Attach design file or photo metadata to the case.
     */
    public function attachFile(
        LabOrder $labCase,
        string $fileName,
        string $storagePath,
        string $fileType
    ): LabOrderAttachment {
        return LabOrderAttachment::create([
            'lab_order_id' => $labCase->id,
            'file_name' => $fileName,
            'storage_path' => $storagePath,
            'file_type' => $fileType,
        ]);
    }
}
