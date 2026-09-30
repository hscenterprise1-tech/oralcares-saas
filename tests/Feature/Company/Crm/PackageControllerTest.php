<?php

use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);
});

it('displays the packages index', function () {
    CompanyPlan::factory()->create(['name' => 'Starter']);

    get(route('company.admin.packages.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Admin/Packages/Index')
            ->has('packages', 1)
        );
});

it('creates a trial-eligible package', function () {
    post(route('company.admin.packages.store'), [
        'name' => 'Growth',
        'price' => 12999,
        'billing_period' => 'monthly',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'is_active' => true,
        'is_trial_available' => true,
        'trial_duration_days' => 15,
    ])->assertRedirect();

    $plan = CompanyPlan::where('name', 'Growth')->firstOrFail();

    expect($plan->max_doctors)->toBe(5);
    expect($plan->is_trial_available)->toBeTrue();
    expect($plan->trial_duration_days)->toBe(15);
    expect($plan->key)->not->toBeEmpty();
});

it('updates a package', function () {
    $plan = CompanyPlan::factory()->create(['name' => 'Old Name', 'max_doctors' => 2]);

    put(route('company.admin.packages.update', $plan), [
        'name' => 'New Name',
        'price' => 5000,
        'billing_period' => 'monthly',
        'max_users' => 10,
        'max_doctors' => 8,
        'max_branches' => 2,
        'storage_limit_gb' => 20,
        'is_active' => true,
        'is_trial_available' => false,
    ])->assertRedirect();

    $plan->refresh();

    expect($plan->name)->toBe('New Name');
    expect($plan->max_doctors)->toBe(8);
    expect($plan->is_trial_available)->toBeFalse();
});

it('deletes a package with no active subscriptions', function () {
    $plan = CompanyPlan::factory()->create();

    delete(route('company.admin.packages.destroy', $plan))->assertRedirect();

    expect(CompanyPlan::find($plan->id))->toBeNull();
});

it('refuses to delete a package that has active subscriptions', function () {
    $plan = CompanyPlan::factory()->create();
    CompanySubscription::create([
        'organization_id' => $this->organization->id,
        'company_plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now(),
    ]);

    delete(route('company.admin.packages.destroy', $plan))->assertRedirect();

    expect(CompanyPlan::find($plan->id))->not->toBeNull();
});
