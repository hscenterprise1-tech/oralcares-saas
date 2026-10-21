<?php

use App\ClinicMembershipStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ConsentRecord;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\PatientMedicalHistory;
use App\Models\PatientProfile;
use App\Models\User;
use App\Services\ClinicContext;
use App\UserType;
use Illuminate\Database\Eloquent\Model;

/*
 * Patient-owned records used to carry only an organisation (or no tenant at
 * all), so two clinics of one organisation shared them and the tenant scope
 * could not hold them. They now carry the patient's clinic.
 */

/**
 * @return array<string, array{0: class-string<Model>, 1: array<string, mixed>}>
 */
function patientOwnedRecords(): array
{
    return [
        'profile' => [PatientProfile::class, []],
        'medical history' => [PatientMedicalHistory::class, []],
        'alert' => [PatientAlert::class, ['type' => 'allergy', 'description' => 'Penicillin']],
        'consent' => [ConsentRecord::class, ['consent_type' => 'treatment']],
    ];
}

function ownedPatient(Clinic $clinic): Patient
{
    return Patient::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
}

function workInClinic(Clinic $clinic): void
{
    $user = User::factory()->create(['user_type' => UserType::ClinicUser, 'organization_id' => $clinic->organization_id]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active, 'is_primary_owner' => true,
    ]);
    test()->actingAs($user);
    app(ClinicContext::class)->setUser($user);
    app(ClinicContext::class)->setMembership($membership);
}

it('takes the clinic of the patient it belongs to', function (string $model, array $attributes) {
    $clinic = Clinic::factory()->create();
    $patient = ownedPatient($clinic);

    $record = $model::withoutGlobalScopes()->create([...$attributes, 'patient_id' => $patient->id, 'organization_id' => $clinic->organization_id]);

    expect($record->clinic_id)->toBe($clinic->id);
})->with(patientOwnedRecords());

it('refuses a record for another clinic\'s patient', function (string $model, array $attributes) {
    $organization = Organization::factory()->create();
    $mine = Clinic::factory()->create(['organization_id' => $organization->id]);
    $sister = Clinic::factory()->create(['organization_id' => $organization->id]);
    $theirPatient = ownedPatient($sister);
    workInClinic($mine);

    expect(fn () => $model::create([...$attributes, 'patient_id' => $theirPatient->id, 'organization_id' => $organization->id]))
        ->toThrow(LogicException::class);
})->with(patientOwnedRecords());

it('hides a sister clinic\'s records from a clinic user', function (string $model, array $attributes) {
    $organization = Organization::factory()->create();
    $mine = Clinic::factory()->create(['organization_id' => $organization->id]);
    $sister = Clinic::factory()->create(['organization_id' => $organization->id]);
    $model::withoutGlobalScopes()->create([...$attributes, 'patient_id' => ownedPatient($sister)->id, 'organization_id' => $organization->id]);
    workInClinic($mine);
    app()->instance('enforce_tenant_scope_in_tests', true);

    expect($model::count())->toBe(0);
})->with(patientOwnedRecords());
