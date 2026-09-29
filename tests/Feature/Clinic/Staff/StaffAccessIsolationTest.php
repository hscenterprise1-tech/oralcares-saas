<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicEmployee;
use App\Models\ClinicMembership;
use App\Models\Patient;
use App\Models\PatientVisit;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Staff operations run as: active clinic → membership → branch → role/permission.
 * Nothing about one clinic's staff is reachable from another clinic, and a
 * member without branch access reaches no branch's records.
 */
beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);

    $this->clinic = Clinic::factory()->create();
    $this->main = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'Main', 'is_main' => true, 'status' => BranchStatus::Active]);
    $this->east = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'East', 'status' => BranchStatus::Active]);

    $this->otherClinic = Clinic::factory()->create();
    $this->otherBranch = Branch::factory()->create(['clinic_id' => $this->otherClinic->id, 'status' => BranchStatus::Active]);
    $this->otherDoctor = ClinicEmployee::create(['clinic_id' => $this->otherClinic->id, 'name' => 'Dr. Elsewhere', 'email' => 'elsewhere@other.test', 'status' => 'active']);

    $this->patient = Patient::withoutGlobalScopes()->create(['clinic_id' => $this->clinic->id, 'first_name' => 'Meena', 'last_name' => 'Iyer', 'phone' => '9000000001', 'gender' => 'female', 'uhid' => 'UHID-S-1']);

    /**
     * A clinic login with the given roles on the given branches. `$access`
     * is the membership's branch access (null = all branches).
     */
    $this->member = function (array $roles, array $roleBranches, ?array $access = [], ?string $employeeStatus = null): array {
        $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
        $membership = ClinicMembership::factory()->create([
            'clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'status' => ClinicMembershipStatus::Active,
            'has_all_branches_access' => $access === null,
        ]);
        if ($access) {
            $membership->assignedBranches()->sync(collect($access)->pluck('id'));
        }
        if ($employeeStatus) {
            ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'name' => 'Staff', 'email' => "staff{$user->id}@clinic.test", 'status' => $employeeStatus]);
        }
        app(ClinicRoleAssignmentService::class)->syncRoles($user, $roles, $roleBranches);

        return [$user, array_filter([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => ($access === null ? ($roleBranches[0] ?? null)?->id : null),
        ])];
    };
});

// ─── Membership and staff status ────────────────────────────

it('ends a login\'s access to the clinic when their staff record is made inactive', function () {
    [$user, $session] = ($this->member)([ClinicRole::Receptionist->value], [$this->main], [$this->main], 'active');
    $this->actingAs($user)->withSession($session)->get(route('clinic.dashboard'))->assertOk();

    ClinicEmployee::withoutGlobalScopes()->where('user_id', $user->id)->update(['status' => 'resigned']);

    $this->actingAs($user)->withSession($session)->get(route('clinic.dashboard'))->assertForbidden();
});

it('refuses clinic pages to a login with no membership in the clinic', function () {
    $stranger = User::factory()->create(['user_type' => UserType::ClinicUser]);

    $this->actingAs($stranger)->get(route('admin.employees.index'))->assertForbidden();
});

// ─── Branch access ──────────────────────────────────────────

it('gives a member with no branch access no branch-scoped records and no fallback to the main branch', function () {
    [$user, $session] = ($this->member)([ClinicRole::Receptionist->value], [$this->main], []);
    $as = fn () => $this->actingAs($user)->withSession($session);

    $as()->get(route('clinic.reception'))->assertForbidden();
    $as()->get(route('clinic.visits.index'))->assertForbidden();
    $as()->get(route('clinic.appointments.index'))->assertForbidden();
    $as()->post(route('clinic.visits.store'), ['patient_id' => $this->patient->id])->assertForbidden();

    expect(PatientVisit::withoutGlobalScopes()->count())->toBe(0);
});

