<?php

namespace App\Console\Commands;

use App\Models\CaseNumber;
use App\Models\CaseNumberSequence;
use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use App\Services\Patient\CaseNumberGenerator;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Repeatable proof that case numbers are race-safe under real concurrency.
 *
 * The Pest suite runs on SQLite, where row locks do nothing and nothing runs
 * in parallel. This command starts N separate PHP processes against the real
 * MySQL database, all releasing at the same instant, each issuing numbers for
 * its own patients — and every worker also asks for the *same* first
 * patient, so they all race for one row. It then checks: no duplicate number, one number per patient, and
 * a gap-free sequence. Everything it creates lives in one temporary clinic
 * that is deleted afterwards, and the command verifies nothing is left.
 */
class CaseNumberRaceCheck extends Command
{
    protected $signature = 'case-numbers:race-check
        {--workers=8 : Parallel worker processes}
        {--per-worker=5 : Patients each worker issues numbers for}
        {--worker : Internal: run as one worker}
        {--clinic= : Internal: clinic id for a worker}
        {--patients= : Internal: comma-separated patient ids for a worker}
        {--start-at= : Internal: unix time (float) the workers release at}';

    protected $description = 'Issue case numbers from parallel processes against MySQL and verify there are no duplicates (local only; cleans up after itself)';

    public function handle(CaseNumberGenerator $generator): int
    {
        if ($this->option('worker')) {
            return $this->runWorker($generator);
        }

        if (! app()->isLocal() || DB::getDriverName() !== 'mysql') {
            $this->error('Runs only in the local environment against MySQL.');

            return self::FAILURE;
        }

        $workers = max(2, (int) $this->option('workers'));
        $perWorker = max(1, (int) $this->option('per-worker'));

        [$organization, $clinic] = $this->createTemporaryClinic();
        $this->info("Temporary clinic #{$clinic->id} ({$clinic->slug}) created.");

        $patientIds = collect();
        $passed = false;

        try {
            // Without model events: the search observer would otherwise queue an
            // index job per patient that outlives the temporary clinic.
            $patientIds = Patient::withoutEvents(fn () => collect(range(1, $workers * $perWorker))
                ->map(fn (int $n) => Patient::create(['clinic_id' => $clinic->id, 'first_name' => 'Race', 'last_name' => "Patient {$n}", 'name' => "Race Patient {$n}"])->id));

            $shared = $patientIds->first();
            $batches = $patientIds->chunk($perWorker)->values();
            $startAt = microtime(true) + 3;

            $results = Process::pool(function (Pool $pool) use ($batches, $shared, $clinic, $startAt) {
                foreach ($batches as $i => $batch) {
                    // Every worker also asks for the first patient: they all race for one row.
                    $ids = $batch->push($shared)->unique()->implode(',');
                    $pool->as("w{$i}")->path(base_path())->timeout(120)->command([
                        PHP_BINARY, 'artisan', 'case-numbers:race-check', '--worker',
                        "--clinic={$clinic->id}", "--patients={$ids}", "--start-at={$startAt}",
                    ]);
                }
            })->start()->wait();

            $failedWorkers = $results->collect()->filter(fn ($result) => ! $result->successful());
            foreach ($failedWorkers as $name => $result) {
                $this->error("Worker {$name} failed: ".trim($result->errorOutput() ?: $result->output()));
            }

            $passed = $this->report($clinic, $patientIds->count()) && $failedWorkers->isEmpty();
        } finally {
            $this->deleteTemporaryClinic($organization, $clinic, $patientIds);
        }

        $this->line($passed ? '<info>PASS</info>: no duplicate case numbers under concurrency.' : '<error>FAIL</error>');

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function runWorker(CaseNumberGenerator $generator): int
    {
        $clinic = Clinic::withoutGlobalScopes()->findOrFail((int) $this->option('clinic'));
        $ids = array_map('intval', explode(',', (string) $this->option('patients')));

        $wait = (float) $this->option('start-at') - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }

        foreach ($ids as $id) {
            $generator->forPatient($clinic, Patient::withoutGlobalScopes()->findOrFail($id));
        }

        return self::SUCCESS;
    }

