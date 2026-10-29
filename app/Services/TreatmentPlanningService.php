<?php

namespace App\Services;

use App\Events\LabRequested;
use App\Events\PrescriptionDrafted;
use App\Events\TreatmentPlanCreated;
use App\Events\XrayRequested;
use App\LabCaseStatus;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TreatmentPlan;
use App\Models\TreatmentRecord;
use App\Models\XrayRecord;
use App\Services\Patient\PatientCareGuard;
use App\XrayStatus;
use Illuminate\Support\Facades\DB;

class TreatmentPlanningService
{
    public function __construct(
        protected ClinicalIdentifierService $identifierService,
        protected ClinicalPrescriptionService $prescriptions,
    ) {}

    /**
     * Create a treatment plan containing procedures.
     */
    public function createTreatmentPlan(
        Patient $patient,
        Clinic $clinic,
        string $title,
        array $procedures = []
    ): TreatmentPlan {
        // A patient recorded as deceased gets no new care (`PatientCareGuard`), on this path too.
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);

        return DB::transaction(function () use ($patient, $clinic, $title, $procedures) {
            $plan = TreatmentPlan::create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'title' => $title,
                'status' => 'draft',
            ]);

            foreach ($procedures as $proc) {
                TreatmentRecord::create([
                    'patient_id' => $patient->id,
                    'treatment_plan_id' => $plan->id,
                    'code' => $proc['code'],
                    'description' => $proc['description'],
                    'tooth_number' => $proc['tooth_number'] ?? null,
                    'status' => $proc['status'] ?? 'proposed',
                    'price' => $proc['price'] ?? 0.00,
                ]);
            }

            event(new TreatmentPlanCreated($plan));

            return $plan;
        });
    }

    /**
     * Request a prescription order.
     */
    public function requestPrescription(
        ClinicalEncounter $encounter,
        ClinicEmployee $doctor,
        array $items,
        ?string $notes = null
    ): Prescription {
        // Through the one prescription writer, never a second Rx path.
        $rx = $this->prescriptions->issuePrescription($encounter, ['notes' => $notes], $items, $doctor);

        event(new PrescriptionDrafted($rx));

        return $rx;
    }

    /**
     * Request an X-Ray Scan.
     */
    public function requestXray(
        ClinicalEncounter $encounter,
        Patient $patient,
        ?string $notes = null
    ): XrayRecord {
        // A patient recorded as deceased gets no new care (`PatientCareGuard`), on this path too.
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);

        return DB::transaction(function () use ($encounter, $patient, $notes) {
            $xrNumber = $this->identifierService->generateXrayNumber($encounter->clinic);

            $xr = XrayRecord::create([
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'xray_number' => $xrNumber,
                'notes' => $notes,
                'status' => XrayStatus::Requested,
            ]);

            event(new XrayRequested($xr));

            return $xr;
        });
    }

    /**
     * Request a Lab Order.
     */
    public function requestLab(
        ClinicalEncounter $encounter,
        Patient $patient,
        ?string $notes = null
    ): LabOrder {
        // A patient recorded as deceased gets no new care (`PatientCareGuard`), on this path too.
        app(PatientCareGuard::class)->assertCanReceiveCare($patient);

        return DB::transaction(function () use ($encounter, $patient, $notes) {
            $lbNumber = $this->identifierService->generateLabOrderNumber($encounter->clinic);

            $lb = LabOrder::create([
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'lab_order_number' => $lbNumber,
                'notes' => $notes,
                'status' => LabCaseStatus::Requested,
            ]);

            event(new LabRequested($lb));

            return $lb;
        });
    }
}
