<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Services\Patient\PatientClinicalEntries;
use App\Support\Clinical\DefaultClinicalCatalogue;

class MedicalHistoryService
{
    /** Alert types rebuilt from the medical history; every other type (manual) is left alone. */
    public const DERIVED_ALERT_TYPES = ['medical_condition', 'allergy'];

    /**
     * Save legacy medical-history fields. Conditions and allergies are no
     * longer kept here: any sent (the allergy list, the old yes/no condition
     * columns) are recorded as clinical entries, which raise the alerts.
     * Nothing about which condition is dangerous lives in this code.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateMedicalHistory(Patient $patient, array $data): PatientMedicalHistory
    {
        $history = PatientMedicalHistory::updateOrCreate(['patient_id' => $patient->id], $data);

        $entries = app(PatientClinicalEntries::class);
        if (array_key_exists('allergies', $data)) {
            $allergies = $entries->prepare($patient->clinic_id, DefaultClinicalCatalogue::KIND_ALLERGY, collect($data['allergies'] ?? [])->map(fn ($name) => ['label' => (string) $name])->all(), 'allergies');
            $entries->replace($patient, DefaultClinicalCatalogue::KIND_ALLERGY, $allergies, 'clinical', auth()->user());
        }

        $conditions = collect(DefaultClinicalCatalogue::legacyConditionColumns())
            ->filter(fn (string $key, string $column) => ! empty($data[$column]))
            ->map(fn (string $key) => ['key' => $key, 'label' => $key])->values()->all();
        if ($conditions !== []) {
            $entries->add($patient, DefaultClinicalCatalogue::KIND_CONDITION, $entries->prepare($patient->clinic_id, DefaultClinicalCatalogue::KIND_CONDITION, $conditions, 'conditions'), 'clinical', auth()->user());
        }

        return $history;
    }

    /**
     * Make the patient's manual safety alerts exactly these texts (an empty
     * list clears them). Unchanged alerts keep their row and date.
     *
     * @param  list<string>  $texts
     * @return bool whether anything changed
     */
    public function syncManualAlerts(Patient $patient, array $texts, string $severity = 'high'): bool
    {
        $wanted = collect($texts)->map(fn ($text) => trim((string) $text))->filter()->unique()->values();
        $current = $patient->alerts()->where('type', 'manual')->get();

        $stale = $current->reject(fn ($alert) => $wanted->contains($alert->description));
        $missing = $wanted->reject(fn ($text) => $current->contains('description', $text));

        if ($stale->isNotEmpty()) {
            $patient->alerts()->whereKey($stale->pluck('id'))->delete();
        }
        foreach ($missing as $text) {
            $patient->alerts()->create(['type' => 'manual', 'description' => $text, 'severity' => $severity]);
        }

        return $stale->isNotEmpty() || $missing->isNotEmpty();
    }
}
