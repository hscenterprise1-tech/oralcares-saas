<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\PatientTimelineEvent;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'City Dental & Care', 'slug' => 'city-dental']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'name' => 'Reception User',
    ]);

    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);
});

test('patient directory lists clinic patients and supports search', function () {
    Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Priya',
        'last_name' => 'Nair',
        'phone' => '9876511111',
        'gender' => 'female',
        'uhid' => 'UHID-2026-0001',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get('/clinic/patients?q=Priya');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Patients/Index')
        ->has('patients.data', 1)
        ->where('patients.data.0.name', 'Priya Nair')
        ->where('metrics.total', 1)
    );
});

test('patient registration requires first name, last name, phone, and gender', function () {
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', []);

    $response->assertSessionHasErrors(['first_name', 'last_name', 'phone', 'gender']);
});

test('blood group is optional during patient registration', function () {
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Kunal',
            'last_name' => 'Kapoor',
            'phone' => '9876522222',
            'gender' => 'male',
            'blood_group' => '', // explicitly blank / optional
            'age' => 29,
        ]);

    $response->assertRedirect();
    $patient = Patient::where('phone', '9876522222')->first();
    expect($patient)->not->toBeNull();
    expect($patient->first_name)->toBe('Kunal');
    expect($patient->blood_group)->toBeNull();
    expect($patient->uhid)->not->toBeEmpty();
});

test('duplicate detection endpoint flags matching phone numbers', function () {
    Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Dev',
        'last_name' => 'Anand',
        'phone' => '9876533333',
        'gender' => 'male',
        'uhid' => 'UHID-2026-0002',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->postJson('/clinic/patients/check-duplicate', [
            'phone' => '9876533333',
        ]);

    $response->assertStatus(200);
    $response->assertJson([
        'has_duplicates' => true,
        'count' => 1,
    ]);
});

test('patient registration creates profile, atomic UHID and registers timeline event', function () {
    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Neha',
            'last_name' => 'Verma',
            'phone' => '9876544444',
            'email' => 'neha@example.com',
            'gender' => 'female',
            'age' => 28,
            'address' => 'Flat 402, Lotus Heights',
            'city' => 'Pune',
            'state' => 'Maharashtra',
            'pincode' => '411001',
            'emergency_contact_name' => 'Rajesh Verma',
            'emergency_contact_phone' => '9876555555',
        ]);

    $patient = Patient::where('phone', '9876544444')->first();
    expect($patient)->not->toBeNull();
    expect($patient->profile)->not->toBeNull();
    expect($patient->profile->city)->toBe('Pune');
    expect($patient->profile->emergency_contact_name)->toBe('Rajesh Verma');

    $timelineEvent = PatientTimelineEvent::where('patient_id', $patient->id)->where('event_type', 'registered')->first();
    expect($timelineEvent)->not->toBeNull();

    $response->assertRedirect("/clinic/patients/{$patient->id}");
});

test('allergies and manual alerts recorded at registration surface as patient alerts', function () {
    $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Ananya',
            'last_name' => 'Bose',
            'phone' => '9876566666',
            'gender' => 'female',
            'allergies' => ['Penicillin', 'Peanuts'],
            'medical_alerts' => ['Fall risk'],
        ])
        ->assertRedirect();

    $patient = Patient::where('phone', '9876566666')->firstOrFail();

    $history = PatientMedicalHistory::where('patient_id', $patient->id)->first();
    expect($history)->not->toBeNull();
    expect($history->allergies)->toBe(['Penicillin', 'Peanuts']);

    $descriptions = $patient->alerts()->pluck('description')->all();
    expect($descriptions)->toContain('Allergy: Penicillin', 'Allergy: Peanuts', 'Fall risk');
});

