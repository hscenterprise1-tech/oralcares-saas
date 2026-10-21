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
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
     * Order lab work from a consultation (crown, bridge, denture, aligner…):
     * the teeth, the restoration, shade and material (from the clinic's lab
     * masters when set up, else as written), the lab and the date expected.
     * Optionally tied to the plan item it fulfils. Starts as an order the
     * clinic has not yet sent.
     *
     * @param  array{teeth: list<int>, restoration: string, laboratory_name?: ?string, shade?: ?string, material?: ?string, delivery_due_date?: ?string, notes?: ?string, treatment_record_id?: ?int}  $details
     */
    public function order(ClinicalEncounter $encounter, ClinicEmployee $doctor, array $details): LabOrder
    {
        return DB::transaction(function () use ($encounter, $doctor, $details) {
            /** @var ClinicalEncounter $locked */
            $locked = ClinicalEncounter::query()->withoutGlobalScopes()->whereKey($encounter->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw new DomainException('This consultation is signed. Order lab work in an open consultation.');
            }
            $txId = $details['treatment_record_id'] ?? null;
            if ($txId && ! TreatmentRecord::query()->withoutGlobalScopes()->whereKey($txId)->where('clinic_id', $locked->clinic_id)->where('patient_id', $locked->patient_id)->exists()) {
                throw new InvalidArgumentException('That treatment is not this patient\'s.');
            }

            $order = LabOrder::create([
                'clinic_id' => $locked->clinic_id,
                'patient_id' => $locked->patient_id,
                'encounter_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'treatment_record_id' => $txId,
                'requesting_doctor_id' => $doctor->id,
                'lab_order_number' => $this->identifierService->generateLabOrderNumber($locked->clinic),
                'status' => LabCaseStatus::Draft,
                'laboratory_name' => $details['laboratory_name'] ?? null,
                'requested_teeth' => array_values($details['teeth']),
                'restoration' => $details['restoration'],
                'shade' => $details['shade'] ?? null,
                'material' => $details['material'] ?? null,
                'delivery_due_date' => $details['delivery_due_date'] ?? null,
                'notes' => $details['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            app(LaboratoryWorkflowService::class)->recordStep($order, 'lab_order_created', null);

            return $order;
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
