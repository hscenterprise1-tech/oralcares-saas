<?php

namespace App\Services\Communication;

use App\Models\Patient;
use App\PatientStatus;

/**
 * Whether a patient may be sent messages, decided from the record's
 * lifecycle state alone — the one rule every patient message passes, when it
 * is queued (`PatientMessaging::send`), when it is due (`NotificationService`),
 * just before WhatsApp hands it to the provider (`SendClinicWhatsAppMessage`)
 * and when a failed WhatsApp message is retried.
 *
 * A duplicate merged into another record and a patient recorded as deceased
 * are never messaged. A record that cannot be found is not messaged either.
 * Everything else (today `active` and `inactive`) is.
 *
 * Consent, channel and provider checks stay where they are; this only answers
 * "may this patient be contacted at all", with a reason a person can read.
 */
class PatientMessageEligibility
{
    public const MERGED_REASON = 'Not sent: the patient record was merged into another record.';

    public const DECEASED_REASON = 'Not sent: the patient is recorded as deceased.';

    public const MISSING_REASON = 'Not sent: the patient record was not found.';

    /**
     * Why this patient may not be messaged, or null when they may.
     */
    public function blockReason(?Patient $patient): ?string
    {
        if ($patient === null) {
            return self::MISSING_REASON;
        }

        return match ($patient->status) {
            PatientStatus::Merged => self::MERGED_REASON,
            PatientStatus::Deceased => self::DECEASED_REASON,
            default => null,
        };
    }

    /**
     * The same answer for a patient known only by id, read fresh from the
     * database within the clinic (callers run in jobs, outside a request's
     * clinic scope).
     */
    public function blockReasonFor(int $clinicId, int $patientId): ?string
    {
        return $this->blockReason(
            Patient::query()->withoutGlobalScopes()->where('clinic_id', $clinicId)->find($patientId)
        );
    }
}
