<?php

namespace App\Services\Patient;

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientClinicalEntry;
use App\Models\User;
use App\Services\Clinical\ClinicalCatalogue;
use App\Services\Reception\PatientFlowConfigService;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one writer of a patient's conditions, allergies and medications, and of
 * the safety alerts they raise.
 *
 * An entry is either a catalogue item (by key, checked against what the
 * clinic has enabled) or a custom label. Recording the same thing twice
 * updates the one row; removing it marks it resolved, never deletes it.
 *
 * Derived alerts (`allergy`, `medical_condition` rows in `patient_alerts`)
 * are rebuilt from the active entries after every write: every allergy, and
 * every condition whose severity is high or critical. Nothing about which
 * condition is dangerous lives in code.
 *
 * @phpstan-type EntryInput array{key?: ?string, label?: ?string, severity?: ?string, details?: ?array<string, mixed>}
 */
class PatientClinicalEntries
{
    public const DERIVED_ALERT_TYPES = ['medical_condition', 'allergy'];

    public function __construct(private ClinicalCatalogue $catalogue) {}

    /**
     * Turn submitted entries of one kind into checked rows: catalogue keys
     * must be enabled at the clinic, custom labels must be sensible. Done
     * before any write so a bad selection is a validation error.
     *
     * @param  list<EntryInput>  $entries
     * @return list<array{catalogue_key: ?string, label: string, dedupe_key: string, severity: ?string, details: ?array<string, mixed>}>
     */
    public function prepare(int $clinicId, string $kind, array $entries, string $field, string|array|null $specialtyKey = null, array $alreadyRecorded = [], bool $import = false): array
    {
        $this->assertEntryKind($kind);
        $items = collect($this->catalogue->effective($clinicId, $kind, $specialtyKey))->keyBy('key');
        // A clinic that keeps conditions to its own list refuses typed names that are not on it.
        // What the patient already has stays (`$alreadyRecorded`, dedupe keys), and history imports are never refused.
        $catalogueOnly = ! $import && $kind === DefaultClinicalCatalogue::KIND_CONDITION && $this->conditionsFromCatalogue($clinicId);

        $prepared = [];
        foreach (array_values($entries) as $index => $entry) {
            $key = $entry['key'] ?? null;
            $severity = $entry['severity'] ?? null;
            if ($severity !== null && ! in_array($severity, DefaultClinicalCatalogue::SEVERITIES, true)) {
                throw ValidationException::withMessages(["{$field}.{$index}.severity" => 'Choose low, moderate, high or critical.']);
            }

            if ($key && ! $items->has($key)) {
                // An item the clinic has switched off, archived or kept for
                // other specialties, or an unknown key with no name: refused.
                if ($this->catalogue->knows($clinicId, $kind, $key) || trim((string) ($entry['label'] ?? '')) === '') {
                    throw ValidationException::withMessages(["{$field}.{$index}" => 'That option is not available at this clinic.']);
                }
                $key = null; // A plain name that only looked like a key.
            }

            if ($key) {
                $item = $items->get($key);
                $prepared[$key] = ['catalogue_key' => $key, 'label' => $item['name'], 'dedupe_key' => $key, 'severity' => $severity ?? $item['severity'], 'details' => $entry['details'] ?? null];

                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 160) {
                throw ValidationException::withMessages(["{$field}.{$index}" => 'Enter a name of up to 160 characters.']);
            }
            // A typed name that matches an enabled item is that item.
            $match = $items->first(fn (array $item) => mb_strtolower($item['name']) === mb_strtolower($label));
            $dedupe = $match['key'] ?? mb_strtolower($label);
            if ($catalogueOnly && $match === null && ! in_array($dedupe, $alreadyRecorded, true)) {
                throw ValidationException::withMessages(["{$field}.{$index}" => "“{$label}” is not in this clinic's list of conditions. Choose one from the list, or ask an administrator to add it in Settings."]);
            }
            $prepared[$dedupe] = [
                'catalogue_key' => $match['key'] ?? null,
                'label' => $match['name'] ?? $label,
                'dedupe_key' => $dedupe,
                'severity' => $severity ?? $match['severity'] ?? ($kind === DefaultClinicalCatalogue::KIND_ALLERGY ? 'high' : null),
                'details' => $entry['details'] ?? null,
            ];
        }

        return array_values($prepared);
    }

