<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsPerClinic;
use App\Models\Clinic;
use App\Models\ClinicSpecialty;
use App\Services\Patient\ClinicSpecialties;
use App\Services\Patient\PatientBranchActivityRecorder;
use App\Services\Security\SecurityAudit;
use App\Support\Specialty\SpecialtyRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Patient foundation backfill (Phase 2). Additive and idempotent: run it as
 * often as needed. Per clinic, inside the clinic's tenant context:
 *
 *  1. clinic_specialties      from the legacy `clinics.specialties` JSON
 *  2. patient_specialties     from encounter specialties; every patient of a
 *                             single-specialty clinic (source `backfill`)
 *  3. patient_branch_activity from visits, appointments, encounters, bills
 *  4. profile consolidation   alternate phone → patients.alternate_phone,
 *                             pincode → postal_code; disagreements become
 *                             `patient_data_conflicts`, nothing is overwritten
 *  5. medical basics          chronic_conditions merged into medical_conditions,
 *                             history medical_alerts → manual patient_alerts
 *  6. encounter timing        from clinical_consultations where the encounter
 *                             has none
 *  7. consents                captured_at from signed_at for older rows
 *
 * then prints the verification counts. `--dry-run` does all of it inside a
 * transaction that is rolled back.
 */
class BackfillPatientFoundation extends Command
{
    use RunsPerClinic;

    protected $signature = 'patients:backfill-foundation
        {--clinic=* : Clinic id(s)}
        {--all-clinics : Every active clinic}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Backfill the patient foundation (specialties, branch activity, consolidated profile/medical data) and verify it';

    private const CHUNK = 500;

    public function handle(ClinicSpecialties $clinicSpecialties, PatientBranchActivityRecorder $recorder, SpecialtyRegistry $registry, SecurityAudit $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];
        $actions = [];

