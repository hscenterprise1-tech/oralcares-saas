<?php

use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\CRM\DealPageService;
use App\Services\CRM\DealStageService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 2 — the Deal Detail can move a deal without the Kanban board.
 *
 * The board was the only surface that could change a stage, and it could only
 * express seven of twelve statuses because each column wrote one hard-coded
 * default. It also surfaced precondition failures as a raw
 * InvalidArgumentException in a red banner.
 *
 * DealStageService declares the same rules transitionStage() enforces, so the
 * UI can state a requirement before the user tries, and the endpoint returns a
 * validation error rather than a 500.
 */
function dealAdmin(): User
{
    return User::factory()->create(['user_type' => UserType::MasterAdmin]);
}

function dealAt(LeadStatus $status, array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'status' => $status,
        'company_name' => 'Stage Control Dental',
        'contact_name' => 'Buyer',
        'email' => 'buyer@stagecontrol.test',
    ], $attributes));
}

/**
 * A deal that has actually earned the right to be Won.
 *
 * Won now asks for the same two facts the conversion gate always asked for —
 * an accepted agreement and a settled payment. These tests are about
 * provisioning, not about the gate, so they get a deal that satisfies it.
 */
function closableDeal(): Lead
{
    $lead = dealAt(LeadStatus::PaymentReceived);

    CommercialDocument::create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'document_number' => 'AGR-TEST-'.$lead->id,
        'version' => 1,
        'status' => 'accepted',
        'currency' => 'INR',
        'subtotal' => 100000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'grand_total' => 100000,
    ]);

    Payment::create([
        'lead_id' => $lead->id,
        'amount' => 100000,
        'currency' => 'INR',
        'gateway' => 'manual',
        'status' => 'successful',
    ]);

    return $lead;
}

// ─────────────────────────────────────────────────────────────────────────────
// Every status is reachable
// ─────────────────────────────────────────────────────────────────────────────

it('offers every lifecycle status, not just the board columns', function () {
    $values = collect(app(DealStageService::class)->availableTransitions(dealAt(LeadStatus::Contacted)))
        ->pluck('value');

    /* Every stage still on the road — `assigned` is retired and off the rail. */
    expect($values)->toHaveCount(count(LeadStatus::cases()) - count(LeadStatus::RETIRED))
        ->and($values)->toContain('demo_completed', 'negotiation', 'quotation_approved', 'payment_received')
        /* Retired, and therefore absent from the rail entirely. */
        ->and($values)->not->toContain('assigned');
});

it('marks the current stage and does not offer it as a move', function () {
    $rows = collect(app(DealStageService::class)->availableTransitions(dealAt(LeadStatus::Contacted)))
        ->keyBy('value');

    expect($rows['contacted']['is_current'])->toBeTrue()
        ->and($rows['contacted']['allowed'])->toBeFalse();
});

it('names a contextual next stage that adapts to where the deal is', function () {
    $service = app(DealStageService::class);

    /* New advances straight to Contacted; Assigned is no longer a step. */
    expect($service->nextStage(dealAt(LeadStatus::New))['value'])->toBe('contacted')
        ->and($service->nextStage(dealAt(LeadStatus::Contacted))['value'])->toBe('demo_scheduled')
        ->and($service->nextStage(dealAt(LeadStatus::QuotationSent))['value'])->toBe('quotation_approved')
        ->and($service->nextStage(dealAt(LeadStatus::Won)))->toBeNull()
        ->and($service->nextStage(dealAt(LeadStatus::Lost)))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Preconditions are declared, not thrown
// ─────────────────────────────────────────────────────────────────────────────

it('states what is missing instead of allowing a doomed transition', function () {
    $rows = collect(app(DealStageService::class)->availableTransitions(dealAt(LeadStatus::Contacted)))
        ->keyBy('value');

    expect($rows['quotation_sent']['allowed'])->toBeFalse()
        ->and($rows['quotation_sent']['requirement'])->toContain('quotation')
        ->and($rows['quotation_sent']['action'])->toBe('create_quotation');

    expect($rows['trial_active']['allowed'])->toBeFalse()
        ->and($rows['trial_active']['action'])->toBe('start_trial');

    expect($rows['demo_completed']['allowed'])->toBeFalse()
        ->and($rows['demo_completed']['action'])->toBe('schedule_demo');
});

it('allows the transition once the requirement is satisfied', function () {
    $lead = dealAt(LeadStatus::Contacted);
    CompanyQuotation::factory()->create(['lead_id' => $lead->id]);

    $rows = collect(app(DealStageService::class)->availableTransitions($lead->fresh()))->keyBy('value');

    expect($rows['quotation_sent']['allowed'])->toBeTrue()
        ->and($rows['quotation_sent']['requirement'])->toBeNull();
});

it('returns a validation error rather than a server error for a blocked move', function () {
    $lead = dealAt(LeadStatus::Contacted);

    $this->actingAs(dealAdmin())
        ->from(route('company.crm.leads.show', $lead))
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'quotation_sent'])
        ->assertRedirect()
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status->value)->toBe('contacted');
});

