<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsPerClinic;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientClinicalEntry;
use App\Services\Patient\PatientClinicalEntries;
use App\Services\Security\SecurityAudit;
use App\Support\Clinical\DefaultClinicalCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves each patient's conditions, allergies and medications from the old
 * medical-history columns into `patient_clinical_entries` (source
 * `backfill`), then rebuilds their safety alerts from the entries.
 *
 * Additive and idempotent: an entry is only added if the patient has never
 * had it recorded, so re-running never revives something a clinician
 * resolved. The legacy columns are read, never changed or cleared; they are
 * retired only in a later approved cleanup.
 *
 * Verification per clinic: patients with legacy data, legacy items found,
 * entries added, and patients with legacy data who still have no entries
 * (must be 0). `--dry-run` does all of it in a transaction rolled back.
 */
class BackfillPatientClinicalEntries extends Command
{
    use RunsPerClinic;

    protected $signature = 'patients:backfill-clinical-entries
        {--clinic=* : Clinic id(s)}
        {--all-clinics : Every active clinic}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Backfill conditions, allergies and medications from the legacy medical history into clinical entries, and verify';

    private const CHUNK = 500;

    public function handle(PatientClinicalEntries $entries, SecurityAudit $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        $this->forEachSelectedClinic(function (Clinic $clinic) use ($entries, $audit, $dryRun, &$rows) {
            DB::beginTransaction();
            try {
                $stats = ['clinic' => $clinic->id, 'patients_with_legacy' => 0, 'legacy_items' => 0, 'entries_added' => 0, 'unmatched_kept_as_custom' => 0, 'skipped_items' => 0, 'unverified_patients' => 0];

                DB::table('patient_medical_histories')->where('clinic_id', $clinic->id)->orderBy('id')
                    ->chunkById(self::CHUNK, function ($histories) use ($clinic, $entries, &$stats) {
                        $patients = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)
                            ->whereIn('id', $histories->pluck('patient_id'))->with('medicalHistory')->get()->keyBy('id');

                        foreach ($histories as $history) {
                            $patient = $patients->get($history->patient_id);
                            if (! $patient) {
                                continue;
                            }
                            $legacy = [];
                            foreach (DefaultClinicalCatalogue::ENTRY_KINDS as $kind) {
                                $names = $entries->legacyLabels($patient, $kind);
                                if ($names !== []) {
                                    $legacy[$kind] = $names;
                                }
                            }
                            if ($legacy === []) {
                                continue;
                            }

                            $stats['patients_with_legacy']++;
                            $changed = false;
                            foreach ($legacy as $kind => $names) {
                                $stats['legacy_items'] += count($names);
                                $prepared = $this->prepare($entries, $clinic, $kind, $names);
                                $stats['skipped_items'] += count($names) - count($prepared);
                                $stats['unmatched_kept_as_custom'] += count(array_filter($prepared, fn (array $entry) => $entry['catalogue_key'] === null));
                                $added = $entries->addMissing($patient, $kind, $prepared, 'backfill', null);
                                $stats['entries_added'] += $added;
                                $changed = $changed || $added > 0;
                            }
                            if ($changed) {
                                $entries->syncDerivedAlerts($patient);
                            }

                            if (! PatientClinicalEntry::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('patient_id', $patient->id)->exists()) {
                                $stats['unverified_patients']++;
                            }
                        }
                    });

                if ($dryRun) {
                    DB::rollBack();
                } else {
                    $audit->record('patient.clinical_entries.backfilled', $clinic, collect($stats)->except('clinic')->all(), ['clinic_id' => $clinic->id]);
                    DB::commit();
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
            $rows[] = $stats;
        });

        if ($rows === []) {
            $this->warn('No clinic processed.');

            return self::SUCCESS;
        }

        $this->table(array_keys($rows[0]), $rows);
        $unverified = array_sum(array_column($rows, 'unverified_patients'));
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Backfill complete.');
        if ($unverified > 0) {
            $this->error("{$unverified} patient(s) with legacy data have no clinical entries. Investigate before retiring the legacy columns.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Each legacy name as an entry: a name matching an item the clinic has
     * enabled becomes that item, anything else a custom entry. A name the
     * entry rules refuse (too long) is shortened rather than lost.
     *
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    private function prepare(PatientClinicalEntries $entries, Clinic $clinic, string $kind, array $names): array
    {
        $input = array_map(fn (string $name) => ['label' => mb_substr($name, 0, 160)], $names);

        try {
            return $entries->prepare($clinic->id, $kind, $input, $kind, import: true);
        } catch (ValidationException) {
            return [];
        }
    }
}
