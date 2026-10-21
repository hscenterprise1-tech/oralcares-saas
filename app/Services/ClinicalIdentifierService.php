<?php

namespace App\Services;

use App\ClinicDocument;
use App\Models\BillingReference;
use App\Models\Clinic;
use App\Models\Patient;
use App\Services\Numbering\ClinicDocumentNumbers;

/**
 * Clinical document numbers for the older clinical services. Every number
 * here is issued by `ClinicDocumentNumbers` from a locked sequence; it used
 * to be "count the existing rows + 1", which two concurrent requests both
 * read as the same number.
 */
class ClinicalIdentifierService
{
    public function __construct(protected ClinicDocumentNumbers $numbers) {}

    /**
     * Legacy `patients.patient_number` (patients are identified by UHID, from
     * `UhidGenerator`). Kept for the old registration path only.
     */
    public function generatePatientNumber(Clinic $clinic): string
    {
        $count = Patient::where('clinic_id', $clinic->id)->count() + 1;
        $padded = str_pad((string) $count, 5, '0', STR_PAD_LEFT);

        return "PT-{$clinic->id}-{$padded}";
    }

    public function generateVisitNumber(Clinic $clinic): string
    {
        return $this->numbers->next(ClinicDocument::Encounter, $clinic);
    }

    public function generatePrescriptionNumber(Clinic $clinic): string
    {
        return $this->numbers->next(ClinicDocument::Prescription, $clinic);
    }

    public function generateXrayNumber(Clinic $clinic): string
    {
        return $this->numbers->next(ClinicDocument::Imaging, $clinic);
    }

    public function generateLabOrderNumber(Clinic $clinic): string
    {
        return $this->numbers->next(ClinicDocument::LabOrder, $clinic);
    }

    /**
     * Legacy treatment-execution billing reference (clinic bills carry their
     * own invoice numbers). Count-based, kept for that path only.
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
