<?php

namespace App\Console\Commands;

use App\AppointmentStatus;
use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\EmploymentStatus;
use App\Models\Appointment;
use App\Models\CaseNumber;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicQueue;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Scopes\TenantScope;
use App\Services\Core\NumberingEngine;
use App\Support\ClinicClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

/**
 * Times the platform's hot query paths against a synthetic-tenant database
 * (see `perf:seed-tenants`), using the application's own scopes and query
 * shapes: patient directory and search, calendar, reception board, clinic
 * context resolution, dashboard counts, UHID numbering and the cross-tenant
 * `queue:close-day` sweep (old and new discovery query side by side).
 *
 * Each path runs once to warm up, then `--runs` times; the median is
 * reported with the rows MySQL's plan expects to examine. Read-only except the
 * numbering probe, which issues numbers on a synthetic clinic.
 */
class PerfBenchmark extends Command
{
    protected $signature = 'perf:benchmark
        {--label= : Tier label for the report, e.g. 10k}
        {--runs=5 : Timed runs per path}
        {--output= : Append JSON lines to this file}';

    protected $description = 'Benchmark hot query paths against a synthetic-tenant database';

    /** @var list<array<string, mixed>> */
    private array $results = [];

    public function handle(NumberingEngine $numbering): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (app()->isProduction() || ! preg_match('/_(scratch|perf)$/', $database)) {
            $this->error("Refusing to benchmark against [{$database}].");

            return self::FAILURE;
        }

        $hot = Clinic::query()->where('slug', 'perf-hot-0')->firstOrFail();
        $small = $this->clinicWithPatients(10, 40);
        $large = $this->clinicWithPatients(600, 2000);
        $today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();

        $counts = collect(['clinics', 'patients', 'appointments', 'patient_visits', 'users'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        $this->info('Dataset: '.$counts->map(fn ($n, $t) => "{$t}=".number_format($n))->implode(', '));

        foreach (['small' => $small, 'large' => $large, 'hot' => $hot] as $size => $clinic) {
            $branchId = DB::table('branches')->where('clinic_id', $clinic->id)->where('is_main', true)->value('id');
            $phone = DB::table('patients')->where('clinic_id', $clinic->id)->value('phone');
            $searchKey = DB::table('case_numbers')->where('clinic_id', $clinic->id)->value('search_key');

            $this->measure("patient_directory_page.{$size}", fn () => Patient::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->with(['profile', 'visits' => fn ($q) => $q->latest()->limit(1)])
                ->orderBy('created_at', 'desc')
                ->paginate(15));

            $this->measure("patient_name_search.{$size}", fn () => Patient::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->matchingSearch('Sharma')
                ->limit(10)
                ->get(), Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->matchingSearch('Sharma')->limit(10));

            // The name search as it was: the platform-wide FULLTEXT index.
            $fulltext = fn () => Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)
                ->whereFullText(['name', 'first_name', 'last_name'], '+Sharma*', ['mode' => 'boolean'])->limit(10);
            $this->measure("patient_name_search.{$size}.legacy_fulltext", fn () => $fulltext()->get(), $fulltext());

            $this->measure("patient_phone_lookup.{$size}", fn () => Patient::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)
                ->searchByPhone((string) $phone)
                ->limit(20)
                ->get(), Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->searchByPhone((string) $phone)->limit(20));

            $this->measure("case_number_lookup.{$size}", fn () => CaseNumber::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)->where('search_key', $searchKey)->pluck('patient_id'));

