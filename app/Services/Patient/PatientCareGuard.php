<?php

namespace App\Services\Patient;

use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\PatientStatus;
use Illuminate\Validation\ValidationException;

/**
 * The one rule for new care and new charges after a record is deleted
 * (`DeletePatient`).
 *
 * A deleted patient gets no new check-in or visit, booking, consultation,
 * prescription, clinical order, treatment, package, follow-up or bill.
 * Called inside each service that creates one, so every controller, job and
 * screen is covered. Existing records stay as they are, and existing bills
 * can still be paid and refunded. Messages are stopped separately
 * (`PatientMessageEligibility`).
 */
class PatientCareGuard
{
    /**
     * @throws ValidationException
     */
    public function assertCanReceiveCare(Patient $patient, string $field = 'patient_id'): void
    {
        if ($patient->status === PatientStatus::Deleted) {
            throw ValidationException::withMessages([$field => $this->message($patient)]);
        }
    }

    /**
     * The same rule for something new written into a consultation
     * (prescription, order, treatment plan): its patient must still be able
     * to receive care.
     *
     * @throws ValidationException
     */
    public function assertEncounterCanTakeNewCare(ClinicalEncounter $encounter): void
    {
        $patient = Patient::query()->withoutGlobalScopes()->where('clinic_id', $encounter->clinic_id)->find($encounter->patient_id);
        if ($patient) {
            $this->assertCanReceiveCare($patient);
        }
    }

    /**
     * A new bill for a deleted patient is refused.
     *
     * @throws ValidationException
     */
    public function assertCanBeCharged(Patient $patient, ?ClinicalEncounter $encounter): void
    {
        $this->assertCanReceiveCare($patient);
    }

    public function message(Patient $patient): string
    {
        return "{$patient->full_name}'s record was deleted. New visits, bookings, consultations and charges are not allowed.";
    }
}