    /** Whether the clinic records conditions only from its own catalogue (Settings → Patient form → Quick Care). */
    public function conditionsFromCatalogue(int $clinicId): bool
    {
        $clinic = Clinic::query()->withoutGlobalScopes()->find($clinicId);

        return app(PatientFlowConfigService::class)->quickCare($clinic)['conditions_from_catalogue'];
    }

    /**
     * The dedupe keys of a patient's active entries of one kind: what may be
     * kept as it is even where the clinic now keeps to its catalogue.
     *
     * @return list<string>
     */
    public function activeKeys(Patient $patient, string $kind): array
    {
        return PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', $kind)->where('status', PatientClinicalEntry::ACTIVE)->pluck('dedupe_key')->all();
    }

    /**
     * Add these entries (prepared by `prepare()`); existing ones are updated
     * and reactivated. Nothing already recorded is removed.
     *
     * @param  list<array{catalogue_key: ?string, label: string, dedupe_key: string, severity: ?string, details: ?array<string, mixed>}>  $prepared
     */
    public function add(Patient $patient, string $kind, array $prepared, string $source, ?User $by): bool
    {
        $changed = false;
        foreach ($prepared as $entry) {
            $changed = $this->upsert($patient, $kind, $entry, $source, $by) || $changed;
        }
        if ($changed) {
            $this->syncDerivedAlerts($patient);
        }

        return $changed;
    }

    /**
     * Before the first edit of a kind for a patient the backfill has not
     * reached: record what the legacy history holds as entries, so a change
     * resolves them (kept as history) rather than silently dropping them.
     */
    public function adoptLegacy(Patient $patient, string $kind): void
    {
        $exists = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('kind', $kind)->exists();
        if ($exists) {
            return;
        }

        $names = $this->legacyLabels($patient, $kind);
        if ($names !== []) {
            $this->addMissing($patient, $kind, $this->prepare($patient->clinic_id, $kind, array_map(fn (string $name) => ['label' => mb_substr($name, 0, 160)], $names), $kind, import: true), 'backfill', null);
        }
    }

