<?php

use App\Models\Lead;
use App\Models\Trial;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\CompanyPlan;
use App\Models\Organization;

beforeEach(function () {
    $this->org = Organization::factory()->create();

    // TrialPolicy authorizes on CRM permissions from Slice C onward, so a
    // company user with no grants is correctly denied. This fixture represents
    // a sales employee, so it carries the rep-level permission set.
    $this->companyUser = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]));

    $this->clinicUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->org->id,
    ]);

    $this->plan = CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'trial_duration_days' => 14,
        'storage_limit_gb' => 50,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'trial-lead@example.test',
        'phone' => '919500000000',
    ]);
});

it('allows company user to start trial and logs activity', function () {
    $payload = [
        'lead_id' => $this->lead->id,
        /*
         * A trial runs on a plan now — that is what makes its limits and
         * modules match what is being sold.
         */
        'company_plan_id' => $this->plan->id,
        'start_date' => now()->format('Y-m-d'),
        'end_date' => now()->addDays(14)->format('Y-m-d'),
        'expected_users' => 5,
        'status' => 'active',
        'notes' => 'Evaluating core features',
    ];

    $response = $this->actingAs($this->companyUser)
        ->post(route('company.crm.trials.store'), $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('trials', [
        'lead_id' => $this->lead->id,
        'expected_users' => 5,
        'status' => 'active',
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'lead',
        'subject_id' => $this->lead->id,
        'type' => 'trial',
    ]);
});

it('prevents clinic user from starting trial', function () {
    $payload = [
        'lead_id' => $this->lead->id,
        /*
         * A trial runs on a plan now — that is what makes its limits and
         * modules match what is being sold.
         */
        'company_plan_id' => $this->plan->id,
        'start_date' => now()->format('Y-m-d'),
        'end_date' => now()->addDays(14)->format('Y-m-d'),
    ];

    $response = $this->actingAs($this->clinicUser)
        ->post(route('company.crm.trials.store'), $payload);

    $response->assertForbidden();
});

it('refuses trial analytics posted by hand, and keeps what a human authored', function () {
    $trial = Trial::create([
        'lead_id' => $this->lead->id,
        'start_date' => now()->format('Y-m-d'),
        'end_date' => now()->addDays(14)->format('Y-m-d'),
        'status' => 'active',
        'expected_users' => 5,
    ]);

    $payload = [
        'notes' => 'Practice manager wants a walkthrough.',
        'usage_percent' => 85.50,
        'users_created' => 4,
        'doctors_count' => 1,
        'patients_count' => 120,
        'bills_count' => 45,
        'inactive_days' => 2,
    ];

    $response = $this->actingAs($this->companyUser)
        ->put(route('company.crm.trials.update', $trial), $payload);

    $response->assertSessionHasNoErrors();

    $trial->refresh();

    /*
     * Usage is measured into `trial_usage_snapshots` by the nightly job, never
     * posted. What a human genuinely authors — the note — is kept.
     */
    expect($trial->notes)->toBe('Practice manager wants a walkthrough.')
        ->and((float) $trial->usage_percent)->not->toBe(85.50)
        ->and($trial->users_created)->not->toBe(4)
        ->and($trial->inactive_days)->not->toBe(2);
});
