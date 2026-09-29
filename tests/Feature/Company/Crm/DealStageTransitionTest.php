<?php

use App\Exceptions\IllegalStageTransition;
use App\LeadStatus;
use App\Models\CompanyPlan;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\DealStageService;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The deal state machine.
 *
 * Before this, the pipeline was a set of twelve labels a deal could be given in
 * any order. `requirementMet()` gated eight of them and fell through to `true`
 * for New, Contacted, Demo Scheduled and Negotiation — so a deal could be marked
 * Contacted the second it was created, and a *won* deal could be dragged back to
 * Contacted, because nothing anywhere compared the current stage to the target.
 *
 * Two rules now decide a move, and they are different kinds of rule:
 *
 *   legality      does the edge exist? Won is terminal, Lost reopens only
 *                 through `reopen()`, a running trial does not slide backwards,
 *                 and a qualified deal does not return to intake. Enforced in
 *                 `LeadWorkspaceService::transitionStage()`, so no caller —
 *                 controller, job, seeder — can route around it.
 *   prerequisite  is the evidence there? Enforced at the request boundary,
 *                 where the answer can be rendered on the field that caused it.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->admin = User::factory()->masterAdmin()->create(['organization_id' => $this->org->id]);

    $this->manager = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->manager->assignRole('CRM Manager');
});

function stagedLead(LeadStatus $status, array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'organization_id' => test()->org->id,
        'status' => $status,
        'stage_entered_at' => now(),
    ], $attributes));
}

// ─────────────────────────────────────────────────────────────────────────────
// Prerequisites — "not yet, and here is what to do"
// ─────────────────────────────────────────────────────────────────────────────

it('refuses Contacted until a contact is actually logged', function () {
    $lead = stagedLead(LeadStatus::New);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted'])
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status->value)->toBe('new');
});

it('allows Contacted once a call is on the record', function () {
    $lead = stagedLead(LeadStatus::New);
    logContact($lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted'])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('contacted');
});

/*
 * A stage change is not a contact. The pipeline's second column filled with
 * deals nobody had spoken to precisely because anything counted.
 */
it('does not accept a system activity as evidence of contact', function () {
    $lead = stagedLead(LeadStatus::New);

    $lead->activities()->create([
        'type' => 'system_update',
        'description' => 'Stage changed from New to New.',
    ]);

    expect(app(DealStageService::class)->requirementMet($lead->fresh(), 'contacted'))->toBeFalse();
});

it('refuses a demo booking nobody can attend', function () {
    $lead = stagedLead(LeadStatus::Contacted);
    $stages = app(DealStageService::class);

    /* No demo at all. */
    expect($stages->requirementMet($lead, 'demo_scheduled'))->toBeFalse();

    /* A demo with no presenter and no way in. */
    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => null,
        'scheduled_at' => now()->addDay(),
        'meeting_link' => null,
        'location' => null,
    ]);

    expect($stages->requirementMet($lead->fresh(), 'demo_scheduled'))->toBeFalse();
});

it('accepts a demo with a time, a presenter and a join link', function () {
    $lead = stagedLead(LeadStatus::Contacted);
    scheduleJoinableDemo($lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'demo_scheduled'])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('demo_scheduled');
});

/* An in-person demo has an address instead of a link, and that is enough. */
it('accepts a site visit with a location and no meeting link', function () {
    $lead = stagedLead(LeadStatus::Contacted);

    Demo::factory()->create([
        'lead_id' => $lead->id,
        'presenter_id' => $this->manager->id,
        'scheduled_at' => now()->addDay(),
        'meeting_link' => null,
        'location' => '3rd floor, Apex Tower, Andheri West',
    ]);

    expect(app(DealStageService::class)->requirementMet($lead->fresh(), 'demo_scheduled'))->toBeTrue();
});

/*
 * The old check was "a Trial row exists", which a draft with no package and no
 * tenant satisfies — so the stage that drives every usage report could be set on
 * a deal with nothing to measure.
 */
it('refuses Trial Active for a trial with no package or no tenant', function () {
    $lead = stagedLead(LeadStatus::DemoCompleted);
    $stages = app(DealStageService::class);

    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'company_plan_id' => null,
        'clinic_id' => null,
    ]);

    expect($stages->requirementMet($lead->fresh(), 'trial_active'))->toBeFalse();

    $trial->update(['company_plan_id' => CompanyPlan::factory()->create()->id]);

    /* A package but still no tenant — nothing to measure. */
    expect($stages->requirementMet($lead->fresh(), 'trial_active'))->toBeFalse();
});

