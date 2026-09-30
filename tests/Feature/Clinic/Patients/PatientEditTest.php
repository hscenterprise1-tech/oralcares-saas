<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientMedicalHistory;
use App\Models\PatientTimelineEvent;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'City Dental & Care', 'slug' => 'city-dental-edit']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->user = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Owner User']);

    $this->membership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->user->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Meera',
        'last_name' => 'Iyer',
        'phone' => '9876533333',
        'gender' => 'female',
        'age' => 40,
        'uhid' => 'UHID-2026-0033',
    ]);

    $this->session = [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->membership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ];
});

test('edit page pre-fills the existing patient and hides fields the clinic marked hidden', function () {
    $this->clinic->update(['patient_field_config' => ['blood_group' => 'hidden']]);
    PatientMedicalHistory::create([
        'organization_id' => $this->clinic->organization_id,
        'patient_id' => $this->patient->id,
        'allergies' => ['Penicillin'],
    ]);

    $response = $this->actingAs($this->user)->withSession($this->session)->get("/clinic/patients/{$this->patient->id}/edit");

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Clinic/Patients/Edit')
        ->where('patient.first_name', 'Meera')
        ->where('patient.last_name', 'Iyer')
        ->where('patient.phone', '9876533333')
        ->where('patient.allergies', ['Penicillin'])
        ->where('fieldConfig.blood_group', 'hidden')
    );
});

test('updating a patient enforces the clinic field config exactly like registration does', function () {
    $this->clinic->update(['patient_field_config' => ['email' => 'required']]);

    $response = $this->actingAs($this->user)->withSession($this->session)->put("/clinic/patients/{$this->patient->id}", [
        'first_name' => 'Meera',
        'last_name' => 'Iyer',
        'phone' => '9876533333',
        'gender' => 'female',
        // email deliberately omitted despite being required by this clinic's config
    ]);

    $response->assertSessionHasErrors(['email']);
    expect($this->patient->fresh()->email)->toBeNull();
});

test('updating a patient syncs allergies and manual alerts, clearing them when the form empties them', function () {
    PatientMedicalHistory::create([
        'organization_id' => $this->clinic->organization_id,
        'patient_id' => $this->patient->id,
        'allergies' => ['Penicillin', 'Latex'],
    ]);
    $this->patient->alerts()->create(['type' => 'manual', 'description' => 'Fall risk', 'severity' => 'high']);

    $response = $this->actingAs($this->user)->withSession($this->session)->put("/clinic/patients/{$this->patient->id}", [
        'first_name' => 'Meera',
        'last_name' => 'Iyer',
        'phone' => '9876533333',
        'gender' => 'female',
        'allergies' => ['Sulfa Drugs'],
        'medical_alerts' => ['Pacemaker'],
    ]);

    $response->assertRedirect();
    $this->patient->refresh();
    expect($this->patient->medicalHistory->allergies)->toBe(['Sulfa Drugs']);
    expect($this->patient->alerts()->where('type', 'manual')->pluck('description')->all())->toBe(['Pacemaker']);
    expect($this->patient->alerts()->where('description', 'Fall risk')->exists())->toBeFalse();
});

test('an edit that only changes allergies or manual alerts still writes a timeline event', function () {
    $response = $this->actingAs($this->user)->withSession($this->session)->put("/clinic/patients/{$this->patient->id}", [
        'first_name' => 'Meera',
        'last_name' => 'Iyer',
        'phone' => '9876533333',
        'gender' => 'female',
        'medical_alerts' => ['Post-op nausea watch'],
    ]);

    $response->assertRedirect();
    $event = PatientTimelineEvent::where('patient_id', $this->patient->id)->where('event_type', 'demographics_updated')->first();
    expect($event)->not->toBeNull();
    expect($event->context['updated_fields'])->toContain('medical_alerts');
});
