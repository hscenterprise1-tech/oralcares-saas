<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicDepartment;
use App\Models\ClinicMembership;
use App\Models\ClinicRoom;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Every Clinic Admin area sits behind a `clinic_permission:` route check:
 * staff.manage (employees, invitations), branch.manage (branches,
 * departments, rooms), settings.manage (settings).
 */
function seedAdminPermissionRole(ClinicRole $role): void
{
    setPermissionsTeamId(null);

    Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null])
        ->syncPermissions(collect($role->permissions())
            ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
            ->all());
}

/**
 * @return array<string, string>
 */
function actAsClinicRole(ClinicRole $role): array
{
    $test = test();
    seedAdminPermissionRole($role);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $test->clinic->id, 'user_id' => $user->id, 'is_primary_owner' => false,
        'has_all_branches_access' => true, 'status' => ClinicMembershipStatus::Active,
    ]);
    app(ClinicRoleAssignmentService::class)->syncRoles($user, [$role->value], [$test->branch]);
    $test->actingAs($user);

    return [
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $test->branch->id,
    ];
}

beforeEach(function () {
    $this->clinic = Clinic::factory()->create(['slug' => 'admin-permission-test']);
    $this->branch = Branch::factory()->create([
        'clinic_id' => $this->clinic->id, 'is_main' => true, 'status' => BranchStatus::Active,
    ]);
});

test('staff without the admin permission are refused every admin page', function (ClinicRole $role, string $url) {
    $session = actAsClinicRole($role);

    $this->withSession($session)->get($url)->assertForbidden();
})->with([
    'receptionist' => ClinicRole::Receptionist,
    'doctor' => ClinicRole::Doctor,
])->with([
    'employees' => '/clinic/admin/employees',
    'new employee' => '/clinic/admin/employees/create',
    'branches' => '/clinic/admin/branches',
    'departments' => '/clinic/admin/departments',
    'rooms' => '/clinic/admin/rooms',
    'settings' => '/clinic/admin/settings',
]);

test('a receptionist cannot change branch infrastructure', function () {
    $session = actAsClinicRole(ClinicRole::Receptionist);

    $this->withSession($session)->post('/clinic/admin/branches', ['name' => 'Rogue Branch'])->assertForbidden();
    $this->withSession($session)->post('/clinic/admin/rooms', ['branch_id' => $this->branch->id, 'name' => 'Rogue Room'])->assertForbidden();
    $this->withSession($session)->post('/clinic/admin/departments', ['name' => 'Rogue Dept'])->assertForbidden();

    expect(Branch::where('name', 'Rogue Branch')->exists())->toBeFalse()
        ->and(ClinicRoom::where('name', 'Rogue Room')->exists())->toBeFalse()
        ->and(ClinicDepartment::where('name', 'Rogue Dept')->exists())->toBeFalse();
});

test('a receptionist cannot invite or add staff', function () {
    $session = actAsClinicRole(ClinicRole::Receptionist);

    $this->withSession($session)->post('/clinic/admin/employees', ['name' => 'Rogue Hire', 'email' => 'rogue@example.com'])->assertForbidden();
    $this->withSession($session)->post('/clinic/admin/invitations', ['email' => 'rogue@example.com'])->assertForbidden();
});

test('a clinic admin who is not the owner can open every admin page', function (string $url) {
    $session = actAsClinicRole(ClinicRole::ClinicAdmin);

    $this->withSession($session)->get($url)->assertOk();
})->with([
    '/clinic/admin/employees',
    '/clinic/admin/branches',
    '/clinic/admin/departments',
    '/clinic/admin/rooms',
    '/clinic/admin/settings',
]);
