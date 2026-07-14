<?php

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicMembershipRole;
use App\Models\Role;
use App\Models\User;
use App\PermissionKey;
use App\Services\AssignRoleToMembershipService;
use App\Services\ClinicAuthorization;
use App\Services\ClinicContext;
use App\Services\CloneSystemRolesToClinicService;
use Database\Seeders\SystemRoleTemplateSeeder;

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('permission registry accepts known keys and rejects unknown keys', function () {
    expect(PermissionKey::isValid('clinic.manage'))->toBeTrue()
        ->and(PermissionKey::isValid('invalid.permission'))->toBeFalse();
});

test('system role templates are clinic-neutral', function () {
    $systemRoles = Role::whereNull('clinic_id')->where('is_system', true)->get();

    expect($systemRoles)->not->toBeEmpty();
    foreach ($systemRoles as $role) {
        expect($role->clinic_id)->toBeNull();
    }
});

test('system templates cannot be directly assigned to clinic memberships', function () {
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create(['clinic_id' => $clinic->id]);
    $systemRole = Role::whereNull('clinic_id')->first();

    $service = new AssignRoleToMembershipService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($membership, $systemRole);
});

test('system roles can be cloned into a clinic and cloned permissions are copied', function () {
    $clinic = Clinic::factory()->create();
    $service = new CloneSystemRolesToClinicService;
    $service->execute($clinic);

    $clonedRoles = Role::where('clinic_id', $clinic->id)->get();
    expect($clonedRoles)->toHaveCount(4); // clinic_admin, doctor, receptionist, billing_staff

    $adminRole = $clonedRoles->firstWhere('key', 'clinic_admin');
    expect($adminRole->permissions)->toHaveCount(6); // all 6 permissions copied
});

test('role cloning is idempotent and does not overwrite clinic-customized permissions', function () {
    $clinic = Clinic::factory()->create();
    $service = new CloneSystemRolesToClinicService;
    $service->execute($clinic);

    // Customize the cloned role by removing a permission
    $clonedAdmin = Role::where('clinic_id', $clinic->id)->where('key', 'clinic_admin')->first();
    $clonedAdmin->permissions()->first()->delete();
    $originalCount = $clonedAdmin->permissions()->count();

    // Clone again
    $service->execute($clinic);

    // Verify count has not changed (not overwritten)
    expect($clonedAdmin->fresh()->permissions)->toHaveCount($originalCount);
});

test('clinic role cannot be assigned to another clinic membership', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $roleA = Role::factory()->create(['clinic_id' => $clinicA->id]);
    $membershipB = ClinicMembership::factory()->create(['clinic_id' => $clinicB->id]);

    $service = new AssignRoleToMembershipService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($membershipB, $roleA);
});

test('inactive role cannot be assigned', function () {
    $clinic = Clinic::factory()->create();
    $role = Role::factory()->inactive()->create(['clinic_id' => $clinic->id]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $clinic->id]);

    $service = new AssignRoleToMembershipService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($membership, $role);
});

test('branch-specific role assignment requires same-clinic branch', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $roleA = Role::factory()->create(['clinic_id' => $clinicA->id]);
    $membershipA = ClinicMembership::factory()->create(['clinic_id' => $clinicA->id]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id]);

    $service = new AssignRoleToMembershipService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($membershipA, $roleA, $branchB);
});

test('branch-specific role assignment requires existing membership branch access', function () {
    $clinic = Clinic::factory()->create();
    $role = Role::factory()->create(['clinic_id' => $clinic->id]);

    // No branch access
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'has_all_branches_access' => false,
    ]);

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $service = new AssignRoleToMembershipService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($membership, $role, $branch);
});

test('role assignment does not grant branch access', function () {
    $clinic = Clinic::factory()->create();
    $role = Role::factory()->create(['clinic_id' => $clinic->id]);
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'has_all_branches_access' => false,
    ]);

    $membership->assignBranch($branch);

    $service = new AssignRoleToMembershipService;
    $service->execute($membership, $role, $branch);

    // Verify role assignment is created, but branch access remains scoped strictly by assignBranch
    // (branch access pivot count should still be 1)
    expect($membership->assignedBranches)->toHaveCount(1);
});

test('duplicate role assignment is prevented or idempotently reused', function () {
    $clinic = Clinic::factory()->create();
    $role = Role::factory()->create(['clinic_id' => $clinic->id]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $clinic->id]);

    $service = new AssignRoleToMembershipService;

    $assignment1 = $service->execute($membership, $role);
    $assignment2 = $service->execute($membership, $role);

    expect($assignment1->id)->toBe($assignment2->id);
    expect(ClinicMembershipRole::count())->toBe(1);
});

test('primary owner receives valid permissions', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->primaryOwner()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $clinicContext->setMembership($membership);

    expect($authorization->can(PermissionKey::ClinicManage))->toBeTrue()
        ->and($authorization->can(PermissionKey::ReportsView))->toBeTrue();
});

test('non-owner receives permissions from active assigned roles', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => false,
    ]);

    $role = Role::factory()->create(['clinic_id' => $clinic->id]);
    $role->permissions()->create(['permission' => PermissionKey::ReportsView->value]);

    $clinicContext->setMembership($membership);

    // Initially has no permissions
    expect($authorization->can(PermissionKey::ReportsView))->toBeFalse();

    // Assign role clinic-wide
    $service = new AssignRoleToMembershipService;
    $service->execute($membership, $role);

    expect($authorization->can(PermissionKey::ReportsView))->toBeTrue()
        ->and($authorization->can(PermissionKey::ClinicManage))->toBeFalse();
});

