<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\ClinicQueueItem;
use App\Models\Patient;
use App\Models\User;
use App\QueueItemStatus;
use App\Services\ClinicContext;
use App\Support\ClinicClock;
use App\UserType;
use App\VisitPriority;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Clinic wall-clock time: appointments store the clinic's local time.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Kolkata'));

    $this->clinic = Clinic::factory()->create(['name' => 'Apex Healthcare', 'slug' => 'apex-health']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'North Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Reception Staff',
    ]);

    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->employee = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Dr. Pooja Sharma',
        'email' => 'dr.pooja@example.com',
        'designation' => 'General Practitioner',
        'status' => 'active',
    ]);
});

test('reception dashboard renders with operational metrics and patient flow', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Vikram',
        'last_name' => 'Patel',
        'phone' => '9876543210',
        'gender' => 'male',
    ]);

    Appointment::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'practitioner_id' => $this->employee->id,
        'scheduled_at' => ClinicClock::now(),
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get('/clinic/reception?clinic=apex-health');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Reception/Index')
        ->where('board.summary.booked', 1)
        ->where('board.summary.checked_in', 0)
        ->where('board.booking_status.total', 1)
        ->has('board.queue', 0)
        ->has('board.doctors')
    );
});

test('scheduled appointment can be checked in and assigns queue position', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Kavita',
        'last_name' => 'Deshmukh',
        'phone' => '9876500001',
        'gender' => 'female',
    ]);

    $appointment = Appointment::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'practitioner_id' => $this->employee->id,
        'scheduled_at' => ClinicClock::now(),
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/reception/check-in', [
            'clinic' => 'apex-health',
            'appointment_id' => $appointment->id,
            'practitioner_id' => $this->employee->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);

    $queueItem = ClinicQueueItem::whereHas('patientVisit', fn ($q) => $q->where('patient_id', $patient->id))->first();
    expect($queueItem)->not->toBeNull();
    expect($queueItem->position)->toBe(1);
    expect($queueItem->status)->toBe(QueueItemStatus::Waiting);
});

test('walk-in patient can be added directly to the queue', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Rohan',
        'last_name' => 'Gupta',
        'phone' => '9876500002',
        'gender' => 'male',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/reception/walk-in', [
            'clinic' => 'apex-health',
            'patient_id' => $patient->id,
            'practitioner_id' => $this->employee->id,
            'priority' => 'emergency',
            'notes' => 'Emergency toothache',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $queueItem = ClinicQueueItem::whereHas('patientVisit', fn ($q) => $q->where('patient_id', $patient->id))->first();
    expect($queueItem)->not->toBeNull();
    expect($queueItem->priority)->toBe(VisitPriority::Emergency);
});

test('duplicate check-in prevention warns if patient is already queued today', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Anita',
        'last_name' => 'Roy',
        'phone' => '9876500003',
        'gender' => 'female',
    ]);

    // Check in first time
    $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/reception/check-in', [
            'clinic' => 'apex-health',
            'patient_id' => $patient->id,
        ]);

    // Attempt second check-in for same patient
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/reception/check-in', [
            'clinic' => 'apex-health',
            'patient_id' => $patient->id,
        ]);

    $response->assertSessionHasErrors(['patient_id' => "Anita R. is already in today's queue."]);
    expect(ClinicQueueItem::count())->toBe(1);
});

test('queue item status can be updated through reception workflow', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Suresh',
        'last_name' => 'Iyer',
        'phone' => '9876500004',
        'gender' => 'male',
    ]);

    $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/reception/walk-in', [
            'clinic' => 'apex-health',
            'patient_id' => $patient->id,
        ]);

    $queueItem = ClinicQueueItem::first();

    // Call patient
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->patch("/clinic/reception/queue/{$queueItem->id}/status", [
            'clinic' => 'apex-health',
            'status' => 'called',
        ]);

    $response->assertSessionHas('success');
    expect($queueItem->fresh()->status)->toBe(QueueItemStatus::Called);
});
