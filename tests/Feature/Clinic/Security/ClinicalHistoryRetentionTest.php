<?php

use App\ClinicMembershipStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Deleting a branch or a staff record used to cascade away the clinic's
 * appointments, visits, encounters, queues and bills, or blank their doctor.
 * Records with history are now deactivated, never deleted — refused by the
 * admin screens and, underneath them, by the database.
 */

/** @return array{0: User, 1: Clinic, 2: Branch, 3: Branch} */
function retentionClinic(): array
{
    $organization = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);
    $owner = User::factory()->create(['organization_id' => $organization->id]);
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id, 'user_id' => $owner->id, 'status' => ClinicMembershipStatus::Active,
        'has_all_branches_access' => true, 'is_primary_owner' => true,
    ]);
    OnboardingProgress::create(['organization_id' => $organization->id, 'current_step' => 'completed', 'status' => 'completed']);
    $main = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id, 'is_main' => true]);
    $second = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $organization->id, 'is_main' => false, 'name' => 'Riverside']);

    return [$owner, $clinic, $main, $second];
}

function retentionEncounter(Clinic $clinic, Branch $branch, ?ClinicEmployee $doctor = null): ClinicalEncounter
{
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);

    return ClinicalEncounter::withoutGlobalScopes()->create([
        'organization_id' => $clinic->organization_id,
        'clinic_id' => $clinic->id,
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor?->id,
        'encounter_number' => 'ENC-'.fake()->unique()->numerify('######'),
        'status' => 'in_progress',
        'started_at' => now(),
    ]);
}

it('refuses to delete a branch that has seen patients, and keeps its records', function () {
    [$owner, $clinic, , $riverside] = retentionClinic();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    $appointment = Appointment::withoutGlobalScopes()->create([
        'organization_id' => $clinic->organization_id, 'clinic_id' => $clinic->id, 'branch_id' => $riverside->id,
        'patient_id' => $patient->id, 'scheduled_at' => now()->subDay(), 'duration_minutes' => 15, 'status' => 'completed',
    ]);

    $this->actingAs($owner)
        ->delete(route('admin.branches.destroy', $riverside))
        ->assertRedirect()
        ->assertSessionHas('error', 'Riverside has appointments on record and cannot be deleted. Set it to inactive instead.');

    expect(Branch::withoutGlobalScopes()->whereKey($riverside->id)->exists())->toBeTrue()
        ->and(Appointment::withoutGlobalScopes()->whereKey($appointment->id)->exists())->toBeTrue();
});

it('still deletes a branch created by mistake', function () {
    [$owner, , , $riverside] = retentionClinic();

    $this->actingAs($owner)
        ->delete(route('admin.branches.destroy', $riverside))
        ->assertRedirect(route('admin.branches.index'));

    expect(Branch::withoutGlobalScopes()->whereKey($riverside->id)->exists())->toBeFalse();
});

it('refuses to delete a doctor who has seen patients, and keeps the encounter attributed', function () {
    [$owner, $clinic, $main] = retentionClinic();
    $doctor = ClinicEmployee::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'name' => 'Dr Mehta', 'email' => 'mehta@clinic.test', 'status' => 'active']);
    $encounter = retentionEncounter($clinic, $main, $doctor);

    $this->actingAs($owner)
        ->delete(route('admin.employees.destroy', $doctor))
        ->assertRedirect()
        ->assertSessionHas('error', 'Dr Mehta has encounters on record and cannot be deleted. Set them to inactive instead.');

    expect(ClinicEmployee::withoutGlobalScopes()->whereKey($doctor->id)->exists())->toBeTrue()
        ->and($encounter->fresh()->doctor_id)->toBe($doctor->id);
});

it('makes the database refuse to delete a branch with visits', function () {
    [, $clinic, , $riverside] = retentionClinic();
    PatientVisit::factory()->create(['clinic_id' => $clinic->id, 'branch_id' => $riverside->id, 'patient_id' => Patient::factory()->create(['clinic_id' => $clinic->id])->id]);

    expect(fn () => DB::table('branches')->where('id', $riverside->id)->delete())->toThrow(QueryException::class);
});

it('makes the database refuse to delete a doctor who authored an encounter', function () {
    [, $clinic, $main] = retentionClinic();
    $doctor = ClinicEmployee::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'name' => 'Dr Rao', 'email' => 'rao@clinic.test', 'status' => 'active']);
    retentionEncounter($clinic, $main, $doctor);

    expect(fn () => DB::table('clinic_employees')->where('id', $doctor->id)->delete())->toThrow(QueryException::class);
});

it('makes the database refuse to delete a login that assigned a patient', function () {
    [$owner, $clinic] = retentionClinic();
    $doctor = ClinicEmployee::withoutGlobalScopes()->create(['clinic_id' => $clinic->id, 'name' => 'Dr Iyer', 'email' => 'iyer@clinic.test', 'status' => 'active']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $clinic->organization_id]);
    DB::table('patient_doctor_assignments')->insert([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'to_practitioner_id' => $doctor->id,
        'assigned_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::table('users')->where('id', $owner->id)->delete())->toThrow(QueryException::class);
});
