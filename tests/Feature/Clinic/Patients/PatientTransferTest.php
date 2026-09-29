<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientDoctorAssignment;
use App\Models\PatientTimelineEvent;
use App\Models\Practitioner;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Global (`branch_id = null`) role templates — same seeding `SystemRoleTemplateSeeder`
 * does in production, mirrored from `ClinicBranchIsolationTest::seedClinicRole()`.
 */
function seedRoleForTransferTest(ClinicRole $role): void
{
    setPermissionsTeamId(null);

    $seeded = Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null]);
    $permissions = collect($role->permissions())
        ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
        ->all();

    $seeded->syncPermissions($permissions);
}

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['name' => 'Transfer Test Clinic', 'slug' => 'transfer-test']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id,
        'name' => 'Main Branch',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    $this->owner = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Clinic Owner']);
    $this->ownerMembership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $this->owner->id,
        'is_primary_owner' => true,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $this->doctorA = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Dr. A', 'email' => 'dr.a@example.com', 'status' => 'active']);
    $this->doctorB = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Dr. B', 'email' => 'dr.b@example.com', 'status' => 'active']);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $this->doctorA->id, 'specialty' => 'Dentist', 'is_active' => true]);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $this->doctorB->id, 'specialty' => 'Dentist', 'is_active' => true]);

    $this->patient = Patient::create([
        'clinic_id' => $this->clinic->id,
        'first_name' => 'Rahul',
        'last_name' => 'Sharma',
        'phone' => '9876544444',
        'gender' => 'male',
        'uhid' => 'UHID-2026-0044',
    ]);

    $this->session = [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $this->ownerMembership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ];
});

test('an owner can transfer a patient to a practitioner, recording an audit row and a timeline event', function () {
    $response = $this->actingAs($this->owner)->withSession($this->session)->post("/clinic/patients/{$this->patient->id}/transfer", [
        'to_practitioner_id' => $this->doctorA->id,
        'reason' => 'First assignment',
    ]);

    $response->assertRedirect();
    $this->patient->refresh();
    expect($this->patient->current_practitioner_id)->toBe($this->doctorA->id);

    $assignment = PatientDoctorAssignment::where('patient_id', $this->patient->id)->first();
    expect($assignment)->not->toBeNull();
    expect($assignment->from_practitioner_id)->toBeNull();
    expect($assignment->to_practitioner_id)->toBe($this->doctorA->id);
    expect($assignment->assigned_by)->toBe($this->owner->id);

    $event = PatientTimelineEvent::where('patient_id', $this->patient->id)->where('event_type', 'doctor_transferred')->first();
    expect($event)->not->toBeNull();
});

test('a second transfer records the previous doctor as from_practitioner_id and updates the live pointer', function () {
    $this->patient->update(['current_practitioner_id' => $this->doctorA->id]);

    $response = $this->actingAs($this->owner)->withSession($this->session)->post("/clinic/patients/{$this->patient->id}/transfer", [
        'to_practitioner_id' => $this->doctorB->id,
    ]);

    $response->assertRedirect();
    expect($this->patient->fresh()->current_practitioner_id)->toBe($this->doctorB->id);

    $assignment = PatientDoctorAssignment::where('patient_id', $this->patient->id)->latest()->first();
    expect($assignment->from_practitioner_id)->toBe($this->doctorA->id);
    expect($assignment->to_practitioner_id)->toBe($this->doctorB->id);
});

test('a practitioner cannot transfer a patient', function () {
    seedRoleForTransferTest(ClinicRole::Doctor);

    $doctorUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Doctor User']);
    $doctorMembership = ClinicMembership::factory()->create([
        'clinic_id' => $this->clinic->id,
        'user_id' => $doctorUser->id,
        'is_primary_owner' => false,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);
    app(ClinicRoleAssignmentService::class)->syncRoles($doctorUser, [ClinicRole::Doctor->value], [$this->branch]);

    $response = $this->actingAs($doctorUser)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $doctorMembership->id,
        ClinicContext::SESSION_BRANCH_ID => $this->branch->id,
    ])->post("/clinic/patients/{$this->patient->id}/transfer", [
        'to_practitioner_id' => $this->doctorA->id,
    ]);

    $response->assertStatus(403);
    expect($this->patient->fresh()->current_practitioner_id)->toBeNull();
});

test('transfer rejects a practitioner id from another clinic', function () {
    $otherClinic = Clinic::factory()->create();
    $foreignDoctor = ClinicEmployee::create(['clinic_id' => $otherClinic->id, 'name' => 'Dr. Foreign', 'email' => 'foreign@example.com', 'status' => 'active']);

    $response = $this->actingAs($this->owner)->withSession($this->session)->post("/clinic/patients/{$this->patient->id}/transfer", [
        'to_practitioner_id' => $foreignDoctor->id,
    ]);

    $response->assertSessionHasErrors(['to_practitioner_id']);
});
