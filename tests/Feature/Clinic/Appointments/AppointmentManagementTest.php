<?php

use App\AppointmentStatus;
use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Smile & Health Clinic', 'slug' => 'smile-health']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Center',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Front Desk Executive',
    ]);

    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->doctor = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Dr. Alok Verma',
        'email' => 'dr.alok@example.com',
        'designation' => 'Senior Practitioner',
        'status' => 'active',
    ]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Ritu',
        'last_name' => 'Saxena',
        'phone' => '9876599999',
        'gender' => 'female',
        'uhid' => 'UHID-2026-9999',
    ]);
});

test('appointments index lists scheduled appointments and practitioners', function () {
    Appointment::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'practitioner_id' => $this->doctor->id,
        'scheduled_at' => now(),
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get('/clinic/appointments');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Appointments/Index')
        ->has('appointments', 1)
        ->where('appointments.0.patient_name', 'Ritu Saxena')
        ->has('practitioners', 1)
    );
});

test('scheduling an appointment records operator audit and timeline event', function () {
    $scheduledAt = now()->addDays(2)->format('Y-m-d H:i:s');

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/appointments', [
            'patient_id' => $this->patient->id,
            'practitioner_id' => $this->doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
            'appointment_type' => 'consultation',
            'notes' => 'Follow-up on previous consultation',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $appointment = Appointment::where('patient_id', $this->patient->id)->where('duration_minutes', 30)->first();
    expect($appointment)->not->toBeNull();
    expect($appointment->created_by)->toBe($this->user->id);
    expect($appointment->practitioner_id)->toBe($this->doctor->id);

    $timelineEvent = PatientTimelineEvent::where('patient_id', $this->patient->id)
        ->where('event_type', 'appointment_scheduled')
        ->first();
    expect($timelineEvent)->not->toBeNull();
});

test('appointment status can be updated to completed', function () {
    $appointment = Appointment::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'practitioner_id' => $this->doctor->id,
        'scheduled_at' => now(),
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->patch("/clinic/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ]);

    $response->assertSessionHas('success');
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
});
