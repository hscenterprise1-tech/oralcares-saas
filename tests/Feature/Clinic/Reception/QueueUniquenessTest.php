<?php

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The queue's database backstops: one position per queue, one queue per
 * branch, doctor and day — including the shared queue that has no doctor.
 */
beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id]);
    $this->newQueue = fn (?int $doctorId = null, string $date = '2026-09-29') => DB::table('clinic_queues')->insertGetId([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'doctor_id' => $doctorId,
        'queue_date' => $date, 'name' => 'Queue', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->newItem = fn (int $queueId, int $position) => DB::table('clinic_queue_items')->insert([
        'clinic_queue_id' => $queueId, 'position' => $position, 'status' => 'waiting', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('refuses a second item at the same position in one queue', function () {
    $queue = ($this->newQueue)();
    ($this->newItem)($queue, 1);

    expect(fn () => ($this->newItem)($queue, 1))->toThrow(QueryException::class);
});

it('refuses a second shared queue for the same branch and day', function () {
    ($this->newQueue)(null);

    expect(fn () => ($this->newQueue)(null))->toThrow(QueryException::class);
});

it('refuses a second queue for the same doctor, branch and day, but allows other doctors and days', function () {
    $doctor = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Dr. One', 'email' => 'one@example.test', 'status' => 'active']);
    $other = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Dr. Two', 'email' => 'two@example.test', 'status' => 'active']);

    ($this->newQueue)($doctor->id);
    ($this->newQueue)($other->id);
    ($this->newQueue)($doctor->id, '2026-09-30');
    ($this->newQueue)(null);

    expect(fn () => ($this->newQueue)($doctor->id))->toThrow(QueryException::class);
});

it('stops without deleting anything when duplicates already exist', function () {
    $migration = require database_path('migrations/2026_09_29_101808_add_queue_uniqueness_backstops.php');
    $migration->down();

    $queue = ($this->newQueue)();
    ($this->newItem)($queue, 1);
    ($this->newItem)($queue, 1);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Queue data has duplicates');
    expect(DB::table('clinic_queue_items')->where('clinic_queue_id', $queue)->count())->toBe(2);
});