// ─────────────────────────────────────────────────────────────────────────────
// Moving the deal from the detail page
// ─────────────────────────────────────────────────────────────────────────────

it('moves a deal forward from the deal detail endpoint', function () {
    $lead = dealAt(LeadStatus::New);
    /* Contacted asks for evidence that somebody was contacted. */
    logContact($lead);

    $this->actingAs(dealAdmin())
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted'])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('contacted');
});

it('reaches a status the board could never express', function () {
    $lead = dealAt(LeadStatus::QuotationSent);

    $this->actingAs(dealAdmin())
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'negotiation'])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('negotiation');
});

// ─────────────────────────────────────────────────────────────────────────────
// Lost
// ─────────────────────────────────────────────────────────────────────────────

it('requires a reason to mark a deal lost', function () {
    $lead = dealAt(LeadStatus::Negotiation);

    $this->actingAs(dealAdmin())
        ->from(route('company.crm.leads.show', $lead))
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'lost'])
        ->assertSessionHasErrors('reason');

    expect($lead->fresh()->status->value)->toBe('negotiation');
});

it('marks a deal lost with its reason from the deal detail', function () {
    $lead = dealAt(LeadStatus::Negotiation);

    $this->actingAs(dealAdmin())
        ->post(route('company.crm.leads.transition', $lead), [
            'stage' => 'lost',
            'reason' => 'Competitor',
        ])
        ->assertRedirect();

    $lead->refresh();

    expect($lead->status->value)->toBe('lost')
        ->and($lead->lost_reason->value)->toBe('Competitor');
});

// ─────────────────────────────────────────────────────────────────────────────
// Won — must use the Phase 0 provisioning path
// ─────────────────────────────────────────────────────────────────────────────

it('provisions the customer when won from the deal detail', function () {
    $admin = dealAdmin();
    $this->actingAs($admin);
    $lead = closableDeal();

    $this->actingAs($admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'won'])
        ->assertRedirect();

    $lead->refresh();

    expect($lead->status->value)->toBe('won')
        ->and($lead->customer_organization_id)->not->toBeNull()
        ->and(Clinic::where('organization_id', $lead->customer_organization_id)->exists())->toBeTrue();
});

it('surfaces the resulting account on the deal payload after winning', function () {
    $admin = dealAdmin();
    $this->actingAs($admin);
    $lead = closableDeal();

    $this->actingAs($admin)->post(route('company.crm.leads.transition', $lead), ['stage' => 'won']);

    /* The account link is part of the Now zone's payload group. */
    $this->actingAs($admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where(
            'deal.customer_organization_id',
            $lead->fresh()->customer_organization_id
        ));
});

// ─────────────────────────────────────────────────────────────────────────────
// Qualification
// ─────────────────────────────────────────────────────────────────────────────

it('treats a new lead as unqualified', function () {
    $service = app(DealStageService::class);

    expect($service->isQualified(dealAt(LeadStatus::New)))->toBeFalse()
        /* A legacy row still sitting on the retired stage reads as intake. */
        ->and($service->isQualified(dealAt(LeadStatus::Assigned)))->toBeFalse();
});

it('qualifies a lead when it is moved past intake', function () {
    $lead = dealAt(LeadStatus::New);
    logContact($lead);

    $this->actingAs(dealAdmin())
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted']);

    $lead->refresh();

    expect($lead->qualified_at)->not->toBeNull()
        ->and(app(DealStageService::class)->isQualified($lead))->toBeTrue();
});

