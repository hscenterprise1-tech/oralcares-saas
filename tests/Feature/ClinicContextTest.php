<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use App\Services\ClinicContext;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('web')->get('/_test/clinic-context-status', function (ClinicContext $context) {
        return response()->json([
            'has_user' => $context->user() !== null,
            'user_id' => $context->user()?->id,
            'membership_id' => $context->membership()?->id,
            'clinic_id' => $context->clinic()?->id,
            'branch_id' => $context->branch()?->id,
        ]);
    });
});

test('a clinic_user with one active membership auto-resolves clinic context', function () {
    $user = User::factory()->create(); // ClinicUser type by default
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'status' => ClinicMembershipStatus::Active,
    ]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'user_id' => $user->id,
        'membership_id' => $membership->id,
        'clinic_id' => $clinic->id,
    ]);
});

test('a clinic_user with multiple memberships uses a valid session-selected membership', function () {
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

    // Pass session key to act as selected
    $response = $this->actingAs($user)
        ->withSession([ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membershipB->id])
        ->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => $membershipB->id,
        'clinic_id' => $clinicB->id,
    ]);
});

test('multiple memberships without valid session selection leave clinic unresolved', function () {
    $user = User::factory()->create();
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
    ]);

    ClinicMembership::factory()->create([
        'clinic_id' => $clinicB->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => null,
        'clinic_id' => null,
    ]);
});

test('another user membership ID in session is rejected and cleared', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $clinic = Clinic::factory()->create();

    $membershipOfOther = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $otherUser->id,
    ]);

    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    // Active user has TWO memberships of their own, so they do not auto-resolve
    $membershipOfUser1 = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
    ]);
    $membershipOfUser2 = ClinicMembership::factory()->create([
        'clinic_id' => $clinicB->id,
        'user_id' => $user->id,
    ]);

    // We store the other user's membership ID in session
    $response = $this->actingAs($user)
        ->withSession([
            ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID => $membershipOfOther->id,
            ClinicContext::SESSION_BRANCH_ID => 1,
        ])
        ->get('/_test/clinic-context-status');

    // The middleware should reject the other user's membership and fall back.
    // Since there are other active memberships, but no valid session match, it leaves unresolved.
    $response->assertJson([
        'membership_id' => null,
        'clinic_id' => null,
    ]);

    $this->assertNull(session(ClinicContext::SESSION_CLINIC_MEMBERSHIP_ID));
    $this->assertNull(session(ClinicContext::SESSION_BRANCH_ID));
});

test('inactive membership cannot become active context', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->inactive()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => null,
        'clinic_id' => null,
    ]);
});

test('suspended membership cannot become active context', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->suspended()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => null,
        'clinic_id' => null,
    ]);
});

test('master_admin is not automatically forced into clinic context', function () {
    $user = User::factory()->masterAdmin()->create();
    $clinic = Clinic::factory()->create();
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => null,
        'clinic_id' => null,
    ]);
});

test('company_user is not automatically forced into clinic context', function () {
    $user = User::factory()->companyUser()->create();
    $clinic = Clinic::factory()->create();
    ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => null,
        'clinic_id' => null,
    ]);
});

test('one accessible active branch auto-resolves', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'membership_id' => $membership->id,
        'branch_id' => $branch->id,
    ]);
});

test('multiple branches use valid session-selected branch', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    $branch1 = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);
    $branch2 = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    $response = $this->actingAs($user)
        ->withSession([ClinicContext::SESSION_BRANCH_ID => $branch2->id])
        ->get('/_test/clinic-context-status');

    $response->assertJson([
        'branch_id' => $branch2->id,
    ]);
});

test('multiple branches without selection leave branch unresolved', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);
    Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'branch_id' => null,
    ]);
});

