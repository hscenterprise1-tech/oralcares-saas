<?php

use App\Models\CompanyPlan;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
    actingAs($this->admin);

    $this->lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
});

it('creates a trial that defaults its duration and doctor cap from the selected package', function () {
    $plan = CompanyPlan::factory()->create([
        'max_doctors' => 4,
        'is_trial_available' => true,
        'trial_duration_days' => 5,
    ]);

    post(route('company.crm.leads.create-trial', $this->lead), [
        'company_plan_id' => $plan->id,
    ])->assertSessionHasNoErrors();

    $trial = Trial::where('lead_id', $this->lead->id)->firstOrFail();

    expect($trial->company_plan_id)->toBe($plan->id);
    expect($trial->duration_days)->toBe(5);
    expect($trial->max_doctors)->toBe(4);
    expect($trial->plan)->toBe($plan->name);
});

it('allows an explicit duration to override the package default', function () {
    $plan = CompanyPlan::factory()->create(['trial_duration_days' => 15]);

    post(route('company.crm.leads.create-trial', $this->lead), [
        'company_plan_id' => $plan->id,
        'duration_days' => 21,
    ])->assertSessionHasNoErrors();

    expect(Trial::where('lead_id', $this->lead->id)->first()->duration_days)->toBe(21);
});

it('is no longer locked to a hardcoded 7-or-15-day trial duration', function () {
    post(route('company.crm.leads.create-trial', $this->lead), [
        'duration_days' => 20,
    ])->assertSessionHasNoErrors();

    expect(Trial::where('lead_id', $this->lead->id)->first()->duration_days)->toBe(20);
});

it('extend endpoint accepts a flexible day count outside the old 7/15 restriction', function () {
    $trial = app(LeadWorkspaceService::class)->createTrial($this->lead, ['duration_days' => 10], $this->admin);

    post(route('company.crm.trials.extend', $trial), [
        'additional_days' => 12,
        'extension_reason' => 'Client needs more time to onboard staff.',
    ])->assertSessionHasNoErrors();

    expect($trial->fresh()->duration_days)->toBe(22);
});
