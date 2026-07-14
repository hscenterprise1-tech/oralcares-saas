<?php

use App\BranchStatus;
use App\ClinicMembershipStatus;
use App\ClinicStatus;
use App\Data\ClinicOnboardingData;
use App\Data\ClinicOnboardingResult;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\OnboardClinicService;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('a valid clinic_user can onboard a clinic successfully', function () {
    $user = User::factory()->create(); // default type is clinic_user
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'Happy Teeth Clinic',
        clinicSlug: 'happy-teeth',
        owner: $user,
        primaryBranchName: 'Happy Teeth Main Branch'
    );

    $result = $service->execute($data);

    expect($result)->toBeInstanceOf(ClinicOnboardingResult::class)
        ->and($result->clinic->name)->toBe('Happy Teeth Clinic')
        ->and($result->clinic->slug)->toBe('happy-teeth')
        ->and($result->clinic->status)->toBe(ClinicStatus::Active)

        // Primary branch
        ->and($result->primaryBranch->name)->toBe('Happy Teeth Main Branch')
        ->and($result->primaryBranch->is_main)->toBeTrue()
        ->and($result->primaryBranch->status)->toBe(BranchStatus::Active)
        ->and($result->primaryBranch->clinic_id)->toBe($result->clinic->id)

        // Owner membership
        ->and($result->ownerMembership->user_id)->toBe($user->id)
        ->and($result->ownerMembership->clinic_id)->toBe($result->clinic->id)
        ->and($result->ownerMembership->is_primary_owner)->toBeTrue()
        ->and($result->ownerMembership->has_all_branches_access)->toBeTrue()
        ->and($result->ownerMembership->status)->toBe(ClinicMembershipStatus::Active)

        // Cloned roles
        ->and($result->clonedRoles)->toHaveCount(4);
});

test('onboarding result exposes clinic, primary branch, owner membership, and cloned roles', function () {
    $user = User::factory()->create();
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'Bright Smile Dental',
        clinicSlug: 'bright-smile',
        owner: $user,
        primaryBranchName: 'Bright Smile Main'
    );

    $result = $service->execute($data);

    expect($result->clinic)->toBeInstanceOf(Clinic::class)
        ->and($result->primaryBranch)->toBeInstanceOf(Branch::class)
        ->and($result->ownerMembership)->toBeInstanceOf(ClinicMembership::class)
        ->and($result->clonedRoles->first())->toBeInstanceOf(Role::class);
});

test('primary owner is not redundantly assigned any roles during onboarding', function () {
    $user = User::factory()->create();
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'Grace Dental',
        clinicSlug: 'grace-dental',
        owner: $user,
        primaryBranchName: 'Grace Main'
    );

    $result = $service->execute($data);

    // Primary owner is authorized via dynamic is_primary_owner flag override,
    // so no roles are directly assigned in clinic_membership_roles pivot
    expect($result->ownerMembership->roleAssignments)->toBeEmpty();
});

test('invalid master_admin owner is rejected', function () {
    $user = User::factory()->masterAdmin()->create();
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'Fail Clinic',
        clinicSlug: 'fail-clinic',
        owner: $user,
        primaryBranchName: 'Fail Branch'
    );

    $this->expectException(InvalidArgumentException::class);
    $service->execute($data);
});

test('invalid company_user owner is rejected', function () {
    $user = User::factory()->companyUser()->create();
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'Fail Clinic 2',
        clinicSlug: 'fail-clinic-2',
        owner: $user,
        primaryBranchName: 'Fail Branch 2'
    );

    $this->expectException(InvalidArgumentException::class);
    $service->execute($data);
});

test('invalid owner onboarding leaves no clinic behind (atomic rollback)', function () {
    $user = User::factory()->masterAdmin()->create();
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'Atomic Rollback Clinic',
        clinicSlug: 'atomic-rollback',
        owner: $user,
        primaryBranchName: 'Atomic Branch'
    );

    try {
        $service->execute($data);
    } catch (InvalidArgumentException $e) {
        // expected
    }

    expect(Clinic::where('slug', 'atomic-rollback')->exists())->toBeFalse()
        ->and(Branch::where('name', 'Atomic Branch')->exists())->toBeFalse();
});

