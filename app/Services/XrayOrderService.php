<?php

namespace App\Services;

use App\Events\XrayOrdered;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Models\XrayRecord;
use App\XrayCategory;
use App\XrayRegion;
use App\XrayStatus;
use Illuminate\Support\Facades\DB;

class XrayOrderService
{
    public function __construct(
        protected ClinicalIdentifierService $identifierService
    ) {}

    /**
     * Create a new clinical X-Ray order.
     */
    public function createOrder(
        Patient $patient,
        ClinicalEncounter $encounter,
        ClinicEmployee $doctor,
        XrayCategory $category,
        XrayRegion $region,
        ?array $teeth = [],
        ?string $reason = null,
        ?string $priority = 'normal'
    ): XrayRecord {
        return DB::transaction(function () use ($patient, $encounter, $doctor, $category, $region, $teeth, $reason, $priority) {
            $xrayNumber = $this->identifierService->generateXrayNumber($encounter->clinic);

            $order = XrayRecord::create([
                'patient_id' => $patient->id,
                'encounter_id' => $encounter->id,
                'xray_number' => $xrayNumber,
                'status' => XrayStatus::Requested,
                'category' => $category,
                'requesting_doctor_id' => $doctor->id,
                'requested_region' => $region,
                'requested_teeth' => $teeth,
                'clinical_reason' => $reason,
                'priority' => $priority,
            ]);

            event(new XrayOrdered($order));

            return $order;
        });
    }

    /**
     * Transition the X-Ray order lifecycle status.
     */
    public function transitionStatus(XrayRecord $xray, XrayStatus $newStatus): void
    {
        $xray->update(['status' => $newStatus]);
    }
}
