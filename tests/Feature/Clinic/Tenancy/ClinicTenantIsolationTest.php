<?php

use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\User;
use App\Models\XrayRecord;
use App\Services\ClinicContext;
use App\UserType;

beforeEach(function () {
    app()->instance('enforce_tenant_scope_in_tests', true);
});

test('patient records are strictly isolated between clinics under tenant scope', function () {
    $clinicA = Clinic::factory()->create(['name' => 'Clinic Alpha']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic Beta']);

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    // Create patients in both clinics (bypass global scope for initial fixture seeding)
    $patientA = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinicA->id,
        'first_name' => 'John',
        'last_name' => 'Alpha',
        'gender' => 'male',
        'dob' => '1990-01-01',
        'phone' => '9876543210',
    ]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'Jane',
        'last_name' => 'Beta',
        'gender' => 'female',
        'dob' => '1992-02-02',
        'phone' => '9123456780',
    ]);

    // Now act as user of Clinic A with Clinic A active context
    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    $visiblePatients = Patient::all();

    expect($visiblePatients->pluck('id'))->toContain($patientA->id);
    expect($visiblePatients->pluck('id'))->not->toContain($patientB->id);
});

test('appointments are strictly scoped to the active clinic tenant', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $patientA = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinicA->id,
        'first_name' => 'John',
        'last_name' => 'Doe',
        'gender' => 'male',
        'dob' => '1990-01-01',
    ]);

    $patientB = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'gender' => 'female',
        'dob' => '1992-02-02',
    ]);

    $appointmentA = Appointment::withoutGlobalScopes()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'scheduled_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);

    $appointmentB = Appointment::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'scheduled_at' => now()->addHour(),
        'status' => 'scheduled',
    ]);

    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    $visibleAppointments = Appointment::all();

    expect($visibleAppointments->pluck('id'))->toContain($appointmentA->id);
    expect($visibleAppointments->pluck('id'))->not->toContain($appointmentB->id);
});

test('x-ray imaging and clinical diagnostics are scoped by clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $patientA = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $clinicA->id,
        'first_name' => 'Mark',
        'last_name' => 'Ruffalo',
        'gender' => 'male',
        'dob' => '1985-05-05',
    ]);

    $xrayA = XrayRecord::withoutGlobalScopes()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'xray_type' => 'OPG',
        'image_path' => 'xrays/opg_01.png',
        'status' => 'completed',
    ]);

    $xrayB = XrayRecord::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientA->id,
        'xray_type' => 'IOPA',
        'image_path' => 'xrays/iopa_02.png',
        'status' => 'completed',
    ]);

    $this->actingAs($userA);
    app(ClinicContext::class)->activateMembership($membershipA);

    $visibleXrays = XrayRecord::all();

    expect($visibleXrays->pluck('id'))->toContain($xrayA->id);
    expect($visibleXrays->pluck('id'))->not->toContain($xrayB->id);
});
