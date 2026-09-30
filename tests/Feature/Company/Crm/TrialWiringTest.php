<?php

use App\Http\Controllers\Company\Crm\TrialController;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\ProvisioningAudit;
use App\Models\Trial;
use App\Models\User;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Phase 3 — the CRM actions actually do the thing they claim to.
 *
 * Creating a trial used to write a row and stop, leaving a rep to press
 * "Activate" on an environment that did not exist. These tests are about the
 * gap between what a button said and what it did.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->rep = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->plan = CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'company_name' => 'Bhatia Dental Care',
        'contact_name' => 'Nikhil Bhatia',
        'email' => 'nikhil@bhatia.test',
        'phone' => '919600000000',
        'status' => LeadStatus::DemoCompleted->value,
        'assigned_user_id' => $this->rep->id,
    ]);
});

it('provisions a real environment when a trial is created from a deal', function () {
    $response = $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['company_plan_id' => $this->plan->id, 'duration_days' => 14],
    );

    $response->assertSessionHasNoErrors();

    $trial = Trial::where('lead_id', $this->lead->id)->firstOrFail();

    expect($trial->isProvisioned())->toBeTrue()
        ->and($trial->status)->toBe(TrialStatus::Active)
        ->and(Clinic::find($trial->clinic_id))->not->toBeNull()
        ->and(CompanySubscription::where('organization_id', $trial->customer_organization_id)->exists())->toBeTrue()
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::TrialActive);
});

it('leaves a failed provisioning in a visible preparing state the rep can retry', function () {
    /*
     * The trial row survives on purpose. Rejecting the create would throw away
     * everything the rep entered and leave nothing to retry from; instead the
     * trial sits in `preparing` with the reason on the record and a warning on
     * the response.
     */
    $response = $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['plan' => 'Something Unrecognised', 'duration_days' => 14],
    );

    $response->assertSessionHasNoErrors();
    expect(session('warning'))->toContain('No plan selected');

    $trial = Trial::where('lead_id', $this->lead->id)->firstOrFail();

    expect($trial->status)->toBe(TrialStatus::Preparing)
        ->and($trial->provisioning_error)->toContain('No plan selected')
        ->and($trial->clinic_id)->toBeNull()
        ->and(ProvisioningAudit::where('trial_id', $trial->id)->value('status'))->toBe('failed');
});

it('provisions on retry once the reason for the failure is fixed', function () {
    $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['plan' => 'Something Unrecognised'],
    );

    $trial = Trial::where('lead_id', $this->lead->id)->firstOrFail();
    $trial->forceFill(['company_plan_id' => $this->plan->id])->save();

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.retry', $trial))
        ->assertSessionHasNoErrors();

    expect($trial->fresh()->isProvisioned())->toBeTrue()
        ->and($trial->fresh()->provisioning_error)->toBeNull()
        ->and($trial->fresh()->status)->toBe(TrialStatus::Active);
});

it('refuses to resend a handover for a trial with no environment', function () {
    $trial = Trial::factory()->create([
        'lead_id' => $this->lead->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.resend-handover', $trial))
        ->assertSessionHasErrors('handover');
});

it('has no manual activate action any more', function () {
    /*
     * The old button set the status to Active and nothing else, so it could
     * declare a trial live with nothing behind it. Going live is now a
     * consequence of provisioning, not a thing a person asserts.
     */
    $names = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();

    expect($names)->not->toContain('company.crm.trials.activate')
        ->and($names)->toContain('company.crm.trials.retry')
        ->and($names)->toContain('company.crm.trials.resend-handover')
        ->and($names)->toContain('company.crm.trials.cancel')
        ->and(method_exists(TrialController::class, 'activate'))->toBeFalse();
});

it('extends the access window through the extend action, not just the record', function () {
    $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['company_plan_id' => $this->plan->id],
    );

    $trial = Trial::where('lead_id', $this->lead->id)->firstOrFail();
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->firstOrFail();
    $originalEnd = $subscription->trial_ends_at->copy();

    $this->actingAs($this->rep)->post(route('company.crm.trials.extend', $trial), [
        'additional_days' => 7,
        'extension_reason' => 'Client is still evaluating the EMR module.',
    ])->assertSessionHasNoErrors();

    expect($subscription->fresh()->trial_ends_at->toDateString())
        ->toBe($originalEnd->addDays(7)->toDateString());
});

it('cancels a trial and revokes access through the cancel action', function () {
    $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['company_plan_id' => $this->plan->id],
    );

    $trial = Trial::where('lead_id', $this->lead->id)->firstOrFail();

    $this->actingAs($this->rep)->post(route('company.crm.trials.cancel', $trial), [
        'reason' => 'Prospect asked to be removed.',
    ])->assertSessionHasNoErrors();

    expect($trial->fresh()->status)->toBe(TrialStatus::Cancelled)
        ->and($trial->fresh()->cancellation_reason)->toBe('Prospect asked to be removed.');
});

it('requires a reason to cancel', function () {
    $trial = Trial::factory()->create([
        'lead_id' => $this->lead->id,
        'status' => TrialStatus::Active,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
    ]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.trials.cancel', $trial), [])
        ->assertSessionHasErrors('reason');
});

it('does not create a second trial for a deal that already has a live one', function () {
    $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['company_plan_id' => $this->plan->id],
    );

    $this->actingAs($this->rep)->post(
        route('company.crm.leads.create-trial', $this->lead),
        ['company_plan_id' => $this->plan->id],
    );

    expect(Trial::where('lead_id', $this->lead->id)->count())->toBe(1)
        ->and(Clinic::count())->toBe(1);
});
