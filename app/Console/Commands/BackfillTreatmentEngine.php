<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsPerClinic;
use App\Models\CatalogPriceVersion;
use App\Models\Clinic;
use App\Models\PhysioCarePlan;
use App\Models\TreatmentRecord;
use App\Services\Security\SecurityAudit;
use App\Services\Treatment\LegacyTreatmentMirror;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Treatment engine backfill (Phase 3). Additive and idempotent. Per clinic:
 *
 *  1. procedure specialty    `clinical_procedures.specialty_key` where the
 *                            clinic practises exactly one specialty (never
 *                            guessed for a multi-specialty clinic)
 *  2. prices                 each `clinical_procedure_prices` row becomes a
 *                            `catalog_price_versions` row (amount as stored,
 *                            in force from when it was last set)
 *  3. dental proposal lines  each `treatment_records` row becomes a legacy
 *                            patient treatment (original id, amount, dates,
 *                            status kept) with its procedure, bill lines,
 *                            tooth history and endo/implant/lab rows linked
 *  4. physio plans of care   each becomes a session-based patient treatment
 *                            with its sessions, exercises and packages linked
 *
 * then prints the verification counts and totals. `--dry-run` rolls back.
 */
class BackfillTreatmentEngine extends Command
{
    use RunsPerClinic;

    protected $signature = 'treatments:backfill-engine
        {--clinic=* : Clinic id(s)}
        {--all-clinics : Every active clinic}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Migrate legacy prices, treatment records and physio plans into the treatment engine, and verify it';

    private const CHUNK = 500;

    public function handle(LegacyTreatmentMirror $mirror, SecurityAudit $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        $this->forEachSelectedClinic(function (Clinic $clinic) use ($mirror, $audit, $dryRun, &$rows) {
            DB::beginTransaction();
            try {
                $stats = [
                    'clinic' => $clinic->id,
                    'procedure_specialties_set' => $this->procedureSpecialties($clinic),
                    'prices_versioned' => $this->prices($clinic),
                    'records_mirrored' => $this->records($clinic, $mirror),
                    'physio_plans_mirrored' => $this->physioPlans($clinic, $mirror),
                ];
                $stats += $this->verify($clinic);

                if ($dryRun) {
                    DB::rollBack();
                } else {
                    $audit->record('treatment.engine.backfilled', $clinic, collect($stats)->except('clinic')->all(), ['clinic_id' => $clinic->id]);
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
        $mismatch = collect($rows)->filter(fn (array $row) => $row['records'] !== $row['legacy_treatments'] || $row['records_total'] !== $row['legacy_treatments_total']
            || $row['legacy_prices'] !== $row['legacy_prices_versioned'] || $row['physio_plans'] !== $row['physio_treatments']);
        if ($mismatch->isNotEmpty()) {
            $this->error('Verification mismatch for clinic(s): '.$mismatch->pluck('clinic')->implode(', '));

            return self::FAILURE;
        }
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Backfill complete; counts and totals verified.');

        return self::SUCCESS;
    }

    private function procedureSpecialties(Clinic $clinic): int
    {
        $keys = DB::table('clinic_specialties')->where('clinic_id', $clinic->id)->where('status', 'active')->pluck('specialty_key');

        return $keys->count() === 1
            ? DB::table('clinical_procedures')->where('clinic_id', $clinic->id)->whereNull('specialty_key')->update(['specialty_key' => $keys->first()])
            : 0;
    }

    private function prices(Clinic $clinic): int
    {
        $created = 0;
        DB::table('clinical_procedure_prices')->where('clinic_id', $clinic->id)->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $prices) use ($clinic, &$created) {
                foreach ($prices as $price) {
                    $exists = CatalogPriceVersion::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
                        ->where(fn ($q) => $q->where('legacy_price_id', $price->id)
                            // A price the new writer already versioned is not versioned twice.
                            ->orWhere(fn ($open) => $open->where('clinical_procedure_id', $price->clinical_procedure_id)->where('branch_id', $price->branch_id)->whereNull('effective_to')))
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    CatalogPriceVersion::query()->withoutGlobalScopes()->create([
                        'clinic_id' => $clinic->id,
                        'branch_id' => $price->branch_id,
                        'clinical_procedure_id' => $price->clinical_procedure_id,
                        'amount' => $price->price,
                        'effective_from' => Carbon::parse($price->updated_at ?? $price->created_at ?? now()),
                        'legacy_price_id' => $price->id,
                    ]);
                    $created++;
                }
            });

        return $created;
    }

    private function records(Clinic $clinic, LegacyTreatmentMirror $mirror): int
    {
        $count = 0;
        TreatmentRecord::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $records) use ($mirror, &$count) {
                foreach ($records as $record) {
                    $mirror->mirrorRecord($record);
                    $count++;
                }
            });

        return $count;
    }

    private function physioPlans(Clinic $clinic, LegacyTreatmentMirror $mirror): int
    {
        $count = 0;
        PhysioCarePlan::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $plans) use ($mirror, &$count) {
                foreach ($plans as $plan) {
                    $mirror->mirrorPhysioCarePlan($plan);
                    $count++;
                }
            });

        return $count;
    }

    /** @return array<string, int|string> */
    private function verify(Clinic $clinic): array
    {
        $records = DB::table('treatment_records')->where('clinic_id', $clinic->id);
        $legacy = DB::table('patient_treatments')->where('clinic_id', $clinic->id)->whereNotNull('legacy_treatment_record_id');

        return [
            'records' => (clone $records)->count(),
            'legacy_treatments' => (clone $legacy)->count(),
            'records_total' => number_format((float) (clone $records)->sum('price'), 2, '.', ''),
            'legacy_treatments_total' => number_format((float) (clone $legacy)->sum('agreed_amount'), 2, '.', ''),
            'legacy_prices' => DB::table('clinical_procedure_prices')->where('clinic_id', $clinic->id)->count(),
            'legacy_prices_versioned' => DB::table('clinical_procedure_prices as p')->where('p.clinic_id', $clinic->id)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('catalog_price_versions as v')->where('v.clinic_id', $clinic->id)
                    ->where(fn ($w) => $w->whereColumn('v.legacy_price_id', 'p.id')->orWhere(fn ($o) => $o->whereColumn('v.clinical_procedure_id', 'p.clinical_procedure_id')
                        ->where(fn ($b) => $b->whereColumn('v.branch_id', 'p.branch_id')->orWhere(fn ($n) => $n->whereNull('v.branch_id')->whereNull('p.branch_id'))))))
                ->count(),
            'physio_plans' => DB::table('physio_care_plans')->where('clinic_id', $clinic->id)->count(),
            'physio_treatments' => DB::table('patient_treatments')->where('clinic_id', $clinic->id)->whereNotNull('legacy_physio_care_plan_id')->count(),
            'procedures_without_specialty' => DB::table('clinical_procedures')->where('clinic_id', $clinic->id)->whereNull('specialty_key')->count(),
        ];
    }
}
