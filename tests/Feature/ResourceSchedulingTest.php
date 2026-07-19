<?php

use App\Events\LeaveApproved;
use App\Events\LeaveRequested;
use App\Events\ResourceAssigned;
use App\Events\ResourceUnavailable;
use App\Events\ShiftCreated;
use App\Events\ShiftUpdated;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Services\AvailabilityService;
use App\Services\LeaveService;
use App\Services\ResourceAllocationService;
use App\Services\ResourceService;
use App\Services\ScheduleValidationService;
use App\Services\ShiftService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('resource registration and operating hours setup', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $resService = app(ResourceService::class);

    // 1. Create a generic Doctor Resource
    $resource = $resService->createResource(
        $clinic,
        $branch,
        'Dr. Gregory House',
        'doctor',
        $doctor,
        ['specialty' => 'Nephrology']
    );

    expect($resource->name)->toBe('Dr. Gregory House')
        ->and($resource->resource_type)->toBe('doctor')
        ->and($resource->metadata['specialty'])->toBe('Nephrology');

    // 2. Set operating hours
    $operatingHour = $resService->setOperatingHours($branch, 1, '09:00:00', '18:00:00', false);

    expect($operatingHour->open_time)->toBe('09:00:00')
        ->and($operatingHour->is_closed)->toBeFalse();
});

test('recurring availability and shift roster workflow', function () {
    Event::fake([
        ShiftCreated::class,
        ShiftUpdated::class,
    ]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $resService = app(ResourceService::class);
    $availService = app(AvailabilityService::class);
    $shiftService = app(ShiftService::class);

    $resource = $resService->createResource($clinic, $branch, 'Chair 1', 'chair');

    // 1. Set Availability (Monday session)
    $avail = $availService->addAvailability($resource, 1, '09:00:00', '13:00:00');
    expect($avail->start_time)->toBe('09:00:00');

    // 2. Create Shift
    $shift = $shiftService->createShift($clinic, $branch, $resource, 'Morning Shift', '2026-07-20', '09:00:00', '13:00:00');
    expect($shift->name)->toBe('Morning Shift');
    Event::assertDispatched(ShiftCreated::class);

    // 3. Update Shift
    $shiftService->updateShift($shift, ['end_time' => '14:00:00']);
    expect($shift->fresh()->end_time)->toBe('14:00:00');
    Event::assertDispatched(ShiftUpdated::class);
});

test('leave management lifecycle, approvals and resource unavailability triggers', function () {
    Event::fake([
        LeaveRequested::class,
        LeaveApproved::class,
        ResourceUnavailable::class,
    ]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $resService = app(ResourceService::class);
    $leaveService = app(LeaveService::class);

    // Create resource linked to the doctor
    $resService->createResource($clinic, $branch, 'Dr. House', 'doctor', $doctor);

    // 1. Request leave
    $leave = $leaveService->requestLeave($clinic, $doctor, 'vacation', '2026-07-20', '2026-07-24', 'Annual Vacation');
    expect($leave->status)->toBe('requested');
    Event::assertDispatched(LeaveRequested::class);

    // 2. Approve leave
    $leaveService->approveLeave($leave);
    expect($leave->fresh()->status)->toBe('approved');
    Event::assertDispatched(LeaveApproved::class);
    Event::assertDispatched(ResourceUnavailable::class);
});

test('scheduling allocation and conflict checks', function () {
    Event::fake([
        ResourceAssigned::class,
    ]);

    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = ClinicEmployee::create(['clinic_id' => $clinic->id, 'name' => 'Dr. House', 'email' => 'house@example.com']);

    $resService = app(ResourceService::class);
    $allocService = app(ResourceAllocationService::class);
    $valService = app(ScheduleValidationService::class);

    $resource = $resService->createResource($clinic, $branch, 'Operatory A', 'operatory', $doctor);

    // 1. Normal allocation
    $alloc = $allocService->allocateResource($resource, 'chair_assignment', 1, '2026-07-20', '10:00:00', '12:00:00');
    expect($alloc->allocated_to_type)->toBe('chair_assignment');
    Event::assertDispatched(ResourceAssigned::class);

    // 2. Check for double booking conflict
    $hasConflict = $valService->hasConflict($resource, '2026-07-20', '11:00:00', '13:00:00');
    expect($hasConflict)->toBeTrue();

    // 3. Check for non-conflicting time on same day
    $noConflict = $valService->hasConflict($resource, '2026-07-20', '14:00:00', '15:00:00');
    expect($noConflict)->toBeFalse();
});
