<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Synthetic tenants for scale testing (1k → 100k clinics).
 *
 * Writes realistic clinics straight into a throwaway database: organisation,
 * clinic, branches with opening hours, an owner and staff with logins,
 * practitioners, patients with case numbers, ninety days of appointments in
 * final states, today's bookings, today's reception queues and visits.
 * Patient counts follow a skewed size distribution (most clinics small, a
 * few large), and `--hot` hospital-scale clinics are added once so per-tenant
 * heavy paths can be measured beside the long tail.
 *
 * Refuses to run unless the default connection's database name ends in
 * `_scratch` or `_perf`, and never in production. It is additive: running it
 * again with a larger `--clinics` tops the tenant count up to that number.
 */
class PerfSeedTenants extends Command
{
    protected $signature = 'perf:seed-tenants
        {--clinics=1000 : Total synthetic clinics wanted after this run}
        {--hot=3 : Hospital-scale clinics, created once}
        {--seed=20261002 : Random seed, for repeatable data}';

    protected $description = 'Seed synthetic clinics into a scratch database for scale testing';

    private const BATCH = 2000;

    private const FIRST_NAMES = ['Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Ayaan', 'Krishna', 'Ishaan', 'Rohan', 'Rahul', 'Amit', 'Suresh', 'Ramesh', 'Vikram', 'Anil', 'Sunil', 'Rajesh', 'Manoj', 'Ananya', 'Diya', 'Aadhya', 'Saanvi', 'Pari', 'Anika', 'Navya', 'Myra', 'Sara', 'Priya', 'Pooja', 'Neha', 'Kavya', 'Sneha', 'Anjali', 'Meera', 'Lakshmi', 'Sunita', 'Rekha', 'Asha', 'Farhan', 'Imran', 'Zoya', 'Ayesha', 'Harpreet', 'Gurpreet', 'Simran', 'Joseph', 'Mary', 'Thomas'];

    private const LAST_NAMES = ['Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Patel', 'Shah', 'Mehta', 'Reddy', 'Rao', 'Iyer', 'Nair', 'Menon', 'Pillai', 'Das', 'Banerjee', 'Chatterjee', 'Mukherjee', 'Ghosh', 'Bose', 'Joshi', 'Kulkarni', 'Deshpande', 'Patil', 'Jain', 'Agarwal', 'Bansal', 'Mishra', 'Tiwari', 'Pandey', 'Yadav', 'Chauhan', 'Thakur', 'Khan', 'Ahmed', 'Sheikh', 'Gill', 'Sandhu', 'Fernandes', 'Dsouza'];

    /** @var array<string, int> next id per table */
    private array $nextId = [];

    /** @var array<string, list<array<string, mixed>>> pending rows per table */
    private array $buffer = [];

    private string $passwordHash;

    private CarbonImmutable $today;

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (app()->isProduction() || ! preg_match('/_(scratch|perf)$/', $database)) {
            $this->error("Refusing to seed synthetic tenants into [{$database}]: use a database whose name ends in _scratch or _perf.");

            return self::FAILURE;
        }

        mt_srand((int) $this->option('seed'));
        $this->passwordHash = Hash::make('perf-synthetic-not-a-login');
        $this->today = CarbonImmutable::now('Asia/Kolkata')->startOfDay();

        DB::statement('SET SESSION foreign_key_checks = 0');
        DB::statement('SET SESSION unique_checks = 0');

        foreach (['organizations', 'clinics', 'branches', 'users', 'clinic_memberships', 'clinic_employees', 'doctor_profiles', 'patients', 'case_numbers', 'appointments', 'patient_visits', 'clinic_queues', 'clinic_queue_items', 'clinic_operating_hours'] as $table) {
            $this->nextId[$table] = (int) DB::table($table)->max('id') + 1;
        }

        $existingHot = DB::table('clinics')->where('slug', 'like', 'perf-hot-%')->count();
        for ($i = $existingHot; $i < (int) $this->option('hot'); $i++) {
            $this->seedClinic("perf-hot-{$i}", hot: true);
            $this->flush();
            $this->info("Hot clinic {$i} seeded.");
        }

        $existing = DB::table('clinics')->where('slug', 'like', 'perf-c-%')->count();
        $target = (int) $this->option('clinics');
        $started = microtime(true);

        for ($i = $existing; $i < $target; $i++) {
            $this->seedClinic("perf-c-{$i}", hot: false);

            if ($i % 100 === 99) {
                $this->flush();
            }

            if ($i % 1000 === 999) {
                $rate = ($i + 1 - $existing) / max(microtime(true) - $started, 0.001);
                $this->line(sprintf('%d clinics (%.1f clinics/s)', $i + 1, $rate));
            }
        }