    /**
     * Add only what the patient has never had recorded (any status): for the
     * backfill, which must never revive an entry a clinician resolved.
     *
     * @param  list<array{catalogue_key: ?string, label: string, dedupe_key: string, severity: ?string, details: ?array<string, mixed>}>  $prepared
     * @return int entries added
     */
    public function addMissing(Patient $patient, string $kind, array $prepared, string $source, ?User $by): int
    {
        $known = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('kind', $kind)
            ->pluck('dedupe_key')->all();

        $added = 0;
        foreach ($prepared as $entry) {
            if (! in_array($entry['dedupe_key'], $known, true)) {
                $this->upsert($patient, $kind, $entry, $source, $by);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Make the patient's active entries of one kind exactly these: missing
     * ones are added, the rest resolved (kept, not deleted).
     *
     * @param  list<array{catalogue_key: ?string, label: string, dedupe_key: string, severity: ?string, details: ?array<string, mixed>}>  $prepared
     */
    public function replace(Patient $patient, string $kind, array $prepared, string $source, ?User $by): bool
    {
        $changed = false;
        foreach ($prepared as $entry) {
            $changed = $this->upsert($patient, $kind, $entry, $source, $by) || $changed;
        }

        $resolved = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('kind', $kind)
            ->where('status', PatientClinicalEntry::ACTIVE)
            ->whereNotIn('dedupe_key', array_column($prepared, 'dedupe_key'))
            ->update(['status' => PatientClinicalEntry::RESOLVED, 'resolved_at' => now(), 'resolved_by' => $by?->id, 'updated_at' => now()]);

        if ($changed || $resolved > 0) {
            $this->syncDerivedAlerts($patient);
        }

        return $changed || $resolved > 0;
    }

    /** @return list<string> */
    public function allergyLabels(Patient $patient): array
    {
        return $this->labelsFor($patient, DefaultClinicalCatalogue::KIND_ALLERGY);
    }

    /**
     * The patient's active entry names of one kind.
     *
     * Compatibility, until `patients:backfill-clinical-entries` has run for
     * the clinic: a patient with no entries of this kind at all still shows
     * what the old medical-history columns hold. Read-only; nothing writes
     * those columns any more.
     *
     * @return list<string>
     */
    public function labelsFor(Patient $patient, string $kind): array
    {
        $entries = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', $kind)
            ->get(['label', 'status']);

        if ($entries->isNotEmpty()) {
            return $entries->where('status', PatientClinicalEntry::ACTIVE)->pluck('label')->sort()->values()->all();
        }

        return $this->legacyLabels($patient, $kind);
    }

    /**
     * What the old medical-history columns say for one kind (names only).
     *
     * @return list<string>
     */
    public function legacyLabels(Patient $patient, string $kind): array
    {
        $history = $patient->relationLoaded('medicalHistory') ? $patient->medicalHistory : $patient->medicalHistory()->withoutGlobalScopes()->first();
        if (! $history) {
            return [];
        }

        $names = match ($kind) {
            DefaultClinicalCatalogue::KIND_ALLERGY => $history->allergies ?? [],
            DefaultClinicalCatalogue::KIND_MEDICATION => $history->current_medications ?? [],
            DefaultClinicalCatalogue::KIND_CONDITION => [
                ...($history->medical_conditions ?? []),
                ...collect(DefaultClinicalCatalogue::legacyConditionColumns())
                    ->filter(fn (string $key, string $column) => (bool) $history->{$column})
                    ->map(fn (string $key) => collect(DefaultClinicalCatalogue::items())->firstWhere('key', $key)['name'] ?? $key)
                    ->values()->all(),
            ],
            default => [],
        };

        return collect($names)
            ->map(fn ($name) => trim(is_array($name) ? (string) ($name['name'] ?? $name['label'] ?? '') : (string) $name))
            ->filter()->unique(fn (string $name) => mb_strtolower($name))->values()->all();
    }

    /**
     * Active entries for many patients at once, grouped by patient.
     *
     * @param  list<int>  $patientIds
     * @return Collection<int, Collection<int, PatientClinicalEntry>>
     */
    public function activeFor(int $clinicId, array $patientIds): Collection
    {
        if ($patientIds === []) {
            return collect();
        }

        return PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_id', $patientIds)->where('status', PatientClinicalEntry::ACTIVE)
            ->orderBy('kind')->orderBy('label')
            ->get(['id', 'patient_id', 'kind', 'catalogue_key', 'label', 'severity', 'details', 'recorded_at'])
            ->groupBy('patient_id');
    }

    /**
     * The safety flags any role that can open the patient may see: every
     * allergy, conditions of high or critical severity, and high or critical
     * manual alerts. Labels and severity only, for many patients at once.
     * This is all a non-clinical role is ever sent.
     *
     * @param  list<int>  $patientIds
     * @return array<int, list<array{kind: string, label: string, severity: string}>>
     */
    public function safetyFlags(int $clinicId, array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        $flags = [];
        $entries = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_id', $patientIds)->where('status', PatientClinicalEntry::ACTIVE)
            ->where(fn ($query) => $query->where('kind', DefaultClinicalCatalogue::KIND_ALLERGY)
                ->orWhere(fn ($query) => $query->where('kind', DefaultClinicalCatalogue::KIND_CONDITION)->whereIn('severity', DefaultClinicalCatalogue::ALERTING_SEVERITIES)))
            ->get(['patient_id', 'kind', 'label', 'severity']);
        foreach ($entries as $entry) {
            $flags[$entry->patient_id][] = ['kind' => $entry->kind, 'label' => $entry->label, 'severity' => $entry->severity ?? 'high'];
        }

        $manual = PatientAlert::withoutGlobalScopes()
            ->where('clinic_id', $clinicId)->whereIn('patient_id', $patientIds)
            // Serious manual alerts, and every operational flag chosen at the desk (fall risk, needs assistance).
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('type', 'manual')->whereIn('severity', DefaultClinicalCatalogue::ALERTING_SEVERITIES))
                ->orWhere('type', 'flag'))
            ->get(['patient_id', 'description', 'severity']);
        foreach ($manual as $alert) {
            $flags[$alert->patient_id][] = ['kind' => 'alert', 'label' => $alert->description, 'severity' => $alert->severity];
        }

        $rank = array_flip(array_reverse(DefaultClinicalCatalogue::SEVERITIES));
        foreach ($flags as $patientId => $list) {
            usort($list, fn (array $a, array $b) => [$rank[$a['severity']] ?? 9, $a['label']] <=> [$rank[$b['severity']] ?? 9, $b['label']]);
            $flags[$patientId] = $list;
        }

        return $flags;
    }

    /** Rebuild the patient's derived alerts from their active entries. */
    public function syncDerivedAlerts(Patient $patient): void
    {
        $patient->alerts()->whereIn('type', self::DERIVED_ALERT_TYPES)->delete();

        $entries = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)->where('status', PatientClinicalEntry::ACTIVE)
            ->whereIn('kind', [DefaultClinicalCatalogue::KIND_ALLERGY, DefaultClinicalCatalogue::KIND_CONDITION])
            ->orderBy('label')->get(['kind', 'label', 'severity']);

        foreach ($entries as $entry) {
            if ($entry->kind === DefaultClinicalCatalogue::KIND_ALLERGY) {
                $patient->alerts()->create(['clinic_id' => $patient->clinic_id, 'type' => 'allergy', 'description' => 'Allergy: '.$entry->label, 'severity' => $entry->severity ?? 'high']);
            } elseif (in_array($entry->severity, DefaultClinicalCatalogue::ALERTING_SEVERITIES, true)) {
                $patient->alerts()->create(['clinic_id' => $patient->clinic_id, 'type' => 'medical_condition', 'description' => $entry->label, 'severity' => $entry->severity]);
            }
        }
    }