it('shows a member only the branch they have access to', function () {
    foreach ([$this->main, $this->east] as $branch) {
        PatientVisit::create(['clinic_id' => $this->clinic->id, 'branch_id' => $branch->id, 'patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_status' => 'checked_in']);
    }
    [$user, $session] = ($this->member)([ClinicRole::Receptionist->value], [$this->east], [$this->east]);

    $this->actingAs($user)->withSession($session)->get(route('clinic.visits.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('visits.data', 1)->where('visits.data.0.branch_name', 'East'));
});

// ─── Cross-clinic ───────────────────────────────────────────

it('keeps another clinic\'s staff out of every staff action', function () {
    [$admin, $session] = ($this->member)([ClinicRole::ClinicAdmin->value], [$this->main, $this->east], null);
    $mine = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Mine', 'email' => 'mine@clinic.test', 'status' => 'active']);
    $as = fn () => $this->actingAs($admin)->withSession($session);

    $as()->get(route('admin.employees.index'))
        ->assertInertia(fn ($page) => $page->has('employees', 1)->where('employees.0.id', $mine->id));

    $as()->get(route('admin.employees.edit', $this->otherDoctor->id))->assertForbidden();
    $as()->put(route('admin.employees.update', $this->otherDoctor->id), ['name' => 'Taken', 'status' => 'active'])->assertForbidden();

    $as()->put(route('admin.employees.update', $mine->id), ['name' => 'Mine', 'email' => 'mine@clinic.test', 'status' => 'active', 'branches' => [$this->otherBranch->id]])
        ->assertSessionHasErrors('branches.0');
});

it('refuses to attach another clinic\'s doctor to an appointment or a visit', function () {
    [$user, $session] = ($this->member)([ClinicRole::Receptionist->value], [$this->main], [$this->main]);
    $as = fn () => $this->actingAs($user)->withSession($session);

    $as()->post(route('clinic.appointments.store'), [
        'patient_id' => $this->patient->id, 'practitioner_id' => $this->otherDoctor->id, 'scheduled_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
    ])->assertSessionHasErrors('practitioner_id');

    $as()->post(route('clinic.visits.store'), ['patient_id' => $this->patient->id, 'doctor_id' => $this->otherDoctor->id])
        ->assertSessionHasErrors('doctor_id');
});

// ─── Roles ──────────────────────────────────────────────────

it('lets only a role holder with roles.manage change what staff may do', function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'Staff Coordinator', 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions([Permission::firstOrCreate(['name' => 'staff.manage', 'guard_name' => 'web'])]);
    [$coordinator, $session] = ($this->member)(['Staff Coordinator'], [$this->main], [$this->main]);
    $employee = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Nurse', 'email' => 'nurse@clinic.test', 'status' => 'active']);
    $doctorRole = Role::where('name', ClinicRole::Doctor->value)->whereNull('branch_id')->first();

    $this->actingAs($coordinator)->withSession($session)
        ->put(route('admin.employees.update', $employee->id), ['name' => 'Nurse Renamed', 'email' => 'nurse@clinic.test', 'status' => 'active', 'roles' => [$doctorRole->id]])
        ->assertForbidden();

    $this->actingAs($coordinator)->withSession($session)
        ->put(route('admin.employees.update', $employee->id), ['name' => 'Nurse Renamed', 'email' => 'nurse@clinic.test', 'status' => 'active'])
        ->assertRedirect(route('admin.employees.index'));

    expect($employee->fresh()->name)->toBe('Nurse Renamed')
        ->and($employee->roles()->count())->toBe(0);
});

it('refuses to hand clinic staff a role that is not a clinic role', function () {
    [$admin, $session] = ($this->member)([ClinicRole::ClinicAdmin->value], [$this->main], null);
    $employee = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'name' => 'Nurse', 'email' => 'nurse@clinic.test', 'status' => 'active']);
    setPermissionsTeamId(null);
    $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web', 'branch_id' => null]);

    $this->actingAs($admin)->withSession($session)
        ->put(route('admin.employees.update', $employee->id), ['name' => 'Nurse', 'email' => 'nurse@clinic.test', 'status' => 'active', 'roles' => [$superAdmin->id]])
        ->assertSessionHasErrors('roles.0');
});

it('keeps a linked staff member\'s roles in Spatie only, per branch, and shows those roles', function () {
    [$admin, $session] = ($this->member)([ClinicRole::ClinicAdmin->value], [$this->main], null);
    [$doctorUser] = ($this->member)([], [], [$this->main]);
    $employee = ClinicEmployee::create(['clinic_id' => $this->clinic->id, 'user_id' => $doctorUser->id, 'name' => 'Dr. Linked', 'email' => 'linked@clinic.test', 'status' => 'active']);
    DB::table('clinic_employee_branches')->insert(['clinic_employee_id' => $employee->id, 'branch_id' => $this->main->id]);
    $doctorRole = Role::where('name', ClinicRole::Doctor->value)->whereNull('branch_id')->first();

    $this->actingAs($admin)->withSession($session)
        ->put(route('admin.employees.update', $employee->id), ['name' => 'Dr. Linked', 'email' => 'linked@clinic.test', 'status' => 'active', 'branches' => [$this->main->id], 'roles' => [$doctorRole->id]])
        ->assertRedirect(route('admin.employees.index'));

    setPermissionsTeamId($this->main->id);
    expect($doctorUser->fresh()->hasRole(ClinicRole::Doctor->value))->toBeTrue()
        ->and($employee->roles()->count())->toBe(0);

    $this->actingAs($admin)->withSession($session)->get(route('admin.employees.edit', $employee->id))
        ->assertInertia(fn ($page) => $page->where('employee.roles.0.name', ClinicRole::Doctor->value));
});
