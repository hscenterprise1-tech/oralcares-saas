<?php

namespace App\Services;

use App\Models\BillingReference;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\XrayRecord;

class ClinicalIdentifierService
{
    /**
     * Generate unique Patient number.
     */
    public function generatePatientNumber(Clinic $clinic): string
    {
        $count = Patient::where('clinic_id', $clinic->id)->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "PT-{$clinic->id}-{$padded}";
    }

    /**
     * Generate unique Encounter/Visit number.
     */
    public function generateVisitNumber(Clinic $clinic): string
    {
        $count = ClinicalEncounter::where('clinic_id', $clinic->id)->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "ENC-{$clinic->id}-{$padded}";
    }

    /**
     * Generate unique Prescription number.
     */
    public function generatePrescriptionNumber(Clinic $clinic): string
    {
        $count = Prescription::whereHas('encounter', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "RX-{$clinic->id}-{$padded}";
    }

    /**
     * Generate unique X-Ray scan number.
     */
    public function generateXrayNumber(Clinic $clinic): string
    {
        $count = XrayRecord::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "XR-{$clinic->id}-{$padded}";
    }

    /**
     * Generate unique Lab Order number.
     */
    public function generateLabOrderNumber(Clinic $clinic): string
    {
        $count = LabOrder::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "LB-{$clinic->id}-{$padded}";
    }

    /**
     * Generate unique Billing Reference number.
     */
    public function generateBillingReference(Clinic $clinic): string
    {
        $count = BillingReference::whereHas('patient', function ($q) use ($clinic) {
            $q->where('clinic_id', $clinic->id);
        })->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "BL-{$clinic->id}-{$padded}";
    }
}
