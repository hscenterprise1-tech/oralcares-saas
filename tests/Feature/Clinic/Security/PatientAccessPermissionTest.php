<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Patient search, duplicate-check and the visits list each need their own
 * permission, enforced on the route. Being a member of the clinic is not enough.
 */
function patientAccessRole(string $name, array $permissions): void
{
    setPermissionsTeamId(null);

    Role::firstOrCreate(['name' => $name, 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions(collect($permissions)
            ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
            ->all());
}

/**
 * @return array<string, int>
 */
function actAsPatientAccessRole(string $role, ?array $permissions = null): array
{
    $test = test();
    patientAccessRole($role, $permissions ?? ClinicRole::from($role)->permissions());

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $test->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role], [$test->branch]);
    $test->actingAs($user);

    return [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $test->branch->id,
    ];
}

beforeEach(function () {
    $this->clinic = Clinic::factory()->create();
    $this->branch = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'is_main' => true, 'status' => BranchStatus::Active]);

    $this->patient = Patient::withoutGlobalScopes()->create([
        'clinic_id' => $this->clinic->id, 'first_name' => 'Anjali', 'last_name' => 'Rao',
        'phone' => '9876500001', 'gender' => 'female', 'uhid' => 'UHID-A-0001',
    ]);

    $otherClinic = Clinic::factory()->create();
    Patient::withoutGlobalScopes()->create([
        'clinic_id' => $otherClinic->id, 'first_name' => 'Anjali', 'last_name' => 'Menon',
        'phone' => '9876500002', 'gender' => 'female', 'uhid' => 'UHID-B-0001',
    ]);
});

// ─── Patient search ─────────────────────────────────────────

it('lets a role with patient.search search patients', function () {
    $session = actAsPatientAccessRole(ClinicRole::Receptionist->value);

    $this->withSession($session)->getJson(route('clinic.patients.search', ['q' => 'Anjali']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.uhid', 'UHID-A-0001');
});

it('refuses patient search to a clinic member without patient.search, even by direct URL', function () {
    $session = actAsPatientAccessRole('Viewer Only', ['patient.view']);

    $this->withSession($session)->getJson(route('clinic.patients.search', ['q' => 'Anjali']))->assertForbidden();
});

it('never returns another clinic\'s patients from search', function () {
    $session = actAsPatientAccessRole(ClinicRole::Doctor->value);

    $this->withSession($session)->getJson(route('clinic.patients.search', ['q' => 'Menon']))
        ->assertOk()
        ->assertJsonCount(0);
});

// ─── Duplicate check ────────────────────────────────────────

it('lets a role that registers patients run the duplicate check', function () {
    $session = actAsPatientAccessRole(ClinicRole::Receptionist->value);

    $this->withSession($session)->postJson(route('clinic.patients.check-duplicate'), ['phone' => '9876500001'])
        ->assertOk()
        ->assertJsonPath('has_duplicates', true);
});

it('refuses the duplicate check to a role that cannot register patients', function () {
    $session = actAsPatientAccessRole(ClinicRole::Doctor->value);

    $this->withSession($session)->postJson(route('clinic.patients.check-duplicate'), ['phone' => '9876500001'])
        ->assertForbidden();
});

it('never matches another clinic\'s patient in the duplicate check', function () {
    $session = actAsPatientAccessRole(ClinicRole::Receptionist->value);

    $this->withSession($session)->postJson(route('clinic.patients.check-duplicate'), ['phone' => '9876500002'])
        ->assertOk()
        ->assertJsonPath('has_duplicates', false);
});

// ─── Visits list ────────────────────────────────────────────

it('opens the visits page for a role with visit.view', function () {
    PatientVisit::create([
        'clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id, 'patient_id' => $this->patient->id,
        'visit_date' => today(), 'visit_status' => 'checked_in', 'queue_status' => 'waiting',
    ]);
    $session = actAsPatientAccessRole(ClinicRole::Receptionist->value);

    $this->withSession($session)->get(route('clinic.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Clinic/Visits/Index')->has('visits.data', 1));
});

it('refuses the visits page to a role that can view patients but not visits', function () {
    $session = actAsPatientAccessRole(ClinicRole::BillingStaff->value);

    expect(ClinicRole::BillingStaff->permissions())->toContain('patient.view')->not->toContain('visit.view');

    $this->withSession($session)->get(route('clinic.visits.index'))->assertForbidden();
});

// ─── Existing roles receive the new permissions ─────────────

it('grants the new permissions to existing clinic roles according to their templates', function () {
    setPermissionsTeamId(null);
    foreach (ClinicRole::cases() as $role) {
        Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null]);
    }

    (require database_path('migrations/2026_09_29_100324_grant_visit_view_and_patient_search_permissions.php'))->up();

    $has = fn (ClinicRole $role, string $permission) => Role::where('name', $role->value)->first()->hasPermissionTo($permission);

    expect($has(ClinicRole::Receptionist, 'patient.search'))->toBeTrue()
        ->and($has(ClinicRole::Receptionist, 'visit.view'))->toBeTrue()
        ->and($has(ClinicRole::Doctor, 'visit.view'))->toBeTrue()
        ->and($has(ClinicRole::BillingStaff, 'patient.search'))->toBeTrue()
        ->and($has(ClinicRole::BillingStaff, 'visit.view'))->toBeFalse()
        ->and($has(ClinicRole::ClinicAdmin, 'visit.view'))->toBeTrue();
});
