<?php

use App\Events\DoctorAssigned;
use App\Events\PatientCheckedIn;
use App\Events\QueueUpdated;
use App\Events\VisitCancelled;
use App\Events\VisitCompleted;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\Patient;
use App\Services\DoctorAssignmentService;
use App\Services\QueueService;
use App\Services\ReceptionService;
use App\Services\VisitCheckInService;
use App\Services\VisitLifecycleService;
use App\VisitPriority;
use App\VisitStatus;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('patient check-in generates encounter and fires event', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $checkInService = app(VisitCheckInService::class);
    $encounter = $checkInService->checkIn($clinic, $branch, $patient);

    expect($encounter)->not->toBeNull()
        ->and($encounter->status)->toBe(VisitStatus::CheckedIn)
        ->and($encounter->encounter_number)->toBe("ENC-{$clinic->id}-00001");

    Event::assertDispatched(PatientCheckedIn::class, function ($event) use ($encounter) {
        return $event->encounter->id === $encounter->id;
    });
});

test('doctor assignment updates encounter and fires event', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = app(VisitCheckInService::class)->checkIn($clinic, $branch, $patient);

    app(DoctorAssignmentService::class)->assignDoctor($encounter, $doctor);

    expect($encounter->fresh()->doctor_id)->toBe($doctor->id);

    Event::assertDispatched(DoctorAssigned::class);
});

test('visit lifecycle status transitions correctly', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $encounter = app(VisitCheckInService::class)->checkIn($clinic, $branch, $patient);

    $lifecycle = app(VisitLifecycleService::class);

    // Completed transition
    $lifecycle->transitionStatus($encounter, VisitStatus::Completed);
    expect($encounter->fresh()->status)->toBe(VisitStatus::Completed)
        ->and($encounter->fresh()->ended_at)->not->toBeNull();
    Event::assertDispatched(VisitCompleted::class);

    // Cancelled transition
    $lifecycle->transitionStatus($encounter, VisitStatus::Cancelled);
    expect($encounter->fresh()->status)->toBe(VisitStatus::Cancelled);
    Event::assertDispatched(VisitCancelled::class);
});

test('queue service handles enqueuing, priority, and contiguous re-ordering', function () {
    Event::fake([QueueUpdated::class]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $queue = app(QueueService::class)->createQueue($clinic, $branch, 'Floor 1 Queue');

    $patient1 = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $patient2 = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-2', 'name' => 'Jane Smith']);

    $enc1 = app(VisitCheckInService::class)->checkIn($clinic, $branch, $patient1);
    $enc2 = app(VisitCheckInService::class)->checkIn($clinic, $branch, $patient2);

    $queueService = app(QueueService::class);

    // Enqueue 1
    $item1 = $queueService->enqueue($queue, $enc1, VisitPriority::Normal);
    expect($item1->position)->toBe(1)
        ->and($enc1->fresh()->status)->toBe(VisitStatus::Waiting);

    // Enqueue 2
    $item2 = $queueService->enqueue($queue, $enc2, VisitPriority::Emergency);
    expect($item2->position)->toBe(2);

    // Call Next
    $called = $queueService->callNext($queue);
    expect($called->id)->toBe($item1->id)
        ->and($called->called_at)->not->toBeNull()
        ->and($enc1->fresh()->status)->toBe(VisitStatus::Called);

    // Remove first and check reordering
    $queueService->removeFromQueue($queue, $enc1);
    expect($queue->items()->count())->toBe(1)
        ->and($queue->items()->first()->position)->toBe(1);

    Event::assertDispatched(QueueUpdated::class);
});

test('reception service walk-in check-in and stage flow', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $queue = app(QueueService::class)->createQueue($clinic, $branch, 'Waiting Lounge');

    $reception = app(ReceptionService::class);

    // 1. Check in walk-in
    $encounter = $reception->checkInWalkIn($clinic, $branch, $patient);
    expect($encounter->status)->toBe(VisitStatus::CheckedIn);

    // 2. Complete stage (pushes to waiting queue)
    $reception->completeReceptionStage($encounter, $queue, VisitPriority::VIP);

    expect($encounter->fresh()->status)->toBe(VisitStatus::Waiting)
        ->and($queue->items()->count())->toBe(1)
        ->and($queue->items()->first()->priority)->toBe(VisitPriority::VIP);

    // 3. Cancel Check In
    $reception->cancelCheckIn($encounter);
    expect($encounter->fresh()->status)->toBe(VisitStatus::Cancelled);
});
