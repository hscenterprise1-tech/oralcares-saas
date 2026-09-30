<?php

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\SubscriptionEvent;
use App\Models\Trial;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Phase 1 — the shape a real trial needs, before anything uses it.
 *
 * Nothing here changes behaviour. It pins the columns, the enum and the
 * relationships that the provisioning, grace and telemetry phases are built
 * on, so a later change that quietly drops one of them fails here rather than
 * in production.
 */
it('links a trial to the tenant it will provision', function () {
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create(['organization_id' => $org->id]);

    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'customer_organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'provisioned_at' => now(),
    ]);

    expect($trial->customerOrganization->is($org))->toBeTrue()
        ->and($trial->clinic->is($clinic))->toBeTrue()
        ->and($trial->isProvisioned())->toBeTrue();
});

it('treats a trial with no tenant as not yet provisioned', function () {
    $trial = Trial::factory()->create(['lead_id' => Lead::factory()->create()->id]);

    expect($trial->isProvisioned())->toBeFalse()
        ->and($trial->customer_organization_id)->toBeNull()
        ->and($trial->clinic_id)->toBeNull();
});

it('records why provisioning failed rather than stalling silently', function () {
    /*
     * A trial stuck in `preparing` with no reason is the failure mode this
     * column exists to remove.
     */
    $trial = Trial::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'status' => TrialStatus::Preparing,
        'provisioning_error' => 'Clinic slug collision after 10 attempts.',
    ]);

    expect($trial->fresh()->provisioning_error)->toContain('slug collision')
        ->and($trial->isProvisioned())->toBeFalse();
});

it('carries the grace boundary on both the trial and the subscription', function () {
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);

    $trial = Trial::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'end_date' => now()->addDays(14),
        'grace_ends_at' => now()->addDays(21),
    ]);

    $subscription = CompanySubscription::create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'status' => 'active',
        'starts_at' => now(),
        'trial_ends_at' => now()->addDays(14),
        'grace_ends_at' => now()->addDays(21),
        'ends_at' => now()->addDays(21),
    ]);

    expect($trial->fresh()->grace_ends_at)->not->toBeNull()
        ->and($subscription->fresh()->grace_ends_at->toDateString())
        ->toBe(now()->addDays(21)->toDateString());
});

it('knows which statuses still grant the tenant access', function () {
    /*
     * Grace grants access — that is the whole point of it. This list was
     * spelled three different ways across the metrics service, the scope
     * helpers and the workspace payload; now there is one.
     */
    expect(TrialStatus::Active->grantsAccess())->toBeTrue()
        ->and(TrialStatus::EndingSoon->grantsAccess())->toBeTrue()
        ->and(TrialStatus::Extended->grantsAccess())->toBeTrue()
        ->and(TrialStatus::InGrace->grantsAccess())->toBeTrue()
        ->and(TrialStatus::Expired->grantsAccess())->toBeFalse()
        ->and(TrialStatus::Preparing->grantsAccess())->toBeFalse()
        ->and(TrialStatus::Cancelled->grantsAccess())->toBeFalse()
        ->and(TrialStatus::Completed->grantsAccess())->toBeFalse();
});

it('drops the status nothing could reach and names the one that was misleading', function () {
    $values = array_map(fn (TrialStatus $s) => $s->value, TrialStatus::cases());

    /* `requested` was in the enum and assigned by nothing. */
    expect($values)->not->toContain('requested')
        /*
         * `converted` was set when a customer asked for a price, which is not
         * a sale — conversion happens at Won, gated on an accepted agreement
         * and a settled payment.
         */
        ->and($values)->not->toContain('converted')
        ->and($values)->toContain('quotation_requested')
        ->and($values)->toContain('in_grace');

    expect(TrialStatus::QuotationRequested->label())->toBe('Quotation Requested')
        ->and(TrialStatus::InGrace->label())->toBe('In Grace Period');
});

it('logs what changed about a subscription, and against which deal', function () {
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create(['organization_id' => $org->id]);
    $starter = CompanyPlan::factory()->create(['name' => 'Starter']);
    $growth = CompanyPlan::factory()->create(['name' => 'Growth']);

    $subscription = CompanySubscription::create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'company_plan_id' => $starter->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addDays(21),
    ]);

    SubscriptionEvent::create([
        'company_subscription_id' => $subscription->id,
        'organization_id' => $org->id,
        'event' => SubscriptionEvent::TRIAL_STARTED,
        'to_plan_id' => $starter->id,
        'lead_id' => $lead->id,
        'changes' => ['trial_ends_at' => now()->addDays(14)->toDateString()],
    ]);

    SubscriptionEvent::create([
        'company_subscription_id' => $subscription->id,
        'organization_id' => $org->id,
        'event' => SubscriptionEvent::CONVERTED_TO_PAID,
        'from_plan_id' => $starter->id,
        'to_plan_id' => $growth->id,
        'lead_id' => $lead->id,
        'reason' => 'Deal won',
    ]);

    $events = $subscription->fresh()->events;

    /* Newest first, so the current state is the top of the list. */
    expect($events)->toHaveCount(2)
        ->and($events->first()->event)->toBe(SubscriptionEvent::CONVERTED_TO_PAID)
        ->and($events->first()->fromPlan->name)->toBe('Starter')
        ->and($events->first()->toPlan->name)->toBe('Growth')
        ->and($events->last()->changes)->toHaveKey('trial_ends_at');
});

it('leaves the plan the only place limits are defined', function () {
    /*
     * Phase 2 makes provisioning honour these. Pinned now so a change to the
     * plan table cannot silently remove what the trial is meant to grant.
     */
    $plan = CompanyPlan::factory()->create([
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
    ]);

    expect(Schema::hasColumn('company_plans', 'storage_limit_gb'))->toBeTrue()
        ->and($plan->max_users)->toBe(20)
        ->and($plan->max_branches)->toBe(3);

    /*
     * And the gap Phase 2 has to close: there is no module or WhatsApp-quota
     * column, so every plan currently grants the same five modules and no
     * allowance can be reported.
     */
    expect(Schema::hasColumn('company_plans', 'modules'))->toBeFalse()
        ->and(Schema::hasColumn('company_plans', 'whatsapp_quota'))->toBeFalse();
});
