<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicRole;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\ClinicRoleAssignmentService;
use App\UserType;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Global (`branch_id = null`) role templates, seeded the same way
 * `SystemRoleTemplateSeeder` does in production — a role name alone isn't
 * enough for `getAllPermissions()` to resolve anything.
 */
function seedClinicRole(ClinicRole $role): void
{
    setPermissionsTeamId(null);

    $seeded = Role::firstOrCreate(['name' => $role->value, 'guard_name' => 'web', 'branch_id' => null]);
    $permissions = collect($role->permissions())
        ->map(fn (string $perm) => Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']))
        ->all();

    $seeded->syncPermissions($permissions);
}

test('clinic user with multi-branch access can switch branch successfully', function () {
    $clinic = Clinic::factory()->create();
    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Downtown Branch', 'status' => BranchStatus::Active]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Uptown Branch', 'status' => BranchStatus::Active]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $branch1->id,
        ])
        ->post('/clinic/branches/switch', [
            'branch_id' => $branch2->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas(ClinicContext::SESSION_BRANCH_ID, $branch2->id);
    $response->assertSessionHas('success', 'Active branch switched to Uptown Branch.');
});

test('clinic user cannot switch to a branch belonging to another clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'status' => BranchStatus::Active]);
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'status' => BranchStatus::Active]);

    $userA = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $userA->id,
        'has_all_branches_access' => true,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($userA)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membershipA->id,
            ClinicContext::SESSION_BRANCH_ID => $branchA->id,
        ])
        ->post('/clinic/branches/switch', [
            'branch_id' => $branchB->id,
        ]);

    $response->assertStatus(403);
});

test('clinic user cannot switch to an unassigned branch without all-branch access', function () {
    $clinic = Clinic::factory()->create();
    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => false,
        'status' => ClinicMembershipStatus::Active,
    ]);

    // Assign only branch1
    $membership->assignedBranches()->attach($branch1->id);

    $response = $this->actingAs($user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
            ClinicContext::SESSION_BRANCH_ID => $branch1->id,
        ])
        ->post('/clinic/branches/switch', [
            'branch_id' => $branch2->id,
        ]);

    $response->assertStatus(403);
});

test('a non-owner user holding two roles gets their union on an assigned branch and nothing on an unassigned one', function () {
    seedClinicRole(ClinicRole::ClinicAdmin);
    seedClinicRole(ClinicRole::Doctor);

    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    OnboardingProgress::create(['organization_id' => $org->id, 'current_step' => 'completed', 'status' => 'completed']);
    CompanySubscription::factory()->create(['clinic_id' => $clinic->id]);

    $assignedBranch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $org->id, 'status' => BranchStatus::Active]);
    $unassignedBranch = Branch::factory()->create(['clinic_id' => $clinic->id, 'organization_id' => $org->id, 'status' => BranchStatus::Active]);

    $user = User::factory()->create(['organization_id' => $org->id, 'user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        // The owner bypass must not be what carries this test — a real
        // combined-role user is not necessarily the primary owner.
        'is_primary_owner' => false,
        'has_all_branches_access' => false,
        'status' => ClinicMembershipStatus::Active,
    ]);
    // Access to both branches, but the role assignment below only covers
    // one — modelling an admin who granted branch access before finishing
    // the role setup on the second branch.
    $membership->assignedBranches()->attach([$assignedBranch->id, $unassignedBranch->id]);

    app(ClinicRoleAssignmentService::class)->syncRoles(
        $user,
        [ClinicRole::ClinicAdmin->value, ClinicRole::Doctor->value],
        [$assignedBranch]
    );

    // On the branch they were actually given roles on: both role names
    // present (what a future "is this person a Doctor" check would read),
    // and the full permission union granted.
    $this->actingAs($user)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $assignedBranch->id,
    ])->get('/clinic/dashboard')->assertInertia(fn ($page) => $page
        ->where('clinic.is_owner', false)
        ->where('auth.user.roles', fn ($roles) => collect($roles)->contains('Clinic Admin') && collect($roles)->contains('Doctor'))
        ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('staff.manage') && collect($permissions)->contains('clinical.edit'))
    );

    // On the other branch they can access but were never assigned a role
    // on: nothing. Spatie's team scoping, not a clinic-wide grant.
    //
    // Real requests each boot the app fresh, so `SetCurrentBranch` setting
    // a new team id is enough on its own — this is purely a same-process
    // test artifact: two `get()` calls in one test share the container, and
    // Spatie's permission map is cached across team ids unless cleared,
    // the same reason `tests/Pest.php`'s role-grant helpers clear it after
    // every mutation.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user->unsetRelation('roles')->unsetRelation('permissions');

    $this->actingAs($user)->withSession([
        ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membership->id,
        ClinicContext::SESSION_BRANCH_ID => $unassignedBranch->id,
    ])->get('/clinic/dashboard')->assertInertia(fn ($page) => $page
        ->where('clinic.is_owner', false)
        ->where('auth.user.roles', fn ($roles) => collect($roles)->isEmpty())
        ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->isEmpty())
    );
});
