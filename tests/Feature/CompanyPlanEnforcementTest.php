<?php

use App\Events\FeatureDisabled;
use App\Events\LimitReached;
use App\Exceptions\FeatureDisabledException;
use App\Exceptions\ResourceLimitExceededException;
use App\Exceptions\SubscriptionExpiredException;
use App\Exceptions\SubscriptionSuspendedException;
use App\Http\Middleware\RequireFeatureEntitlement;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\FeatureGateService;
use App\Services\ResourceLimitService;
use App\Services\SubscriptionService;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\SystemRoleTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SystemRoleTemplateSeeder::class);
});

test('expired subscription throws SubscriptionExpiredException', function () {
    $clinic = Clinic::factory()->create();
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Expired,
    ]);

    $clinicContext = mock(ClinicContext::class);
    $clinicContext->shouldReceive('getClinic')->andReturn($clinic);

    $middleware = new RequireFeatureEntitlement($clinicContext, app(FeatureGateService::class), app(ResourceLimitService::class));

    $request = Request::create('/patients', 'GET');

    $this->expectException(SubscriptionExpiredException::class);
    $middleware->handle($request, fn () => 'next', 'patients');
});

test('suspended subscription throws SubscriptionSuspendedException', function () {
    $clinic = Clinic::factory()->create();
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Suspended,
    ]);

    $clinicContext = mock(ClinicContext::class);
    $clinicContext->shouldReceive('getClinic')->andReturn($clinic);

    $middleware = new RequireFeatureEntitlement($clinicContext, app(FeatureGateService::class), app(ResourceLimitService::class));

    $request = Request::create('/patients', 'GET');

    $this->expectException(SubscriptionSuspendedException::class);
    $middleware->handle($request, fn () => 'next', 'patients');
});

test('feature disabled throws FeatureDisabledException and dispatches event', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $plan = CompanyPlan::factory()->create();
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
    ]);

    $featureGate = app(FeatureGateService::class);

    $this->expectException(FeatureDisabledException::class);
    try {
        $featureGate->requiresFeature($clinic, 'whatsapp');
    } finally {
        Event::assertDispatched(FeatureDisabled::class, function ($event) use ($clinic) {
            return $event->clinic->id === $clinic->id && $event->featureKey === 'whatsapp';
        });
    }
});

test('enforce user limit blocks creation and fires event', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $plan = CompanyPlan::factory()->create(['max_users' => 1]);
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'max_users' => 1,
    ]);

    // Create 1 user membership to hit the limit
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    ClinicMembership::create(['clinic_id' => $clinic->id, 'user_id' => $user->id]);

    $limitService = app(ResourceLimitService::class);

    $this->expectException(ResourceLimitExceededException::class);
    try {
        $limitService->enforceUserLimit($clinic);
    } finally {
        Event::assertDispatched(LimitReached::class, function ($event) use ($clinic) {
            return $event->clinic->id === $clinic->id && $event->resourceType === 'users';
        });
    }
});

test('enforce doctor limit blocks creation and fires event', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $plan = CompanyPlan::factory()->create(['max_doctors' => 1]);
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'max_doctors' => 1,
    ]);

    // Create 1 doctor membership to hit the limit
    $user = User::factory()->create(['user_type' => UserType::ClinicUser]);
    $membership = ClinicMembership::create(['clinic_id' => $clinic->id, 'user_id' => $user->id]);

    // Assign doctor role
    $role = Role::create([
        'clinic_id' => $clinic->id,
        'key' => 'doctor',
        'name' => 'Doctor',
        'is_system' => false,
        'is_active' => true,
    ]);
    $membership->roleAssignments()->create(['role_id' => $role->id]);

    $limitService = app(ResourceLimitService::class);

    $this->expectException(ResourceLimitExceededException::class);
    try {
        $limitService->enforceDoctorLimit($clinic);
    } finally {
        Event::assertDispatched(LimitReached::class, function ($event) use ($clinic) {
            return $event->clinic->id === $clinic->id && $event->resourceType === 'doctors';
        });
    }
});

test('enforce branch limit blocks creation and fires event', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $plan = CompanyPlan::factory()->create(['max_branches' => 1]);
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'max_branches' => 1,
    ]);

    // Create 1 branch to hit the limit
    Branch::factory()->create(['clinic_id' => $clinic->id]);

    $limitService = app(ResourceLimitService::class);

    $this->expectException(ResourceLimitExceededException::class);
    try {
        $limitService->enforceBranchLimit($clinic);
    } finally {
        Event::assertDispatched(LimitReached::class, function ($event) use ($clinic) {
            return $event->clinic->id === $clinic->id && $event->resourceType === 'branches';
        });
    }
});

test('storage limit enforces storage usage block', function () {
    Event::fake();

    $clinic = Clinic::factory()->create();
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Active,
        'storage_limit_gb' => 10,
    ]);

    // Set usage cache
    Cache::put("clinic_storage_usage_{$clinic->id}", 9.5);

    $limitService = app(ResourceLimitService::class);

    // Uploading 1 GB should fail as 9.5 + 1.0 = 10.5 > 10.0
    $this->expectException(ResourceLimitExceededException::class);
    try {
        $limitService->enforceStorageLimit($clinic, 1.0);
    } finally {
        Event::assertDispatched(LimitReached::class);
    }
});

test('addons correctly increment limits allowing creation', function () {
    $clinic = Clinic::factory()->create();
    $sub = CompanySubscription::factory()->create([
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Active,
        'max_branches' => 1,
    ]);

    Branch::factory()->create(['clinic_id' => $clinic->id]);

    $limitService = app(ResourceLimitService::class);

    // Exceeds limit initially
    expect($limitService->canCreateBranch($clinic))->toBeFalse();

    // Purchase extra branch addon
    app(SubscriptionService::class)->purchaseAddon($sub, 'extra_branch', 1, 29.00);

    // Limit increased to 2, so can create branch is now true!
    expect($limitService->canCreateBranch($clinic))->toBeTrue();
});