it('refuses Won without settled commercials', function () {
    $lead = stagedLead(LeadStatus::PaymentReceived);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'won'])
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status->value)->toBe('payment_received');
});

// ─────────────────────────────────────────────────────────────────────────────
// Legality — "this edge does not exist"
// ─────────────────────────────────────────────────────────────────────────────

it('hard blocks every move out of Won', function () {
    $lead = stagedLead(LeadStatus::Won, ['qualified_at' => now()->subMonth()]);

    foreach (['contacted', 'new', 'demo_scheduled', 'negotiation', 'lost'] as $target) {
        $this->actingAs($this->admin)
            ->post(route('company.crm.leads.transition', $lead->fresh()), [
                'stage' => $target,
                'reason' => 'Price',
            ])
            ->assertSessionHasErrors('stage');
    }

    expect($lead->fresh()->status->value)->toBe('won');
});

it('offers no transition at all on a won deal', function () {
    $lead = stagedLead(LeadStatus::Won, ['qualified_at' => now()->subMonth()]);

    $transitions = collect(app(DealStageService::class)->availableTransitions($lead));

    expect($transitions->where('allowed', true))->toBeEmpty()
        /* Every stage the rail shows, minus the current one. */
        ->and($transitions->where('blocked', true))->toHaveCount(count(LeadStatus::cases()) - count(LeadStatus::RETIRED) - 1)
        /* A blocked edge offers no remedy, because there is none. */
        ->and($transitions->where('blocked', true)->whereNotNull('action'))->toBeEmpty();
});

/*
 * The invariant holds below the controller too. A job, a seeder or an API
 * client gets the same refusal as the stepper.
 */
it('refuses a regression from Won inside the service itself', function () {
    $lead = stagedLead(LeadStatus::Won, ['qualified_at' => now()->subMonth()]);

    expect(fn () => app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Contacted->value, $this->admin, 'sneaky'))
        ->toThrow(IllegalStageTransition::class);

    expect($lead->fresh()->status->value)->toBe('won');
});

it('will not let a qualified deal fall back into intake', function () {
    $lead = stagedLead(LeadStatus::Contacted, ['qualified_at' => now()->subWeek()]);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'new'])
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status->value)->toBe('contacted');
});

it('will not let a running trial slide backwards', function () {
    $lead = stagedLead(LeadStatus::TrialActive, ['qualified_at' => now()->subWeek()]);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'demo_scheduled'])
        ->assertSessionHasErrors('stage');

    /* Forward is still fine — a trial ends by moving on. */
    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead->fresh()), ['stage' => 'negotiation'])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('negotiation');
});

// ─────────────────────────────────────────────────────────────────────────────
// The two deliberate escapes
// ─────────────────────────────────────────────────────────────────────────────

it('reopens a lost deal through the reopen endpoint, with a reason', function () {
    $lead = stagedLead(LeadStatus::Lost, [
        'qualified_at' => now()->subWeek(),
        'lost_reason' => 'Price',
        'lost_competitor' => 'Practo',
        'lost_at' => now()->subDay(),
    ]);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.reopen', $lead), [
            'stage' => 'negotiation',
            'justification' => 'They came back after their renewal quote went up.',
        ])
        ->assertRedirect();

    $lead->refresh();

    expect($lead->status->value)->toBe('negotiation')
        /* The loss is cleared, because it is no longer true. */
        ->and($lead->lost_reason)->toBeNull()
        ->and($lead->lost_competitor)->toBeNull()
        ->and($lead->lost_at)->toBeNull();
});

it('demands a justification to reopen', function () {
    $lead = stagedLead(LeadStatus::Lost, ['lost_reason' => 'Price']);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.reopen', $lead), ['stage' => 'negotiation'])
        ->assertSessionHasErrors('justification');

    expect($lead->fresh()->status->value)->toBe('lost');
});

it('will not reopen straight into Won', function () {
    $lead = stagedLead(LeadStatus::Lost, ['lost_reason' => 'Price']);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.reopen', $lead), [
            'stage' => 'won',
            'justification' => 'They signed after all, honestly they did.',
        ])
        ->assertSessionHasErrors('stage');
});