test('cross-clinic branch session ID is rejected and cleared', function () {
    $user = User::factory()->create();
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    // Active branch in session belongs to clinicB
    $branchOfB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'status' => BranchStatus::Active]);

    // ClinicA has two active branches (so it doesn't auto-resolve)
    Branch::factory()->create(['clinic_id' => $clinicA->id, 'status' => BranchStatus::Active]);
    Branch::factory()->create(['clinic_id' => $clinicA->id, 'status' => BranchStatus::Active]);

    $response = $this->actingAs($user)
        ->withSession([ClinicContext::SESSION_BRANCH_ID => $branchOfB->id])
        ->get('/_test/clinic-context-status');

    $response->assertJson([
        'branch_id' => null,
    ]);

    $this->assertNull(session(ClinicContext::SESSION_BRANCH_ID));
});

test('inactive branch is rejected', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    $branch = Branch::factory()->inactive()->create(['clinic_id' => $clinic->id]);

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'branch_id' => null,
    ]);
});

test('inaccessible branch is rejected', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();

    // No all-branch access
    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => false,
    ]);

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);
    // Not assigned to this branch

    $response = $this->actingAs($user)->get('/_test/clinic-context-status');

    $response->assertJson([
        'branch_id' => null,
    ]);
});

test('all-branches access allows access to any active branch in the same clinic', function () {
    $user = User::factory()->create();
    $clinic = Clinic::factory()->create();

    $membership = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    $branch = Branch::factory()->create(['clinic_id' => $clinic->id, 'status' => BranchStatus::Active]);

    $response = $this->actingAs($user)
        ->withSession([ClinicContext::SESSION_BRANCH_ID => $branch->id])
        ->get('/_test/clinic-context-status');

    $response->assertJson([
        'branch_id' => $branch->id,
    ]);
});

test('changing clinic membership clears an invalid previous branch', function () {
    $context = new ClinicContext;

    $user = User::factory()->create();
    $context->setUser($user);

    $clinicA = Clinic::factory()->create();
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);
    $branchA = Branch::factory()->create(['clinic_id' => $clinicA->id, 'status' => BranchStatus::Active]);

    $clinicB = Clinic::factory()->create();
    $membershipB = ClinicMembership::factory()->create([
        'clinic_id' => $clinicB->id,
        'user_id' => $user->id,
        'has_all_branches_access' => true,
    ]);

    // Set first membership and branch
    $context->setMembership($membershipA);
    $context->setBranch($branchA);

    expect($context->branch()->id)->toBe($branchA->id);

    // Switch to membershipB, which doesn't have access to branchA since it belongs to clinicA
    $context->setMembership($membershipB);

    expect($context->branch())->toBeNull();
});

test('ClinicContext rejects inconsistent membership/user combinations', function () {
    $context = new ClinicContext;
    $user = User::factory()->create();
    $context->setUser($user);

    $otherUser = User::factory()->create();
    $clinic = Clinic::factory()->create();
    $membershipOfOther = ClinicMembership::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $otherUser->id,
    ]);

    $this->expectException(InvalidArgumentException::class);
    $context->setMembership($membershipOfOther);
});

test('ClinicContext rejects cross-clinic branches', function () {
    $context = new ClinicContext;
    $user = User::factory()->create();
    $context->setUser($user);

    $clinicA = Clinic::factory()->create();
    $membershipA = ClinicMembership::factory()->create([
        'clinic_id' => $clinicA->id,
        'user_id' => $user->id,
    ]);

    $clinicB = Clinic::factory()->create();
    $branchB = Branch::factory()->create(['clinic_id' => $clinicB->id, 'status' => BranchStatus::Active]);

    $context->setMembership($membershipA);

    $this->expectException(InvalidArgumentException::class);
    $context->setBranch($branchB);
});

test('context state does not leak between separate request/application scopes', function () {
    // We resolve the context from container twice. Since it is bound using scoped(),
    // they must be the same instance during the same container lifespan/request,
    // but a fresh container resolution in a separate application instance gets a new context.

    $context1 = app(ClinicContext::class);
    $context2 = app(ClinicContext::class);

    expect($context1)->toBe($context2);

    // If we bootstrap a fresh application instance, it should get a clean context
    $freshApp = $this->createApplication();
    $freshContext = $freshApp->make(ClinicContext::class);

    expect($freshContext)->not->toBe($context1)
        ->and($freshContext->clinic())->toBeNull();
});