    private function report(Clinic $clinic, int $patientCount): bool
    {
        $numbers = CaseNumber::where('clinic_id', $clinic->id)->orderBy('sequence')->get(['patient_id', 'sequence', 'case_number']);
        $sequences = $numbers->pluck('sequence')->all();

        $checks = [
            'rows issued' => [$numbers->count(), $patientCount],
            'distinct case numbers' => [$numbers->pluck('case_number')->unique()->count(), $patientCount],
            'patients with exactly one number' => [$numbers->groupBy('patient_id')->filter(fn ($rows) => $rows->count() === 1)->count(), $patientCount],
            'sequence is 1..N with no gaps' => [$sequences === range(1, $patientCount) ? 'yes' : 'no', 'yes'],
            'counter matches highest sequence' => [
                CaseNumberSequence::where('clinic_id', $clinic->id)->value('last_sequence'),
                $patientCount,
            ],
        ];

        $this->table(['Check', 'Actual', 'Expected', 'OK'], collect($checks)->map(
            fn (array $pair, string $name) => [$name, $pair[0], $pair[1], $pair[0] == $pair[1] ? 'yes' : 'NO']
        )->values()->all());

        return collect($checks)->every(fn (array $pair) => $pair[0] == $pair[1]);
    }

    /**
     * @return array{0: Organization, 1: Clinic}
     */
    private function createTemporaryClinic(): array
    {
        $slug = 'race-check-'.Str::lower(Str::random(8));
        $organization = Organization::create(['name' => "Race check {$slug}"]);
        $clinic = Clinic::create(['organization_id' => $organization->id, 'name' => "Race check {$slug}", 'slug' => $slug]);

        return [$organization, $clinic];
    }

    /**
     * @param  Collection<int, int>  $patientIds
     */
    private function deleteTemporaryClinic(Organization $organization, Clinic $clinic, Collection $patientIds): void
    {
        try {
            DB::transaction(function () use ($organization, $clinic): void {
                CaseNumber::where('clinic_id', $clinic->id)->delete();
                CaseNumberSequence::where('clinic_id', $clinic->id)->delete();
                Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->delete();
                DB::table('numbering_sequences')->where('organization_id', $organization->id)->delete();
                // Through the models, so the search-index and customer-success
                // observers clean up after themselves; Organization soft-deletes.
                $clinic->delete();
                $organization->forceDelete();
            });
        } catch (Throwable $e) {
            $this->error("Cleanup failed: {$e->getMessage()}");
        }

        $leftovers = [
            'clinics' => DB::table('clinics')->where('id', $clinic->id)->count(),
            'organizations' => DB::table('organizations')->where('id', $organization->id)->count(),
            'patients' => DB::table('patients')->where('clinic_id', $clinic->id)->count(),
            'case_numbers' => DB::table('case_numbers')->where('clinic_id', $clinic->id)->count(),
            'case_number_sequences' => DB::table('case_number_sequences')->where('clinic_id', $clinic->id)->count(),
            'numbering_sequences' => DB::table('numbering_sequences')->where('organization_id', $organization->id)->count(),
            'ent_search_documents' => DB::table('ent_search_documents')
                ->where(fn ($q) => $q->where('model_type', Clinic::class)->where('model_id', $clinic->id))
                ->orWhere(fn ($q) => $q->where('model_type', Organization::class)->where('model_id', $organization->id))
                ->count(),
            'ent_search_documents (patients)' => DB::table('ent_search_documents')->where('model_type', Patient::class)->whereIn('model_id', $patientIds)->count(),
            'queued jobs for these patients' => $this->jobsMentioning('jobs', $patientIds),
            'failed jobs for these patients' => $this->jobsMentioning('failed_jobs', $patientIds),
            'customer_success_snapshots' => DB::table('customer_success_snapshots')->where('organization_id', $organization->id)->count(),
        ];

        $this->table(['Cleanup: rows left', 'Count'], collect($leftovers)->map(fn (int $count, string $table) => [$table, $count])->values()->all());
        $this->line(array_sum($leftovers) === 0 ? '<info>Temporary clinic fully deleted.</info>' : '<error>Temporary data left behind — see above.</error>');
    }

    /**
     * Queue payloads (pending or failed) that carry one of these patients,
     * e.g. a search-index job a patient observer queued.
     *
     * @param  Collection<int, int>  $patientIds
     */
    private function jobsMentioning(string $table, Collection $patientIds): int
    {
        return DB::table($table)->where('payload', 'like', '%Patient%')->pluck('payload')
            ->filter(function (string $payload) use ($patientIds): bool {
                $command = json_decode($payload, true)['data']['command'] ?? '';

                return str_contains($command, 'App\\Models\\Patient')
                    && preg_match('/s:2:"id";i:(\d+);/', $command, $match) === 1
                    && $patientIds->contains((int) $match[1]);
            })
            ->count();
    }
}