        $this->forEachSelectedClinic(function (Clinic $clinic) use ($clinicSpecialties, $recorder, $registry, $audit, $dryRun, &$rows, &$actions) {
            DB::beginTransaction();
            try {
                $stats = [
                    'clinic' => $clinic->id,
                    'unmapped_specialty_keys' => $this->clinicSpecialties($clinic, $clinicSpecialties, $registry),
                    'enrolments_added' => $this->patientSpecialties($clinic),
                    'branch_rows' => $this->branchActivity($clinic, $recorder),
                    'single_branch_attributed' => $this->singleBranchAttribution($clinic, $recorder),
                    ...$this->profiles($clinic),
                    ...$this->medical($clinic),
                    'encounters_timed' => $this->encounterTiming($clinic),
                    'consents_dated' => $this->consents($clinic),
                ];
                $stats += $this->verify($clinic);
                $actions[$clinic->id] = ['clinic' => "{$clinic->id} {$clinic->name}", ...$this->ownerActions($clinic)];

                if ($dryRun) {
                    DB::rollBack();
                } else {
                    $audit->record('patient.foundation.backfilled', $clinic, collect($stats)->except('clinic')->all(), ['clinic_id' => $clinic->id]);
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

        $this->table(array_keys($rows[0]), array_map(fn (array $row) => array_map(fn ($value) => is_array($value) ? implode(',', $value) : $value, $row), $rows));
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Backfill complete.');

        $pending = collect($actions)->filter(fn (array $a) => $a['patients_needing_branch'] !== [] || $a['needs_specialty_configuration']);
        if ($pending->isNotEmpty()) {
            $this->warn('Needs the clinic owner (nothing was assumed):');
            $this->table(['clinic', 'patients with no branch to attribute (first 50)', 'no active specialty configured'], $pending->map(fn (array $a) => [
                $a['clinic'], implode(',', $a['patients_needing_branch']) ?: '—', $a['needs_specialty_configuration'] ? 'yes — activate the clinic\'s specialties, then re-run' : '—',
            ])->values()->all());
        }

        return self::SUCCESS;
    }

    /** @return list<string> legacy keys the registry does not know (left in the JSON) */
    private function clinicSpecialties(Clinic $clinic, ClinicSpecialties $clinicSpecialties, SpecialtyRegistry $registry): array
    {
        $legacy = array_values(array_filter((array) ($clinic->specialties ?? []), 'is_string'));
        if ($legacy !== []) {
            $clinicSpecialties->mirrorLegacy($clinic);
        }

        return array_values(array_filter($legacy, fn (string $key) => $registry->normalize([$key]) === []));
    }

    private function patientSpecialties(Clinic $clinic): int
    {
        $specialties = ClinicSpecialty::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->get()->keyBy('specialty_key');
        if ($specialties->isEmpty()) {
            return 0;
        }
        $active = $specialties->where('status', ClinicSpecialty::ACTIVE);
        $added = 0;
        $now = now();

        DB::table('patients')->where('clinic_id', $clinic->id)->orderBy('id')->select(['id', 'created_at'])
            ->chunkById(self::CHUNK, function (Collection $patients) use ($clinic, $specialties, $active, $now, &$added) {
                $byPatient = DB::table('clinical_encounters')->where('clinic_id', $clinic->id)->whereIn('patient_id', $patients->pluck('id'))
                    ->whereNotNull('specialty')->whereNull('voided_at')
                    ->groupBy('patient_id', 'specialty')
                    ->selectRaw('patient_id, specialty, min(coalesce(started_at, created_at)) as first_at, max(coalesce(started_at, created_at)) as last_at')
                    ->get()->groupBy('patient_id');

                $insert = [];
                foreach ($patients as $patient) {
                    $seen = $byPatient->get($patient->id, collect())->keyBy('specialty');
                    // A single-specialty clinic: every patient belongs to it.
                    if ($active->count() === 1 && $seen->isEmpty()) {
                        $seen = collect([$active->keys()->first() => (object) ['first_at' => $patient->created_at, 'last_at' => null]]);
                    }
                    foreach ($seen as $key => $activity) {
                        $specialty = $specialties->get($key);
                        if (! $specialty) {
                            continue;
                        }
                        $insert[] = [
                            'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'clinic_specialty_id' => $specialty->id,
                            'status' => 'active', 'source' => 'backfill', 'enrolled_at' => $activity->first_at ?? $patient->created_at ?? $now,
                            'last_activity_at' => $activity->last_at, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
                $added += $insert === [] ? 0 : DB::table('patient_specialties')->insertOrIgnore($insert);
            });

        return $added;
    }

    private function branchActivity(Clinic $clinic, PatientBranchActivityRecorder $recorder): int
    {
        $sources = [
            'visit' => ['patient_visits', 'created_at'],
            'appointment' => ['appointments', 'created_at'],
            'encounter' => ['clinical_encounters', 'coalesce(started_at, created_at)'],
            'billing' => ['patient_billings', 'created_at'],
        ];
        $before = DB::table('patient_branch_activity')->where('clinic_id', $clinic->id)->count();

        DB::table('patients')->where('clinic_id', $clinic->id)->orderBy('id')->select('id')
            ->chunkById(self::CHUNK, function (Collection $patients) use ($clinic, $sources, $recorder) {
                // One grouped read and one batched upsert per source and chunk, never a write per row.
                foreach ($sources as $source => [$table, $at]) {
                    $recorder->recordMany($clinic->id, DB::table($table)->where('clinic_id', $clinic->id)->whereIn('patient_id', $patients->pluck('id'))->whereNotNull('branch_id')
                        ->groupBy('patient_id', 'branch_id')
                        ->selectRaw("patient_id, branch_id, min({$at}) as first_at, max({$at}) as last_at")
                        ->get()
                        ->map(fn ($row) => ['patient_id' => (int) $row->patient_id, 'branch_id' => (int) $row->branch_id, 'first_at' => $row->first_at, 'last_at' => $row->last_at])
                        ->all(), $source);
                }
            });

        return DB::table('patient_branch_activity')->where('clinic_id', $clinic->id)->count() - $before;
    }

    /**
     * A patient with no recorded activity in a clinic that has exactly one
     * branch can only have been registered there: record that branch. In a
     * multi-branch clinic nothing is assumed — those patients are listed for
     * the clinic to attribute.
     */
    private function singleBranchAttribution(Clinic $clinic, PatientBranchActivityRecorder $recorder): int
    {
        $branches = DB::table('branches')->where('clinic_id', $clinic->id)->pluck('id');
        if ($branches->count() !== 1) {
            return 0;
        }

        $attributed = 0;
        DB::table('patients')->where('clinic_id', $clinic->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patient_branch_activity as a')->whereColumn('a.patient_id', 'patients.id'))
            ->orderBy('id')->select(['id', 'created_at'])
            ->chunkById(self::CHUNK, function (Collection $patients) use ($clinic, $branches, $recorder, &$attributed) {
                $recorder->recordMany($clinic->id, $patients->map(fn ($patient) => [
                    'patient_id' => (int) $patient->id, 'branch_id' => (int) $branches->first(),
                    'first_at' => $patient->created_at ?? now(), 'last_at' => $patient->created_at ?? now(),
                ])->all(), 'backfill');
                $attributed += $patients->count();
            });

        return $attributed;
    }

    /**
     * What the backfill cannot decide and the clinic must: patients with no
     * branch to attribute (multi-branch clinics), and a clinic with patients
     * but no configured specialty (never defaulted).
     *
     * @return array{patients_needing_branch: list<int>, needs_specialty_configuration: bool}
     */
    private function ownerActions(Clinic $clinic): array
    {
        return [
            'patients_needing_branch' => DB::table('patients')->where('clinic_id', $clinic->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patient_branch_activity as a')->whereColumn('a.patient_id', 'patients.id'))
                ->orderBy('id')->limit(50)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'needs_specialty_configuration' => DB::table('patients')->where('clinic_id', $clinic->id)->exists()
                && ! DB::table('clinic_specialties')->where('clinic_id', $clinic->id)->where('status', 'active')->exists(),
        ];
    }

    /** @return array{alternate_phones_filled: int, postal_codes_filled: int, conflicts_recorded: int} */
    private function profiles(Clinic $clinic): array
    {
        $filledPhones = $filledPostal = $conflicts = 0;

        DB::table('patient_profiles as pp')->join('patients as p', 'p.id', '=', 'pp.patient_id')
            ->where('p.clinic_id', $clinic->id)->orderBy('pp.id')
            ->select(['pp.id', 'pp.patient_id', 'pp.secondary_phone', 'pp.alternate_mobile', 'pp.pincode', 'pp.postal_code', 'pp.first_name as profile_first', 'pp.last_name as profile_last', 'p.alternate_phone', 'p.first_name', 'p.last_name'])
            ->chunkById(self::CHUNK, function (Collection $profiles) use ($clinic, &$filledPhones, &$filledPostal, &$conflicts) {
                foreach ($profiles as $profile) {
                    // Alternate phone: the patient record is canonical.
                    $current = trim((string) $profile->alternate_phone);
                    foreach (['alternate_mobile', 'secondary_phone'] as $field) {
                        $value = trim((string) $profile->{$field});
                        if ($value === '' || $value === $current) {
                            continue;
                        }
                        if ($current === '') {
                            DB::table('patients')->where('id', $profile->patient_id)
                                ->where(fn ($q) => $q->whereNull('alternate_phone')->orWhere('alternate_phone', ''))
                                ->update(['alternate_phone' => $value]);
                            $current = $value;
                            $filledPhones++;
                        } else {
                            $conflicts += $this->conflict($clinic, $profile->patient_id, 'alternate_phone', "patient_profiles.{$field}", $current, $value);
                        }
                    }

                    // Postal code: `postal_code` is canonical.
                    $postal = trim((string) $profile->postal_code);
                    $pincode = trim((string) $profile->pincode);
                    if ($pincode !== '' && $postal === '') {
                        DB::table('patient_profiles')->where('id', $profile->id)->update(['postal_code' => $pincode]);
                        $filledPostal++;
                    } elseif ($pincode !== '' && $pincode !== $postal) {
                        $conflicts += $this->conflict($clinic, $profile->patient_id, 'postal_code', 'patient_profiles.pincode', $postal, $pincode);
                    }

                    // Identity lives on the patient; a different name on the profile is reported, not applied.
                    foreach (['first' => 'first_name', 'last' => 'last_name'] as $part => $field) {
                        $other = trim((string) $profile->{"profile_{$part}"});
                        if ($other !== '' && mb_strtolower($other) !== mb_strtolower(trim((string) $profile->{$field}))) {
                            $conflicts += $this->conflict($clinic, $profile->patient_id, $field, "patient_profiles.{$field}", $profile->{$field}, $other);
                        }
                    }
                }
            }, 'pp.id', 'id');

        return ['alternate_phones_filled' => $filledPhones, 'postal_codes_filled' => $filledPostal, 'conflicts_recorded' => $conflicts];
    }

    /** @return array{conditions_merged: int, alerts_moved: int} */
    private function medical(Clinic $clinic): array
    {
        $merged = $moved = 0;
        $now = now();

        DB::table('patient_medical_histories as h')->join('patients as p', 'p.id', '=', 'h.patient_id')
            ->where('p.clinic_id', $clinic->id)->orderBy('h.id')
            ->select(['h.id', 'h.patient_id', 'h.chronic_conditions', 'h.medical_conditions', 'h.medical_alerts'])
            ->chunkById(self::CHUNK, function (Collection $histories) use ($clinic, $now, &$merged, &$moved) {
                foreach ($histories as $history) {
                    $chronic = json_decode((string) $history->chronic_conditions, true) ?: [];
                    $conditions = json_decode((string) $history->medical_conditions, true) ?: [];
                    $union = collect($conditions)->merge($chronic)->map(fn ($c) => trim((string) $c))->filter()
                        ->unique(fn ($c) => mb_strtolower($c))->values()->all();
                    if (count($union) !== count($conditions)) {
                        DB::table('patient_medical_histories')->where('id', $history->id)->update(['medical_conditions' => json_encode($union)]);
                        $merged++;
                    }

                    $existing = DB::table('patient_alerts')->where('patient_id', $history->patient_id)->where('type', 'manual')->pluck('description')
                        ->map(fn ($d) => mb_strtolower(trim((string) $d)))->all();
                    foreach (json_decode((string) $history->medical_alerts, true) ?: [] as $text) {
                        $text = trim((string) $text);
                        if ($text === '' || in_array(mb_strtolower($text), $existing, true)) {
                            continue;
                        }
                        DB::table('patient_alerts')->insert(['clinic_id' => $clinic->id, 'patient_id' => $history->patient_id, 'type' => 'manual', 'description' => $text, 'severity' => 'high', 'created_at' => $now, 'updated_at' => $now]);
                        $existing[] = mb_strtolower($text);
                        $moved++;
                    }
                }
            }, 'h.id', 'id');

        return ['conditions_merged' => $merged, 'alerts_moved' => $moved];
    }

    private function encounterTiming(Clinic $clinic): int
    {
        $timed = 0;
        $timings = DB::table('clinical_consultations as c')->join('clinical_encounters as e', 'e.id', '=', 'c.encounter_id')
            ->where('e.clinic_id', $clinic->id)
            ->where(fn ($q) => $q->whereNull('e.started_at')->orWhere(fn ($q2) => $q2->whereNull('e.ended_at')->whereNotNull('c.ended_at')))
            ->groupBy('c.encounter_id')
            ->selectRaw('c.encounter_id, min(c.started_at) as started_at, max(c.ended_at) as ended_at')
            ->get();

        foreach ($timings as $timing) {
            $timed += DB::table('clinical_encounters')->where('id', $timing->encounter_id)->whereNull('started_at')->update(['started_at' => $timing->started_at]);
            if ($timing->ended_at) {
                $timed += DB::table('clinical_encounters')->where('id', $timing->encounter_id)->whereNull('ended_at')->update(['ended_at' => $timing->ended_at]);
            }
        }

        return $timed;
    }

    private function consents(Clinic $clinic): int
    {
        return DB::table('consent_records')->where('clinic_id', $clinic->id)->whereNull('captured_at')->whereNotNull('signed_at')
            ->update(['captured_at' => DB::raw('signed_at'), 'capture_method' => DB::raw("coalesce(capture_method, 'imported')")]);
    }

    /** @return array<string, int> */
    private function verify(Clinic $clinic): array
    {
        $patients = DB::table('patients')->where('clinic_id', $clinic->id);

        return [
            'patients' => (clone $patients)->count(),
            'without_branch_activity' => (clone $patients)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patient_branch_activity as a')->whereColumn('a.patient_id', 'patients.id'))->count(),
            'without_specialty' => (clone $patients)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('patient_specialties as s')->whereColumn('s.patient_id', 'patients.id'))->count(),
            'clinic_specialties_active' => DB::table('clinic_specialties')->where('clinic_id', $clinic->id)->where('status', 'active')->count(),
            'open_conflicts' => DB::table('patient_data_conflicts')->where('clinic_id', $clinic->id)->whereNull('resolved_at')->count(),
            'encounters_untimed' => DB::table('clinical_encounters')->where('clinic_id', $clinic->id)->whereNull('started_at')->count(),
        ];
    }

    private function conflict(Clinic $clinic, int $patientId, string $field, string $source, mixed $canonical, mixed $other): int
    {
        $now = now();

        return DB::table('patient_data_conflicts')->insertOrIgnore([
            'clinic_id' => $clinic->id, 'patient_id' => $patientId, 'field' => $field, 'source' => $source,
            'canonical_value' => $canonical === null ? null : (string) $canonical, 'other_value' => (string) $other,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