    /**
     * @param  array{catalogue_key: ?string, label: string, dedupe_key: string, severity: ?string, details: ?array<string, mixed>}  $entry
     */
    private function upsert(Patient $patient, string $kind, array $entry, string $source, ?User $by): bool
    {
        $existing = PatientClinicalEntry::withoutGlobalScopes()
            ->where('clinic_id', $patient->clinic_id)->where('patient_id', $patient->id)
            ->where('kind', $kind)->where('dedupe_key', $entry['dedupe_key'])->first();

        if (! $existing) {
            PatientClinicalEntry::withoutGlobalScopes()->create([
                'clinic_id' => $patient->clinic_id,
                'patient_id' => $patient->id,
                'kind' => $kind,
                'catalogue_key' => $entry['catalogue_key'],
                'label' => $entry['label'],
                'dedupe_key' => $entry['dedupe_key'],
                'severity' => $entry['severity'],
                'details' => $entry['details'],
                'status' => PatientClinicalEntry::ACTIVE,
                'source' => $source,
                'recorded_by' => $by?->id,
                'recorded_at' => now(),
            ]);

            return true;
        }

        $existing->fill([
            'status' => PatientClinicalEntry::ACTIVE,
            'severity' => $entry['severity'] ?? $existing->severity,
            'details' => $entry['details'] ?? $existing->details,
            'resolved_at' => null,
            'resolved_by' => null,
        ]);
        if (! $existing->isDirty()) {
            return false;
        }
        $existing->save();

        return true;
    }

    private function assertEntryKind(string $kind): void
    {
        if (! in_array($kind, DefaultClinicalCatalogue::ENTRY_KINDS, true)) {
            throw new \InvalidArgumentException("“{$kind}” is not recorded on a patient.");
        }
    }
}