test('missing active system role templates causes complete rollback', function () {
    // Delete all system roles
    Role::whereNull('clinic_id')->delete();

    $user = User::factory()->create();
    $service = app(OnboardClinicService::class);

    $data = new ClinicOnboardingData(
        clinicName: 'No Template Clinic',
        clinicSlug: 'no-template',
        owner: $user,
        primaryBranchName: 'No Template Branch'
    );

    $this->expectException(RuntimeException::class);
    try {
        $service->execute($data);
    } finally {
        expect(Clinic::where('slug', 'no-template')->exists())->toBeFalse()
            ->and(Branch::where('name', 'No Template Branch')->exists())->toBeFalse()
            ->and(ClinicMembership::where('user_id', $user->id)->exists())->toBeFalse();
    }
});

test('failed onboarding does not alter existing clinics', function () {
    $user1 = User::factory()->create();
    $service = app(OnboardClinicService::class);

    // Onboard a healthy clinic first
    $healthyResult = $service->execute(new ClinicOnboardingData(
        clinicName: 'Healthy Clinic',
        clinicSlug: 'healthy-clinic',
        owner: $user1,
        primaryBranchName: 'Healthy Branch'
    ));

    $user2 = User::factory()->masterAdmin()->create(); // invalid user type to trigger failure
    $data = new ClinicOnboardingData(
        clinicName: 'Failing Clinic',
        clinicSlug: 'failing-clinic',
        owner: $user2,
        primaryBranchName: 'Failing Branch'
    );

    try {
        $service->execute($data);
    } catch (InvalidArgumentException $e) {
        // expected
    }

    // Verify healthy clinic is completely unchanged
    expect(Clinic::where('slug', 'healthy-clinic')->exists())->toBeTrue()
        ->and(Clinic::where('slug', 'failing-clinic')->exists())->toBeFalse()
        ->and(Branch::where('name', 'Healthy Branch')->exists())->toBeTrue()
        ->and(Branch::where('name', 'Failing Branch')->exists())->toBeFalse();
});

test('repeated onboarding with same slug triggers early validation failure', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $service = app(OnboardClinicService::class);

    $data1 = new ClinicOnboardingData(
        clinicName: 'Unique Clinic',
        clinicSlug: 'unique-slug',
        owner: $user1,
        primaryBranchName: 'Unique Branch 1'
    );

    $service->execute($data1);

    // Repeated onboarding with same slug
    $data2 = new ClinicOnboardingData(
        clinicName: 'Duplicate Clinic',
        clinicSlug: 'unique-slug',
        owner: $user2,
        primaryBranchName: 'Unique Branch 2'
    );

    $this->expectException(InvalidArgumentException::class);
    $service->execute($data2);
});

test('a single-branch clinic auto-resolves branch context correctly in HTTP request lifecycle', function () {
    $user = User::factory()->create();
    $service = app(OnboardClinicService::class);

    $result = $service->execute(new ClinicOnboardingData(
        clinicName: 'Single Branch Auto Clinic',
        clinicSlug: 'single-branch-auto',
        owner: $user,
        primaryBranchName: 'Auto Primary Branch'
    ));

    // Register a test endpoint that outputs the resolved clinic and branch contexts
    Route::middleware('web')->get('/_test/onboarding-context-status', function (ClinicContext $context) {
        return response()->json([
            'membership_id' => $context->membership()?->id,
            'clinic_id' => $context->clinic()?->id,
            'branch_id' => $context->branch()?->id,
        ]);
    });

    $response = $this->actingAs($user)->get('/_test/onboarding-context-status');

    // Should auto-resolve to the clinic and its single active branch
    $response->assertJson([
        'membership_id' => $result->ownerMembership->id,
        'clinic_id' => $result->clinic->id,
        'branch_id' => $result->primaryBranch->id,
    ]);
});
