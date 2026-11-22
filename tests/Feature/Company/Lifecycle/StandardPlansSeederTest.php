<?php

use App\Models\CompanyPlan;
use App\Services\Provisioning\EntitlementResolver;
use Database\Seeders\CompanyPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds Dental Standard with the approved price, seats and modules', function () {
    $this->seed(CompanyPlanSeeder::class);

    $plan = CompanyPlan::where('key', 'dental-standard')->firstOrFail();

    expect((float) $plan->price)->toBe(2500.0)
        ->and($plan->billing_period)->toBe('monthly')
        ->and($plan->max_doctors)->toBe(2)
        ->and($plan->max_staff)->toBe(3)
        ->and($plan->max_users)->toBe(5)
        ->and($plan->max_branches)->toBe(1);

    $modules = app(EntitlementResolver::class)->modulesFor($plan);

    expect($modules)->toContain('dental')->not->toContain('physiotherapy');
});

it('seeds Physio Standard with the approved price, seats and modules', function () {
    $this->seed(CompanyPlanSeeder::class);

    $plan = CompanyPlan::where('key', 'physio-standard')->firstOrFail();

    expect((float) $plan->price)->toBe(1500.0)
        ->and($plan->max_doctors)->toBe(5)
        ->and($plan->max_staff)->toBe(2)
        ->and($plan->max_users)->toBe(7)
        ->and($plan->max_branches)->toBe(1);

    $modules = app(EntitlementResolver::class)->modulesFor($plan);

    expect($modules)->toContain('physiotherapy')->not->toContain('dental');
});

it('does not overwrite a plan edited after the first seed', function () {
    $this->seed(CompanyPlanSeeder::class);

    CompanyPlan::where('key', 'dental-standard')->update(['price' => 2999]);
    $featureCount = CompanyPlan::where('key', 'dental-standard')->firstOrFail()->features()->count();

    $this->seed(CompanyPlanSeeder::class);

    $plan = CompanyPlan::where('key', 'dental-standard')->firstOrFail();

    expect((float) $plan->price)->toBe(2999.0)
        ->and($plan->features()->count())->toBe($featureCount)
        ->and(CompanyPlan::where('key', 'dental-standard')->count())->toBe(1);
});
