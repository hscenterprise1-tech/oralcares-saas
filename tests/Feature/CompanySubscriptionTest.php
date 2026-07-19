<?php

use App\Models\Clinic;
use App\Models\CompanyOpportunity;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Services\EntitlementService;
use App\Services\FeatureCatalogService;
use App\Services\PlanService;
use App\Services\SubscriptionService;
use App\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('features can be registered and plans can map features', function () {
    $featureCatalog = app(FeatureCatalogService::class);
    $featureCatalog->registerFeature('Patients Management', 'patients');
    $featureCatalog->registerFeature('Billing & Invoicing', 'billing');

    $planService = app(PlanService::class);
    $plan = $planService->createPlan([
        'name' => 'Standard Plan',
        'key' => 'standard',
        'price' => 199.00,
        'billing_period' => 'monthly',
        'max_users' => 10,
        'max_doctors' => 5,
        'max_branches' => 2,
        'storage_limit_gb' => 20,
    ], ['patients', 'billing']);

    expect($plan)->not->toBeNull()
        ->and($plan->name)->toBe('Standard Plan')
        ->and($plan->key)->toBe('standard')
        ->and($plan->features)->toHaveCount(2)
        ->and($plan->features->pluck('key'))->toContain('patients', 'billing');
});

test('subscription inherits limits from plan and transitions status correctly', function () {
    $plan = CompanyPlan::factory()->create([
        'max_users' => 25,
        'max_doctors' => 10,
        'max_branches' => 5,
        'storage_limit_gb' => 50,
    ]);

    $clinic = Clinic::factory()->create();
    $opportunity = CompanyOpportunity::factory()->create();

    $subService = app(SubscriptionService::class);
    $sub = $subService->createSubscription([
        'clinic_id' => $clinic->id,
        'opportunity_id' => $opportunity->id,
        'status' => SubscriptionStatus::Draft,
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
    ], $plan);

    expect($sub->company_plan_id)->toBe($plan->id)
        ->and($sub->max_users)->toBe(25)
        ->and($sub->max_doctors)->toBe(10)
        ->and($sub->max_branches)->toBe(5)
        ->and($sub->storage_limit_gb)->toBe(50)
        ->and($sub->status)->toBe(SubscriptionStatus::Draft);

    $subService->transitionStatus($sub, SubscriptionStatus::Active);
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Active);

    $subService->transitionStatus($sub, SubscriptionStatus::Cancelled);
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($sub->fresh()->canceled_at)->not->toBeNull();
});

test('entitlements check falls back to plan and allows custom overrides', function () {
    $featureCatalog = app(FeatureCatalogService::class);
    $fPatients = $featureCatalog->registerFeature('Patients', 'patients');
    $fBilling = $featureCatalog->registerFeature('Billing', 'billing');
    $fWhatsApp = $featureCatalog->registerFeature('WhatsApp', 'whatsapp');

    $planService = app(PlanService::class);
    $plan = $planService->createPlan([
        'name' => 'Starter Plan',
        'key' => 'starter',
    ], ['patients', 'billing']);

    $subService = app(SubscriptionService::class);
    $sub = $subService->createSubscription([
        'status' => SubscriptionStatus::Active,
    ], $plan);

    $entitlementService = app(EntitlementService::class);

    expect($entitlementService->hasFeature($sub, 'patients'))->toBeTrue();

    expect($entitlementService->hasFeature($sub, 'whatsapp'))->toBeFalse();

    $entitlementService->setEntitlementOverride($sub, 'whatsapp', true, 100);
    expect($entitlementService->hasFeature($sub, 'whatsapp'))->toBeTrue();

    $override = $sub->entitlements()->where('company_feature_id', $fWhatsApp->id)->first();
    expect($override->usage_limit)->toBe(100)
        ->and($override->is_unlimited)->toBeFalse();

    $entitlementService->setEntitlementOverride($sub, 'patients', false);
    expect($entitlementService->hasFeature($sub, 'patients'))->toBeFalse();
});

test('addons can be purchased on subscription', function () {
    $sub = CompanySubscription::factory()->create();

    $subService = app(SubscriptionService::class);
    $addon = $subService->purchaseAddon($sub, 'extra_storage', 2, 50.00);

    expect($addon)->not->toBeNull()
        ->and($addon->addon_type)->toBe('extra_storage')
        ->and($addon->quantity)->toBe(2)
        ->and((float) $addon->price)->toBe(50.00)
        ->and($addon->is_active)->toBeTrue()
        ->and($addon->subscription->id)->toBe($sub->id);
});