test('picking a doctor at registration creates the first visit with that assignment', function () {
    $doctor = ClinicEmployee::create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Dr. Kabir Singh',
        'email' => 'dr.kabir@example.com',
        'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Yash',
            'last_name' => 'Rathi',
            'phone' => '9876577777',
            'gender' => 'male',
            'doctor_id' => $doctor->id,
            'city' => 'Nashik',
        ])
        ->assertRedirect();

    $patient = Patient::where('phone', '9876577777')->firstOrFail();

    $visit = PatientVisit::where('patient_id', $patient->id)->first();
    expect($visit)->not->toBeNull();
    expect($visit->doctor_id)->toBe($doctor->id);
    expect($visit->branch_id)->toBe($this->branch->id);

    // The doctor-assignment path still has to carry profile data through —
    // it uses a different service method than the no-doctor path.
    expect($patient->profile?->city)->toBe('Nashik');
});

test('a clinic can make an ordinarily-required field optional', function () {
    $this->clinic->update(['patient_field_config' => ['last_name' => 'optional', 'gender' => 'optional']]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Zara',
            'phone' => '9876588888',
            // last_name and gender deliberately omitted
        ]);

    $response->assertRedirect();
    $response->assertSessionDoesntHaveErrors(['last_name', 'gender']);
    expect(Patient::where('phone', '9876588888')->exists())->toBeTrue();
});

test('a clinic can make an ordinarily-optional field required, and it is actually enforced', function () {
    $this->clinic->update(['patient_field_config' => ['email' => 'required']]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Zoya',
            'last_name' => 'Khan',
            'phone' => '9876599999',
            'gender' => 'female',
            // email deliberately omitted despite being required by this clinic's config
        ]);

    $response->assertSessionHasErrors(['email']);
    expect(Patient::where('phone', '9876599999')->exists())->toBeFalse();
});

test('an unconfigured clinic falls back to the same required fields as before this setting existed', function () {
    // No patient_field_config set at all — the exact fixture shape every
    // clinic that existed before this feature shipped is in.
    expect($this->clinic->patient_field_config)->toBeNull();

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->post('/clinic/patients', [
            'first_name' => 'Ishaan',
            'phone' => '9876511122',
            // last_name and gender omitted — should still fail, matching pre-feature behaviour
        ]);

    $response->assertSessionHasErrors(['last_name', 'gender']);
});

test('multi-tenant patient isolation prevents cross-clinic search', function () {
    $otherClinic = Clinic::factory()->create(['name' => 'Other Clinic']);
    $otherPatient = Patient::create([
        'clinic_id' => $otherClinic->id,
        'first_name' => 'Secret',
        'last_name' => 'Patient',
        'phone' => '9999999999',
        'gender' => 'male',
        'uhid' => 'UHID-OTHER-01',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->get('/clinic/patients?q=Secret');

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->where('patients.total', 0)
    );
});

test('updating a patient logs which fields changed as a timeline event', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Meera',
        'last_name' => 'Desai',
        'phone' => '9876500000',
        'gender' => 'female',
        'uhid' => 'UHID-2026-0002',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->put("/clinic/patients/{$patient->id}", [
            'first_name' => 'Meera',
            'last_name' => 'Desai',
            'phone' => '9876511111', // changed
            'gender' => 'female',
            'city' => 'Mumbai', // new profile field
        ]);

    $response->assertRedirect();

    $event = PatientTimelineEvent::where('patient_id', $patient->id)
        ->where('event_type', 'demographics_updated')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_id)->toBe($this->user->id)
        ->and($event->context['updated_fields'])->toContain('phone')
        ->and($event->context['updated_fields'])->toContain('profile');
});

test('saving a patient with no actual changes does not log a redundant timeline event', function () {
    $patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Kiran',
        'last_name' => 'Joshi',
        'phone' => '9876522222',
        'gender' => 'male',
        'uhid' => 'UHID-2026-0003',
    ]);

    $this->actingAs($this->user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
            ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
        ])
        ->put("/clinic/patients/{$patient->id}", [
            'first_name' => 'Kiran',
            'last_name' => 'Joshi',
            'phone' => '9876522222',
            'gender' => 'male',
        ])
        ->assertRedirect();

    expect(PatientTimelineEvent::where('patient_id', $patient->id)->where('event_type', 'demographics_updated')->exists())->toBeFalse();
});