        $this->flush();
        DB::statement('SET SESSION unique_checks = 1');
        DB::statement('SET SESSION foreign_key_checks = 1');

        $this->info(sprintf('Done: %d synthetic clinics in %.1fs.', $target, microtime(true) - $started));

        return self::SUCCESS;
    }

    private function seedClinic(string $slug, bool $hot): void
    {
        $now = now();
        $organizationId = $this->add('organizations', ['name' => "Org {$slug}", 'role' => 'customer', 'created_at' => $now, 'updated_at' => $now]);
        $specialty = mt_rand(1, 10) <= 7 ? 'dental' : 'physiotherapy';
        $clinicId = $this->add('clinics', [
            'organization_id' => $organizationId, 'name' => "Clinic {$slug}", 'slug' => $slug, 'status' => 'active',
            'specialties' => json_encode([$specialty]), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $branchCount = $hot ? 5 : $this->pick([1 => 85, 2 => 12, 3 => 2, 5 => 1]);
        $branches = [];
        for ($b = 0; $b < $branchCount; $b++) {
            $branches[] = $branchId = $this->add('branches', [
                'clinic_id' => $clinicId, 'organization_id' => $organizationId, 'name' => "Branch {$b}", 'slug' => "b{$b}",
                'status' => 'active', 'is_main' => $b === 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            for ($day = 0; $day < 7; $day++) {
                $this->add('clinic_operating_hours', ['clinic_id' => $clinicId, 'branch_id' => $branchId, 'day_of_week' => $day,
                    'open_time' => '09:00:00', 'close_time' => '21:00:00', 'is_closed' => $day === 0, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Staff: the owner, practitioners and front desk, each with a login.
        $staffCount = $hot ? 120 : mt_rand(3, 12);
        $doctors = [];
        for ($s = 0; $s < $staffCount; $s++) {
            $userId = $this->add('users', [
                'name' => "Staff {$s} {$slug}", 'email' => "s{$s}.{$slug}@perf.test", 'password' => $this->passwordHash,
                'user_type' => 'clinic_user', 'organization_id' => $organizationId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->add('clinic_memberships', ['clinic_id' => $clinicId, 'user_id' => $userId, 'is_primary_owner' => $s === 0,
                'has_all_branches_access' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            $employeeId = $this->add('clinic_employees', ['clinic_id' => $clinicId, 'user_id' => $userId, 'name' => "Staff {$s}",
                'email' => "s{$s}.{$slug}@perf.test", 'status' => 'active', 'primary_branch_id' => $branches[$s % $branchCount],
                'created_at' => $now, 'updated_at' => $now]);

            if ($s === 0 || $s % 3 === 1) {
                $doctors[] = $employeeId;
                $this->add('doctor_profiles', ['clinic_id' => $clinicId, 'organization_id' => $organizationId, 'clinic_employee_id' => $employeeId,
                    'experience_years' => mt_rand(1, 30), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $patientCount = $hot ? 50000 : $this->patientCount();
        $year = (int) $this->today->format('Y');
        $patients = [];
        for ($p = 0; $p < $patientCount; $p++) {
            $first = self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)];
            $last = self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];
            $created = $this->today->subDays(mt_rand(0, 720))->addMinutes(mt_rand(540, 1260));
            $patients[] = $patientId = $this->add('patients', [
                'clinic_id' => $clinicId, 'organization_id' => $organizationId, 'uhid' => sprintf('OC-%08d', $p + 1),
                'first_name' => $first, 'last_name' => $last, 'name' => "{$first} {$last}",
                'phone' => (string) mt_rand(6000000000, 9999999999), 'gender' => mt_rand(0, 1) ? 'female' : 'male',
                'status' => 'active', 'registration_type' => 'new', 'created_at' => $created, 'updated_at' => $created,
            ]);
            $caseNumber = sprintf('%s/%d/%06d', strtoupper(substr($specialty, 0, 2)), $year, $p + 1);
            $this->add('case_numbers', ['clinic_id' => $clinicId, 'patient_id' => $patientId, 'year' => $year, 'period_key' => (string) $year,
                'counter_key' => (string) $year, 'sequence' => $p + 1, 'case_number' => $caseNumber,
                'search_key' => strtolower(preg_replace('/[^A-Za-z0-9]/', '', $caseNumber)), 'created_at' => $created, 'updated_at' => $created]);
        }

        // Ninety days of history in final states, two weeks ahead booked,
        // and today's book half checked in.
        $appointmentCount = $hot ? 120000 : (int) round($patientCount * 0.8);
        $queues = [];
        $positions = [];
        for ($a = 0; $a < $appointmentCount; $a++) {
            $offset = $hot ? mt_rand(-90, 14) : mt_rand(-30, 14);
            $day = $this->today->addDays($offset);
            $branchId = $branches[mt_rand(0, $branchCount - 1)];
            $doctorId = $doctors[mt_rand(0, count($doctors) - 1)];
            $status = match (true) {
                $offset < 0 => $this->pick(['completed' => 80, 'no_show' => 10, 'cancelled' => 10]),
                $offset > 0 => $this->pick(['scheduled' => 70, 'confirmed' => 30]),
                default => $this->pick(['scheduled' => 30, 'confirmed' => 20, 'checked_in' => 30, 'completed' => 20]),
            };
            $scheduledAt = $day->addMinutes(540 + 15 * mt_rand(0, 47));
            $appointmentId = $this->add('appointments', [
                'organization_id' => $organizationId, 'clinic_id' => $clinicId, 'branch_id' => $branchId,
                'patient_id' => $patients[mt_rand(0, $patientCount - 1)], 'practitioner_id' => $doctorId,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'), 'duration_minutes' => 15, 'status' => $status,
                'source' => 'reception', 'created_at' => $now, 'updated_at' => $now,
            ]);

            if ($offset === 0 && in_array($status, ['checked_in', 'completed'], true)) {
                $queueKey = "{$branchId}:{$doctorId}";
                $queues[$queueKey] ??= $this->add('clinic_queues', ['clinic_id' => $clinicId, 'branch_id' => $branchId, 'doctor_id' => $doctorId,
                    'name' => 'Doctor queue', 'queue_date' => $this->today->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
                $positions[$queueKey] = ($positions[$queueKey] ?? 0) + 1;
                $visitId = $this->add('patient_visits', ['clinic_id' => $clinicId, 'branch_id' => $branchId, 'patient_id' => $patients[mt_rand(0, $patientCount - 1)],
                    'doctor_id' => $doctorId, 'registration_type' => 'repeat', 'visit_date' => $this->today->toDateString(),
                    'queue_status' => 'queued', 'visit_status' => 'registered', 'created_at' => $now, 'updated_at' => $now]);
                $this->add('clinic_queue_items', ['clinic_queue_id' => $queues[$queueKey], 'patient_visit_id' => $visitId, 'doctor_id' => $doctorId,
                    'appointment_id' => $appointmentId, 'position' => $positions[$queueKey], 'status' => $status === 'completed' ? 'completed' : 'waiting',
                    'checked_in_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Past visits, roughly a third of patients in the last month.
        for ($v = 0, $visits = (int) round($patientCount * 0.3); $v < $visits; $v++) {
            $this->add('patient_visits', ['clinic_id' => $clinicId, 'branch_id' => $branches[mt_rand(0, $branchCount - 1)],
                'patient_id' => $patients[mt_rand(0, $patientCount - 1)], 'doctor_id' => $doctors[mt_rand(0, count($doctors) - 1)],
                'registration_type' => 'repeat', 'visit_date' => $this->today->subDays(mt_rand(1, 30))->toDateString(),
                'queue_status' => 'completed', 'visit_status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Skewed clinic sizes: most clinics are small practices, a few are large.
     */
    private function patientCount(): int
    {
        return match ($this->pick(['small' => 60, 'medium' => 30, 'large' => 9, 'xl' => 1])) {
            'small' => mt_rand(10, 40),
            'medium' => mt_rand(40, 150),
            'large' => mt_rand(150, 600),
            'xl' => mt_rand(600, 2000),
        };
    }

    /**
     * @template T of array-key
     *
     * @param  array<T, int>  $weights
     * @return T
     */
    private function pick(array $weights): int|string
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_last($weights);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function add(string $table, array $row): int
    {
        $id = $this->nextId[$table]++;
        $this->buffer[$table][] = ['id' => $id, ...$row];

        if (count($this->buffer[$table]) >= self::BATCH) {
            $this->flushTable($table);
        }

        return $id;
    }

    private function flush(): void
    {
        foreach (array_keys($this->buffer) as $table) {
            $this->flushTable($table);
        }
    }

    private function flushTable(string $table): void
    {
        $rows = $this->buffer[$table] ?? [];
        $this->buffer[$table] = [];

        if ($rows === []) {
            return;
        }

        // Rows of one table share a shape per call site; insert per shape.
        foreach (collect($rows)->groupBy(fn (array $row) => implode(',', array_keys($row))) as $group) {
            foreach (array_chunk($group->all(), 1000) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }
}
