<?php

namespace App\Services\Clinical;

use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Services\Patient\PatientClinicalEntries;
use App\Support\Clinical\DefaultClinicalCatalogue;

/**
 * What is known about a patient's allergies at the moment a prescription is
 * written — read from the clinical record, never inferred:
 *
 * - `documented`: active allergy entries (or, for older records, the legacy
 *   allergy list). A clinician must review them before signing.
 * - `none_known`: reception or a clinician recorded "no known allergies".
 * - `not_recorded`: nothing either way. This is NOT "no allergies"; it is
 *   stated as unknown on the prescription's record.
 *
 * No drug–allergy matching: the record does not hold the data to do it
 * safely. The fingerprint changes whenever the allergy list does, so a review
 * made against an older list does not count.
 */
class PrescriptionAllergyCheck
{
    public const DOCUMENTED = 'documented';

    public const NONE_KNOWN = 'none_known';

    public const NOT_RECORDED = 'not_recorded';

    /**
     * @return array{status: string, allergies: list<array{label: string, severity: ?string, reaction: ?string}>, fingerprint: string}
     */
    public function stateFor(Patient $patient): array
    {
        $entries = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', DefaultClinicalCatalogue::KIND_ALLERGY)->where('status', PatientClinicalEntry::ACTIVE)
            ->orderBy('label')->get(['label', 'severity', 'details']);

        $allergies = $entries->isNotEmpty()
            ? $entries->map(fn (PatientClinicalEntry $entry) => ['label' => $entry->label, 'severity' => $entry->severity, 'reaction' => $entry->details['reaction'] ?? null])->values()->all()
            // Before any entry exists, the legacy list is the record (names only).
            : array_map(fn (string $label) => ['label' => $label, 'severity' => null, 'reaction' => null], app(PatientClinicalEntries::class)->legacyLabels($patient, DefaultClinicalCatalogue::KIND_ALLERGY));

        $status = match (true) {
            $allergies !== [] => self::DOCUMENTED,
            $patient->profile()->withoutGlobalScopes()->value('allergy_status') === 'none_known' => self::NONE_KNOWN,
            default => self::NOT_RECORDED,
        };

        return [
            'status' => $status,
            'allergies' => $allergies,
            'fingerprint' => hash('sha256', json_encode([$patient->clinic_id, $patient->id, $status, $allergies])),
        ];
    }
}
