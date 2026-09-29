<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Elite Health Center', 'slug' => 'elite-health']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'East Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Clinic Admin',
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
        'name' => 'Dr. Meera Nambiar',
        'email' => 'dr.meera@example.com',
        'designation' => 'Consultant',
        'status' => 'active',
    ]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Harish',
        'last_name' => 'Kulkarni',
        'phone' => '9876588888',
        'gender' => 'male',
        'uhid' => 'UHID-2026-8888',
    ]);
});

test('visits index lists recorded clinical visits', function () {
    PatientVisit::create([
        'clinic_id' => $this->clinic->id,
        'branch_id' => $this->branch->id,
        'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id,
        'visit_date' => today(),
        'visit_status' => 'in_progress',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get('/clinic/visits');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Visits/Index')
        ->has('visits.data', 1)
        ->where('visits.data.0.patient_name', 'Harish Kulkarni')
    );
});

test('creating a visit registers patient timeline event and redirects to patient 360', function () {
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/visits', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'notes' => 'Patient arrived for initial consultation',
        ]);

    $response->assertRedirect("/clinic/patients/{$this->patient->id}");
    $response->assertSessionHas('success');

    $visit = PatientVisit::where('patient_id', $this->patient->id)->first();
    expect($visit)->not->toBeNull();
    expect($visit->doctor_id)->toBe($this->doctor->id);

    $timelineEvent = PatientTimelineEvent::where('patient_id', $this->patient->id)
        ->where('event_type', 'visit_started')
        ->first();
    expect($timelineEvent)->not->toBeNull();
});