test('inactive membership receives no permissions', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    // Inactive membership but marked as primary owner (who would normally have all permissions)
    $membership = ClinicMembership::factory()->primaryOwner()->inactive()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    // Setting an inactive membership to active context must throw an exception
    $clinicContext->setUser($user);
    $this->expectException(InvalidArgumentException::class);
    $clinicContext->setMembership($membership);
});

test('inactive role grants no permissions', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $role = Role::factory()->inactive()->create(['clinic_id' => $clinic->id]);
    $role->permissions()->create(['permission' => PermissionKey::ReportsView->value]);

    $service = new AssignRoleToMembershipService;
    // Force assign (bypass AssignRoleToMembershipService active check directly for testing evaluation behavior)
    ClinicMembershipRole::create([
        'clinic_membership_id' => $membership->id,
        'role_id' => $role->id,
    ]);

    $clinicContext->setMembership($membership);

    expect($authorization->can(PermissionKey::ReportsView))->toBeFalse();
});

test('clinic-wide role works across accessible branches', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => false,
    ]);

    $branchA = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    // Give access only to branchA
    $membership->assignBranch($branchA);

    $role = Role::factory()->create(['clinic_id' => $clinic->id]);
    $role->permissions()->create(['permission' => PermissionKey::ReportsView->value]);

    // Assign role clinic-wide
    $service = new AssignRoleToMembershipService;
    $service->execute($membership, $role);

    $clinicContext->setMembership($membership);

    // Can access branchA report, but not branchB (no branch access to branchB)
    expect($authorization->can(PermissionKey::ReportsView, $branchA))->toBeTrue()
        ->and($authorization->can(PermissionKey::ReportsView, $branchB))->toBeFalse();
});

test('branch-specific role works only for its assigned branch', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => false,
    ]);

    $branchA = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    $membership->assignBranch($branchA);
    $membership->assignBranch($branchB);

    $role = Role::factory()->create(['clinic_id' => $clinic->id]);
    $role->permissions()->create(['permission' => PermissionKey::ReportsView->value]);

    // Assign role only to branchA
    $service = new AssignRoleToMembershipService;
    $service->execute($membership, $role, $branchA);

    $clinicContext->setMembership($membership);

    expect($authorization->can(PermissionKey::ReportsView, $branchA))->toBeTrue()
        ->and($authorization->can(PermissionKey::ReportsView, $branchB))->toBeFalse();
});

test('cross-clinic branch permission check returns false', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinicA = Clinic::factory()->create();
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
    ]);

    $clinicB = Clinic::factory()->create();
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id]);

    $clinicContext->setMembership($membershipA);

    expect($authorization->can(PermissionKey::ReportsView, $branchB))->toBeFalse();
});

test('user with multiple roles receives union of permissions', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $role1 = Role::factory()->create(['clinic_id' => $clinic->id]);
    $role1->permissions()->create(['permission' => PermissionKey::ReportsView->value]);

    $role2 = Role::factory()->create(['clinic_id' => $clinic->id]);
    $role2->permissions()->create(['permission' => PermissionKey::StaffManage->value]);

    $service = new AssignRoleToMembershipService;
    $service->execute($membership, $role1);
    $service->execute($membership, $role2);

    $clinicContext->setMembership($membership);

    expect($authorization->can(PermissionKey::ReportsView))->toBeTrue()
        ->and($authorization->can(PermissionKey::StaffManage))->toBeTrue()
        ->and($authorization->can(PermissionKey::BranchManage))->toBeFalse();
});

test('user belonging to multiple clinics does not receive permissions across clinic contexts', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $user = User::factory()->create();
    $clinicContext->setUser($user);

    $clinicA = Clinic::factory()->create();
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
    ]);

    $clinicB = Clinic::factory()->create();
    $membershipB = ClinicMembership::factory()->create([
        'clinic_id' => $clinicB->id,
        'user_id' => $user->id,
    ]);

    $roleA = Role::factory()->create(['clinic_id' => $clinicA->id]);
    $roleA->permissions()->create(['permission' => PermissionKey::ReportsView->value]);

    $service = new AssignRoleToMembershipService;
    $service->execute($membershipA, $roleA);

    // Set context to ClinicB (where user has no roles/permissions assigned)
    $clinicContext->setMembership($membershipB);

    expect($authorization->can(PermissionKey::ReportsView))->toBeFalse();
});

test('company_user or master_admin behavior is not accidentally granted clinic permissions', function () {
    $clinicContext = new ClinicContext;
    $authorization = new ClinicAuthorization($clinicContext);

    $masterAdmin = User::factory()->masterAdmin()->create();
    $clinicContext->setUser($masterAdmin);

    // Try evaluating permission - should instantly return false for clinic permissions
    expect($authorization->can(PermissionKey::ReportsView))->toBeFalse();

    $companyUser = User::factory()->companyUser()->create();
    $clinicContext->setUser($companyUser);

    expect($authorization->can(PermissionKey::ReportsView))->toBeFalse();
});
