<?php

use App\Events\PatientCalled;
use App\Events\PatientCompleted;
use App\Events\PatientHeld;
use App\Events\PatientRecalled;
use App\Events\PatientResumed;
use App\Events\PatientSkipped;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicQueue;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\QueueItemStatus;
use App\Services\PatientFlowService;
use App\Services\QueueDisplayService;
use App\Services\QueueRoutingService;
use App\VisitPriority;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('can register display sessions and filter active panels', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $displayService = app(QueueDisplayService::class);

    $displayService->createSession([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'floor' => 'Floor 1',
        'department' => 'Orthodontics',
        'display_name' => 'Ortho TV',
        'display_type' => 'floor',
        'is_active' => true,
    ]);

    $displayService->createSession([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'floor' => 'Floor 2',
        'department' => 'Endodontics',
        'display_name' => 'Endo Room 3',
        'display_type' => 'doctor',
        'is_active' => false,
    ]);

    $active = $displayService->getActiveDisplays($branch, 'Floor 1', 'Orthodontics');
    expect($active)->toHaveCount(1)
        ->and($active->first()->display_name)->toBe('Ortho TV');
});

test('patient calling recall skip hold and resume workflow records histories', function () {
    Event::fake([
        PatientCalled::class,
        PatientRecalled::class,
        PatientSkipped::class,
        PatientHeld::class,
        PatientResumed::class,
        PatientCompleted::class,
    ]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $queue = ClinicQueue::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'name' => 'Dr. House Queue',
    ]);

    $queueItem = ClinicQueueItem::create([
        'clinic_queue_id' => $queue->id,
        'encounter_id' => $encounter->id,
        'position' => 1,
        'priority' => VisitPriority::Normal,
        'status' => QueueItemStatus::Waiting,
    ]);

    $flow = app(PatientFlowService::class);

    // 1. Call
    $flow->callPatient($queueItem, $doctor);
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Calling)
        ->and($queueItem->fresh()->called_at)->not->toBeNull();
    Event::assertDispatched(PatientCalled::class);

    // 2. Recall
    $flow->recallPatient($queueItem, $doctor);
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Recalled);
    Event::assertDispatched(PatientRecalled::class);

    // 3. Hold
    $flow->holdPatient($queueItem, $doctor);
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Held);
    Event::assertDispatched(PatientHeld::class);

    // 4. Resume
    $flow->resumePatient($queueItem, $doctor);
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Called);
    Event::assertDispatched(PatientResumed::class);

    // 5. Skip
    $flow->skipPatient($queueItem, $doctor);
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Skipped);
    Event::assertDispatched(PatientSkipped::class);

    // 6. Complete
    $flow->completePatient($queueItem, $doctor);
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Completed)
        ->and($queueItem->fresh()->histories)->toHaveCount(6);
    Event::assertDispatched(PatientCompleted::class);
});

test('multi-floor routing logic updates queue item attributes', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::create(['clinic_id' => $clinic->id, 'patient_number' => 'PT-1', 'name' => 'John Doe']);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $encounter = ClinicalEncounter::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'doctor_id' => $doctor->id,
        'encounter_number' => 'ENC-1',
        'status' => 'checked_in',
    ]);

    $queue = ClinicQueue::create([
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'name' => 'General Queue',
    ]);

    $queueItem = ClinicQueueItem::create([
        'clinic_queue_id' => $queue->id,
        'encounter_id' => $encounter->id,
        'position' => 1,
        'priority' => VisitPriority::VIP,
    ]);

    $routing = app(QueueRoutingService::class);

    // Route to Floor 3, X-Ray
    $routing->routePatient($queueItem, 'Floor 3', 'X-Ray');

    expect($queueItem->fresh()->current_floor)->toBe('Floor 3')
        ->and($queueItem->fresh()->current_department)->toBe('X-Ray');
});