it('will not reopen a deal that is not lost', function () {
    $lead = stagedLead(LeadStatus::Negotiation);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.reopen', $lead), [
            'stage' => 'contacted',
            'justification' => 'No particular reason at all.',
        ])
        ->assertSessionHasErrors('stage');
});

/*
 * The escape from the Won gate exists so that nobody edits the database
 * instead. It is restricted to managers and it demands a sentence, which lands
 * in the deal's history as the reason the gate was skipped.
 */
it('lets a manager close a deal on an explicit override', function () {
    $lead = stagedLead(LeadStatus::PaymentReceived, ['qualified_at' => now()->subWeek()]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.transition', $lead), [
            'stage' => 'won',
            'override' => true,
            'override_reason' => 'Signed PO received offline, finance will reconcile on Monday.',
        ])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('won');
});

it('refuses an override without a reason', function () {
    $lead = stagedLead(LeadStatus::PaymentReceived);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'won', 'override' => true])
        ->assertSessionHasErrors('override_reason');

    expect($lead->fresh()->status->value)->toBe('payment_received');
});

it('refuses an override from somebody who is not a manager', function () {
    /*
     * A narrowed grant on purpose: the default set includes `view-operations`,
     * which is one of the three permissions `CrmScope::isManager()` reads, so
     * the standard rep fixture would quietly satisfy the check under test.
     */
    $rep = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]), ['view-crm', 'view-leads', 'create leads', 'edit own leads']);

    $lead = stagedLead(LeadStatus::PaymentReceived, ['assigned_user_id' => $rep->id]);

    $this->actingAs($rep)
        ->post(route('company.crm.leads.transition', $lead), [
            'stage' => 'won',
            'override' => true,
            'override_reason' => 'I would very much like this deal to be won.',
        ])
        ->assertForbidden();

    expect($lead->fresh()->status->value)->toBe('payment_received');
});

// ─────────────────────────────────────────────────────────────────────────────
// The matrix, asserted as a whole
// ─────────────────────────────────────────────────────────────────────────────

/*
 * Every stage the rail can show has a rank, or "forwards" and "backwards" mean
 * nothing. Lost is deliberately outside it: it is not a point on the road, it
 * is leaving the road.
 */
/*
 * Two stages sit outside the rank, for opposite reasons. Lost is not a point on
 * the road — it is leaving it. `assigned` is retired: it was a stage meaning
 * "somebody owns this", which is true of every lead from the moment it exists.
 */
it('ranks every stage still on the road', function () {
    $ranked = array_keys(DealStageService::RANK);
    $all = array_column(LeadStatus::cases(), 'value');

    expect(array_values(array_diff($all, $ranked)))->toBe(['assigned', 'lost'])
        ->and(DealStageService::RANK['won'])->toBe(max(DealStageService::RANK));
});

it('refuses to move a deal into a retired stage', function () {
    $lead = stagedLead(LeadStatus::Contacted);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'assigned'])
        ->assertSessionHasErrors('stage');

    expect($lead->fresh()->status->value)->toBe('contacted');
});

/* A lead still carrying it can leave; it simply cannot go back. */
it('lets a legacy lead move off a retired stage', function () {
    $lead = stagedLead(LeadStatus::Assigned);
    logContact($lead);

    $this->actingAs($this->admin)
        ->post(route('company.crm.leads.transition', $lead), ['stage' => 'contacted'])
        ->assertRedirect();

    expect($lead->fresh()->status->value)->toBe('contacted');
});

it('blocks exactly the edges the matrix says it blocks', function () {
    $stages = app(DealStageService::class);

    $cases = [
        /* from,              to,                should be blocked */
        [LeadStatus::Won, 'contacted', true],
        [LeadStatus::Won, 'lost', true],
        [LeadStatus::Lost, 'contacted', true],
        [LeadStatus::TrialActive, 'contacted', true],
        [LeadStatus::TrialActive, 'negotiation', false],
        [LeadStatus::Negotiation, 'quotation_sent', false],
        /* Backwards, but legitimately — a quote gets revised. */
        [LeadStatus::QuotationSent, 'negotiation', false],
        [LeadStatus::Negotiation, 'lost', false],
    ];

    foreach ($cases as [$from, $to, $expected]) {
        $lead = stagedLead($from, ['qualified_at' => now()->subWeek()]);

        expect($stages->blockReason($lead, $to) !== null)
            ->toBe($expected, "{$from->value} → {$to}");
    }
});
