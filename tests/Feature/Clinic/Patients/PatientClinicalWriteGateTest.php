<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Policies\PatientPolicy;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `PatientPolicy::writeClinicalData()` — the first ownership gate on the
 * `App\Http\Controllers\Clinical\*` module. Exercised directly against the
 * policy rather than through those controllers: none of
 * `EncounterController`/`ClinicalPrescriptionController`/`ClinicalNoteController`
 * has a registered route today (confirmed via `php artisan route:list --path=clinical`
 * — zero results), so there is no HTTP entry point to test against yet. The
 * policy itself is the real, reachable unit of behaviour this change adds.
 */
function seedRoleForWriteGateTest(ClinicRole $role): void
{
    setPermissionsTeamId(null);

    $seeded = Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null]);
    $permissions = collect($role->permissions())
        ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
        ->all();

    $seeded->syncPermissions($permissions);
}

/**
 * Puts `ClinicContext` into the same state `ResolveClinicContext`/
 * `SetCurrentBranch` would during a real request, and sets the Spatie team
 * id the way `SetCurrentBranch` does — without going through an HTTP call,
 * since there's no route to make one against yet.
 */
function actAsClinicMember(User $user, ClinicMembership $membership, int $branchId): void
{
    setPermissionsTeamId($branchId);

    $context = app(ClinicContext::class);
    $context->setUser($user);
    $context->setMembership($membership);
}

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'Main Branch', 'is_main' => true, 'status' => BranchStatus::Active]);

    $this->doctorAUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Dr. A']);
    $this->doctorAEmployee = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $this->doctorAUser->id, 'name' => 'Dr. A', 'email' => 'dr.a@wg.test', 'status' => 'active']);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $this->doctorAEmployee->id, 'specialty' => 'Dentist', 'is_active' => true]);
    $this->doctorAMembership = ClinicMembership::factory()->create(['clinic_id' => $this->clinic->id, 'user_id' => $this->doctorAUser->id, 'is_primary_owner' => false, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active]);

    $this->doctorBUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Dr. B']);
    $this->doctorBEmployee = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $this->doctorBUser->id, 'name' => 'Dr. B', 'email' => 'dr.b@wg.test', 'status' => 'active']);
    Practitioner::create(['clinic_id' => $this->clinic->id, 'organization_id' => $this->clinic->organization_id, 'clinic_employee_id' => $this->doctorBEmployee->id, 'specialty' => 'Dentist', 'is_active' => true]);
    $this->doctorBMembership = ClinicMembership::factory()->create(['clinic_id' => $this->clinic->id, 'user_id' => $this->doctorBUser->id, 'is_primary_owner' => false, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active]);

    seedRoleForWriteGateTest(ClinicRole::Doctor);
    app(ClinicRoleAssignmentService::class)->syncRoles($this->doctorAUser, [ClinicRole::Doctor->value], [$this->branch]);
    app(ClinicRoleAssignmentService::class)->syncRoles($this->doctorBUser, [ClinicRole::Doctor->value], [$this->branch]);

    $this->receptionUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Reception']);
    $this->receptionMembership = ClinicMembership::factory()->create(['clinic_id' => $this->clinic->id, 'user_id' => $this->receptionUser->id, 'is_primary_owner' => false, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active]);
    seedRoleForWriteGateTest(ClinicRole::Receptionist);
    app(ClinicRoleAssignmentService::class)->syncRoles($this->receptionUser, [ClinicRole::Receptionist->value], [$this->branch]);

    $this->ownerUser = User::factory()->create(['user_type' => UserType::ClinicUser, 'name' => 'Owner']);
    $this->ownerMembership = ClinicMembership::factory()->create(['clinic_id' => $this->clinic->id, 'user_id' => $this->ownerUser->id, 'is_primary_owner' => true, 'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active]);

    $this->patient = Patient::create(['clinic_id' => $this->clinic->id, 'first_name' => 'Test', 'last_name' => 'Patient', 'phone' => '9876566601', 'gender' => 'male', 'uhid' => 'UHID-2026-0201']);
});

test('the assigned practitioner can write clinical data for their own patient', function () {
    $this->patient->update(['current_practitioner_id' => $this->doctorAEmployee->id]);
    actAsClinicMember($this->doctorAUser, $this->doctorAMembership, $this->branch->id);

    expect(app(PatientPolicy::class)->writeClinicalData($this->doctorAUser, $this->patient))->toBeTrue();
});

test('a different practitioner cannot write clinical data for a patient assigned to someone else', function () {
    $this->patient->update(['current_practitioner_id' => $this->doctorAEmployee->id]);
    actAsClinicMember($this->doctorBUser, $this->doctorBMembership, $this->branch->id);

    expect(app(PatientPolicy::class)->writeClinicalData($this->doctorBUser, $this->patient))->toBeFalse();
});

test('any clinically-permitted practitioner can write for a patient with no doctor assigned yet', function () {
    expect($this->patient->current_practitioner_id)->toBeNull();
    actAsClinicMember($this->doctorBUser, $this->doctorBMembership, $this->branch->id);

    expect(app(PatientPolicy::class)->writeClinicalData($this->doctorBUser, $this->patient))->toBeTrue();
});

test('the clinic owner can write clinical data for any patient regardless of assignment', function () {
    $this->patient->update(['current_practitioner_id' => $this->doctorAEmployee->id]);
    actAsClinicMember($this->ownerUser, $this->ownerMembership, $this->branch->id);

    expect(app(PatientPolicy::class)->writeClinicalData($this->ownerUser, $this->patient))->toBeTrue();
});

test('reception cannot write clinical data at all, assigned or not', function () {
    actAsClinicMember($this->receptionUser, $this->receptionMembership, $this->branch->id);

    expect(app(PatientPolicy::class)->writeClinicalData($this->receptionUser, $this->patient))->toBeFalse();
});