it('keeps the original qualification moment when the deal moves on', function () {
    $lead = dealAt(LeadStatus::New);
    $admin = dealAdmin();
    logContact($lead);
    scheduleJoinableDemo($lead);

    $this->actingAs($admin)->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted']);
    $first = $lead->fresh()->qualified_at;

    $this->travel(2)->days();
    $this->actingAs($admin)->post(route('company.crm.leads.transition', $lead->fresh()), ['stage' => 'demo_scheduled']);

    expect($lead->fresh()->qualified_at->toDateTimeString())->toBe($first->toDateTimeString());
});

it('records a lead closed out before qualification as disqualified', function () {
    $lead = dealAt(LeadStatus::New);

    $this->actingAs(dealAdmin())
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'lost', 'reason' => 'Unresponsive']);

    $lead->refresh();

    expect(app(DealStageService::class)->isDisqualified($lead))->toBeTrue()
        ->and($lead->qualified_at)->toBeNull()
        ->and($lead->lost_reason->value)->toBe('Unresponsive');
});

it('distinguishes a lost qualified deal from a disqualified one', function () {
    $qualified = dealAt(LeadStatus::Negotiation, ['qualified_at' => now()->subWeek()]);

    $this->actingAs(dealAdmin())
        ->post(route('company.crm.leads.transition', $qualified), ['stage' => 'lost', 'reason' => 'Price']);

    expect(app(DealStageService::class)->isDisqualified($qualified->fresh()))->toBeFalse();
});

/*
 * Reopening is its own endpoint now.
 *
 * This used to move a lost deal back to Contacted through the ordinary stage
 * transition, which is the loophole that let a loss quietly undo itself —
 * taking its reason, its competitor and its place in a loss report with it.
 * `reopen()` asks why, records the answer, and clears the loss.
 */
it('can reopen a lost lead through the reopen endpoint', function () {
    $lead = dealAt(LeadStatus::New);
    $admin = dealAdmin();
    logContact($lead);

    $this->actingAs($admin)->post(route('company.crm.leads.transition', $lead), ['stage' => 'lost', 'reason' => 'Timing']);

    $this->actingAs($admin)
        ->post(route('company.crm.leads.reopen', $lead->fresh()), [
            'stage' => 'contacted',
            'justification' => 'They called back with budget approved for this quarter.',
        ])
        ->assertRedirect();

    $lead->refresh();

    expect($lead->status->value)->toBe('contacted')
        ->and($lead->qualified_at)->not->toBeNull()
        /* The loss is cleared, because it is no longer true. */
        ->and($lead->lost_reason)->toBeNull()
        ->and($lead->lost_at)->toBeNull();
});

it('refuses the stepper as a way out of Lost', function () {
    $lead = dealAt(LeadStatus::New);
    $admin = dealAdmin();
    logContact($lead);

    $this->actingAs($admin)->post(route('company.crm.leads.transition', $lead), ['stage' => 'lost', 'reason' => 'Timing']);

    $this->actingAs($admin)
        ->post(route('company.crm.leads.transition', $lead->fresh()), ['stage' => 'contacted'])
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status->value)->toBe('lost');
});

// ─────────────────────────────────────────────────────────────────────────────
// The payload the page is built from
// ─────────────────────────────────────────────────────────────────────────────

it('ships the stage control in the Now zone', function () {
    /*
     * The stage control moved into Z1 with the next action and the tasks —
     * three things that already agree with each other by construction. The
     * zone is deferred, so it is read from the builder.
     */
    $lead = dealAt(LeadStatus::Contacted);

    $this->actingAs(dealAdmin())
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Company/Crm/Leads/Show'));

    $stage = app(DealPageService::class)->now($lead)['stage_control'];

    expect($stage['transitions'])->toHaveCount(count(LeadStatus::cases()) - count(LeadStatus::RETIRED))
        ->and($stage['current'])->toBe('contacted')
        ->and($stage['suggested'])->toBe('demo_scheduled')
        ->and($stage['lost_reasons'])->not->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenant isolation must survive the rebuild
// ─────────────────────────────────────────────────────────────────────────────

it('does not let another tenant move a deal', function () {
    setPermissionsTeamId(0);
    $roleName = 'CRM Manager';
    Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName($roleName, 'web'));

    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $intruder = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $orgB->id]);
    $intruder->assignRole($roleName);

    $lead = dealAt(LeadStatus::Contacted, ['organization_id' => $orgA->id]);

    $this->actingAs($intruder)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'demo_scheduled'])
        ->assertForbidden();

    expect($lead->fresh()->status->value)->toBe('contacted');
});
