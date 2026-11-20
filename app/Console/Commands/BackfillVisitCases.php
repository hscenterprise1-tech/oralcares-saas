<?php

namespace App\Console\Commands;

use App\Console\Concerns\RunsPerClinic;
use App\Models\Clinic;
use App\Services\Patient\Patient360\Patient360Sections;
use App\Services\Security\SecurityAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links historical visits that have no case (`patient_visits.case_id` null)
 * to their patient's case — only where exactly one case can be theirs (OD-4,
 * "verified only"): a case of the same patient, of the visit's specialty, open
 * on the visit's date (opened on or before it, not closed before it). A visit
 * with no such case, or more than one, stays unlinked: ambiguous history is
 * never guessed.
 *
 * Additive and idempotent: only empty `case_id`s are filled, so re-running
 * links nothing twice and never moves a visit someone linked by hand.
 * `--dry-run` reports the same counts in a transaction rolled back.
 */
class BackfillVisitCases extends Command
{
    use RunsPerClinic;

    protected $signature = 'patients:backfill-visit-cases
        {--clinic=* : Clinic id(s)}
        {--all-clinics : Every active clinic}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Link visits with no case to the one case that can be theirs; leave ambiguous ones unlinked';

    private const CHUNK = 500;

    public function handle(SecurityAudit $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        $this->forEachSelectedClinic(function (Clinic $clinic) use ($audit, $dryRun, &$rows) {
            $stats = ['clinic' => $clinic->id, 'unlinked_visits' => 0, 'linked' => 0, 'no_case' => 0, 'ambiguous' => 0];
            DB::beginTransaction();
            try {
                DB::table('patient_visits as v')->leftJoin('clinic_specialties as s', 's.id', '=', 'v.clinic_specialty_id')
                    ->where('v.clinic_id', $clinic->id)->whereNull('v.case_id')->whereIn('v.visit_status', Patient360Sections::ATTENDED_VISIT)
                    ->select(['v.id', 'v.patient_id', 'v.visit_date', 's.specialty_key'])
                    ->chunkById(self::CHUNK, function ($visits) use ($clinic, &$stats) {
                        $cases = DB::table('patient_cases')->where('clinic_id', $clinic->id)->whereIn('patient_id', $visits->pluck('patient_id')->unique())
                            ->get(['id', 'patient_id', 'specialty', 'opened_at', 'closed_at'])->groupBy('patient_id');

                        foreach ($visits as $visit) {
                            $stats['unlinked_visits']++;
                            $day = (string) $visit->visit_date;
                            $candidates = $cases->get($visit->patient_id, collect())->filter(fn ($case) => ($visit->specialty_key === null || $case->specialty === $visit->specialty_key)
                                && $case->opened_at !== null && substr((string) $case->opened_at, 0, 10) <= $day
                                && ($case->closed_at === null || substr((string) $case->closed_at, 0, 10) >= $day));

                            if ($candidates->count() !== 1) {
                                $stats[$candidates->isEmpty() ? 'no_case' : 'ambiguous']++;

                                continue;
                            }
                            // Only a visit still without a case: never overwrite one linked meanwhile.
                            $stats['linked'] += DB::table('patient_visits')->where('id', $visit->id)->whereNull('case_id')->update(['case_id' => $candidates->first()->id]);
                        }
                    }, 'v.id', 'id');

                if ($dryRun) {
                    DB::rollBack();
                } else {
                    $audit->record('patient.visit_cases.backfilled', $clinic, collect($stats)->except('clinic')->all(), ['clinic_id' => $clinic->id]);
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
        $this->info($dryRun ? 'Dry run: nothing was written.' : 'Backfill complete. Visits left unlinked had no case, or more than one that could be theirs.');

        return self::SUCCESS;
    }
}
