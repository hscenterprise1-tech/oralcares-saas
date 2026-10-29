<?php

namespace App\Services\Patient;

use App\Models\ClinicalEncounter;
use App\Models\Patient;
use App\PatientStatus;
use Illuminate\Validation\ValidationException;

/**
 * The one rule for new care and new charges after a death is recorded.
 *
 * A patient recorded as deceased gets no new check-in or visit, booking,
 * consultation, prescription, clinical order, treatment, package, follow-up
 * or bill. Called inside each service that creates one, so every controller,
 * job and screen is covered.
 *
 * Kept, on purpose:
 * - every existing record stays readable;
 * - existing bills can still be paid and refunded;
 * - a bill for care given on or before the date of death (a consultation
 *   that started then) can still be issued — the work was done;
 * - an open consultation can still be written up and signed.
 * Messages are stopped separately (`PatientMessageEligibility`).
 */
class PatientCareGuard
{
    /**
     * @throws ValidationException
     */
    public function assertCanReceiveCare(Patient $patient, string $field = 'patient_id'): void
    {
        if ($patient->status === PatientStatus::Deceased) {
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
     * A bill may still be issued for care given on or before the date of
     * death; anything else for a deceased patient is refused.
     *
     * @throws ValidationException
     */
    public function assertCanBeCharged(Patient $patient, ?ClinicalEncounter $encounter): void
    {
        if ($patient->status !== PatientStatus::Deceased) {
            return;
        }
        $died = $patient->date_of_death?->toDateString();
        $given = $encounter?->started_at?->toDateString();
        if ($died !== null && $given !== null && $given <= $died) {
            return;
        }

        throw ValidationException::withMessages(['patient_id' => $this->message($patient)]);
    }

    public function message(Patient $patient): string
    {
        $on = $patient->date_of_death ? ' on '.$patient->date_of_death->format('d M Y') : '';

        return "{$patient->full_name} is recorded as deceased{$on}. New visits, bookings, consultations, prescriptions, orders and charges are not allowed.";
    }
}
