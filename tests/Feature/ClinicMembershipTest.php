<?php

use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\AssignPrimaryOwnerService;
use Illuminate\Database\QueryException;

test('a clinic_user can become a clinic member', function () {
    $user = User::factory()->create(); // default type is clinic_user
    $clinic = Clinic::factory()->create();

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    expect($membership->user_id)->toBe($user->id)
        ->and($membership->clinic_id)->toBe($clinic->id);
});

test('the same user cannot have duplicate memberships in the same clinic', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $this->expectException(QueryException::class);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);
});

test('the same user can belong to two different clinics', function () {
    $user = User::factory()->create();
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
    ]);

    $membershipB = ClinicMembership::factory()->create([
        'clinic_id' => $clinicB->id,
        'user_id' => $user->id,
    ]);

    expect($membershipA)->not->toBeNull()
        ->and($membershipB)->not->toBeNull();
});

test('master_admin cannot be assigned as a clinic primary owner', function () {
    $user = User::factory()->masterAdmin()->create();
    $clinic = Clinic::factory()->create();

    $service = new AssignPrimaryOwnerService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($clinic, $user);
});

test('company_user cannot be assigned as a clinic primary owner', function () {
    $user = User::factory()->companyUser()->create();
    $clinic = Clinic::factory()->create();

    $service = new AssignPrimaryOwnerService;

    $this->expectException(InvalidArgumentException::class);
    $service->execute($clinic, $user);
});

test('clinic_user can be assigned as primary owner and receives all-branches access', function () {
    $user = User::factory()->create(); // clinic_user
    $clinic = Clinic::factory()->create();

    $service = new AssignPrimaryOwnerService;
    $membership = $service->execute($clinic, $user);

    expect($membership->is_primary_owner)->toBeTrue()
        ->and($membership->has_all_branches_access)->toBeTrue();
});

test('primary owner assignment does not create duplicate membership records', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();

    // Create a regular membership first
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'is_primary_owner' => false,
    ]);

    $service = new AssignPrimaryOwnerService;
    $membership = $service->execute($clinic, $user);

    expect(ClinicMembership::where('clinic_id', $clinic->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and($membership->is_primary_owner)->toBeTrue();
});

test('clinic can retrieve its primary owner cleanly', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();

    $service = new AssignPrimaryOwnerService;
    $service->execute($clinic, $user);

    expect($clinic->primary_owner)->not->toBeNull()
        ->and($clinic->primary_owner->id)->toBe($user->id);
});

test('membership with all-branches access can access every branch belonging to its clinic', function () {
    $clinic = Clinic::factory()->create();
    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $membership = ClinicMembership::factory()->allBranchesAccess()->create(['clinic_id' => $clinic->id]);

    expect($membership->hasAccessToBranch($branch1))->toBeTrue()
        ->and($membership->hasAccessToBranch($branch2))->toBeTrue();
});

test('membership without all-branches access can access explicitly assigned branches', function () {
    $clinic = Clinic::factory()->create();
    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $membership = ClinicMembership::factory()->create(['clinic_id' => $clinic->id, 'has_all_branches_access' => false]);

    // Assign branch1 explicitly
    $membership->assignBranch($branch1);

    expect($membership->hasAccessToBranch($branch1))->toBeTrue()
        ->and($membership->hasAccessToBranch($branch2))->toBeFalse();
});

test('membership without assignment cannot access an unassigned branch', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);

    $membership = ClinicMembership::factory()->create(['clinic_id' => $clinic->id, 'has_all_branches_access' => false]);

    expect($membership->hasAccessToBranch($branch))->toBeFalse();
});

test('a membership cannot be assigned a branch belonging to another clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $branchOfB = Branch::factory()->create(['clinic_id' => $clinicB->id]);
    $membershipOfA = ClinicMembership::factory()->create(['clinic_id' => $clinicA->id]);

    $this->expectException(InvalidArgumentException::class);
    $membershipOfA->assignBranch($branchOfB);
});

test('duplicate branch assignments are rejected', function () {
    $clinic = Clinic::factory()->create();
    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $membership = ClinicMembership::factory()->create(['clinic_id' => $clinic->id]);

    $membership->assignBranch($branch);

    // Sync without detaching shouldn't throw an error but shouldn't duplicate
    $membership->assignBranch($branch);

    expect($membership->assignedBranches)->toHaveCount(1);
});

test('enum and boolean casts work correctly for memberships', function () {
    $membership = ClinicMembership::factory()->create([
        'is_primary_owner' => 1, // database int/boolean representation
        'has_all_branches_access' => 0,
        'status' => 'inactive',
    ]);

    expect($membership->is_primary_owner)->toBeTrue()
        ->and($membership->has_all_branches_access)->toBeFalse()
        ->and($membership->status)->toBe(ClinicMembershipStatus::Inactive);
});

test('relationships work in all required directions', function () {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create();

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id]);
    $membership->assignBranch($branch);

    // Direct model relations
    expect($clinic->memberships)->toHaveCount(1)
        ->and($user->clinicMemberships)->toHaveCount(1)
        ->and($membership->clinic->id)->toBe($clinic->id)
        ->and($membership->user->id)->toBe($user->id)
        ->and($membership->assignedBranches->first()->id)->toBe($branch->id)
        ->and($branch->memberships->first()->id)->toBe($membership->id);
});