            $week = [$today->startOfWeek()->format('Y-m-d H:i:s'), $today->endOfWeek()->format('Y-m-d H:i:s')];
            $calendar = fn () => Appointment::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)->where('branch_id', $branchId)
                ->whereBetween('scheduled_at', $week);
            $this->measure("calendar_week.{$size}", fn () => $calendar()
                ->with(['patient:id,first_name,last_name,name,uhid,phone', 'practitioner:id,name'])
                ->orderBy('scheduled_at')->limit(501)->get(), $calendar()->orderBy('scheduled_at')->limit(501));

            $this->measure("reception_board_today.{$size}", fn () => ClinicQueue::withoutGlobalScopes()
                ->where('clinic_id', $clinic->id)->where('branch_id', $branchId)->where('queue_date', $today->toDateString())
                ->with(['items.patientVisit.patient'])
                ->get());

            $this->measure("dashboard_today_counts.{$size}", fn () => [
                Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('branch_id', $branchId)
                    ->whereBetween('scheduled_at', [$today->format('Y-m-d H:i:s'), $today->endOfDay()->format('Y-m-d H:i:s')])->count(),
                PatientVisit::withoutGlobalScopes()->where('clinic_id', $clinic->id)->where('visit_date', $today->toDateString())->count(),
                Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count(),
            ]);

            $userId = DB::table('clinic_memberships')->where('clinic_id', $clinic->id)->value('user_id');
            $this->measure("clinic_context_resolution.{$size}", fn () => ClinicMembership::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('user_id', $userId)
                ->where('status', ClinicMembershipStatus::Active)
                ->whereHas('clinic', fn ($q) => $q->where('status', ClinicStatus::Active))
                ->whereNotExists(fn ($q) => $q->from('clinic_employees')
                    ->whereColumn('clinic_employees.clinic_id', 'clinic_memberships.clinic_id')
                    ->whereColumn('clinic_employees.user_id', 'clinic_memberships.user_id')
                    ->whereIn('clinic_employees.status', EmploymentStatus::accessEnding()))
                ->whereHas('clinic', fn ($q) => $q->where('slug', $clinic->slug))
                ->with(['clinic', 'user'])
                ->get());
        }

        // The dashboard's "today by status" count, as it was (`date()` on the
        // column) and as it is now (a range the clinic/branch/time index serves).
        $statusCounts = fn (Closure $day) => $day(DB::table('appointments')->where('clinic_id', $hot->id))
            ->whereNotIn('status', ['cancelled'])->groupBy('status')->selectRaw('status, count(*) as total');
        $legacyDay = $statusCounts(fn ($q) => $q->whereDate('scheduled_at', $today->toDateString()));
        $rangeDay = $statusCounts(fn ($q) => $q->whereOnDate('scheduled_at', $today));
        $this->measure('dashboard_status_counts.hot.legacy_date_fn', fn () => $legacyDay->get(), $legacyDay);
        $this->measure('dashboard_status_counts.hot.range', fn () => $rangeDay->get(), $rangeDay);

        $organization = $hot->organization;
        $this->measure('uhid_numbering', fn () => $numbering->generate('patient', $organization, null, ['prefix' => 'OC-', 'padding' => 8]));

        // The cross-tenant sweep, as `queue:close-day` ran before this audit…
        $from = $today->subDays(7)->toDateString();
        $legacy = fn () => Appointment::withoutGlobalScopes()
            ->whereIn('status', AppointmentStatus::awaitingArrival())
            ->whereNotNull('branch_id')
            ->whereDate('scheduled_at', '>=', $from)
            ->whereDate('scheduled_at', '<=', $today->toDateString());
        $this->measure('close_day_discovery.legacy_hydrate_all', fn () => $legacy()->get(['branch_id', 'scheduled_at'])->count(), $legacy(), runs: 1);

        // …its discovery now: the command's own method, at this moment…
        $command = app(CloseQueueDay::class);
        $discovery = new ReflectionMethod($command, 'candidateDays');
        $this->measure('close_day_discovery.current', fn () => $discovery->invoke($command, $from, $today->toDateString(), ClinicClock::now())->count(), runs: 3);

        // …and as it would be after every branch has closed for the day.
        $lateEvening = ClinicClock::now()->setTime(23, 59);
        $this->measure('close_day_discovery.current_after_closing', fn () => $discovery->invoke($command, $from, $today->toDateString(), $lateEvening)->count(), runs: 3);

        // …and the whole command (discovery, then per-branch checks and work
        // for branches whose day has ended).
        $this->measure('close_day_command.current', fn () => Artisan::call('queue:close-day'), runs: 1);

        $this->table(['path', 'median ms', 'max ms', 'plan rows', 'plan indexes'], array_map(
            fn (array $r) => [$r['path'], $r['median_ms'], $r['max_ms'], $r['plan_rows'] ?? '—', $r['plan_keys'] ?? '—'],
            $this->results,
        ));

        if ($output = $this->option('output')) {
            foreach ($this->results as $row) {
                file_put_contents($output, json_encode(['tier' => $this->option('label'), ...$counts->all(), ...$row])."\n", FILE_APPEND);
            }
        }

        return self::SUCCESS;
    }

    private function clinicWithPatients(int $min, int $max): Clinic
    {
        $clinicId = DB::table('patients')
            ->join('clinics', 'clinics.id', '=', 'patients.clinic_id')
            ->where('clinics.slug', 'like', 'perf-c-%')
            ->groupBy('patients.clinic_id')
            ->havingRaw('count(*) between ? and ?', [$min, $max])
            ->orderBy('patients.clinic_id')
            ->value('patients.clinic_id');

        return Clinic::query()->findOrFail($clinicId);
    }

    /**
     * @param  Builder<Model>|null  $explain
     */
    private function measure(string $path, Closure $callback, mixed $explain = null, ?int $runs = null): void
    {
        $runs ??= (int) $this->option('runs');
        $callback();

        $timings = [];
        for ($i = 0; $i < $runs; $i++) {
            $started = hrtime(true);
            $callback();
            $timings[] = (hrtime(true) - $started) / 1e6;
        }

        sort($timings);
        $row = [
            'path' => $path,
            'median_ms' => round($timings[intdiv(count($timings), 2)], 2),
            'max_ms' => round(max($timings), 2),
        ];

        if ($explain !== null) {
            $plan = DB::select('explain '.$explain->toSql(), $explain->getBindings());
            $row['plan_rows'] = array_sum(array_map(fn ($line) => (int) ($line->rows ?? 0), $plan));
            $row['plan_keys'] = implode(',', array_filter(array_map(fn ($line) => $line->key ?? null, $plan)));
        }

        $this->results[] = $row;
    }
}
