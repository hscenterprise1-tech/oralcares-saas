<?php

/*
 * The V2 Overview's contract.
 *
 * The rebuild's whole claim is that the page tells the truth: every figure is
 * server-computed, every figure opens the rows it counted, and nothing renders
 * a number the data cannot support. These tests hold that claim rather than
 * checking that widgets exist.
 */

use App\LeadStatus;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CRM\DealsQueryService;
use App\Services\CrmMetricsService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));

    $this->org = Organization::factory()->create();
    $this->manager = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]);
    $this->manager->assignRole('CRM Manager');
    $this->metrics = app(CrmMetricsService::class);
});

/** A lead in this manager's organization, with sane defaults. */
/**
 * A lead on this organisation's book.
 *
 * Passing `assigned_user_id => null` means *deliberately unowned*, which the
 * product no longer produces on its own — `LeadObserver::created()` gives every
 * lead an owner. The attention rows that count unowned leads are there for the
 * legacy rows that predate that, so the helper suppresses assignment rather
 * than letting the rota quietly fill the gap the test is measuring.
 */
function commandCentreLead(array $attributes = []): Lead
{
    if (array_key_exists('assigned_user_id', $attributes) && $attributes['assigned_user_id'] === null) {
        return unownedLead(array_merge([
            'organization_id' => test()->org->id,
            'status' => LeadStatus::Contacted,
        ], $attributes));
    }

    return Lead::factory()->create(array_merge([
        'organization_id' => test()->org->id,
        'assigned_user_id' => test()->manager->id,
        'status' => LeadStatus::Contacted,
    ], $attributes));
}

// ─────────────────────────────────────────────── honest headline figures

it('counts how many open deals actually carry a value', function () {
    commandCentreLead(['expected_mrr' => 50000, 'expected_arr' => null]);
    commandCentreLead(['expected_mrr' => null, 'expected_arr' => null]);
    commandCentreLead(['expected_mrr' => 0, 'expected_arr' => null]);

    $kpis = $this->metrics->kpis($this->manager);

    // The headline says "N of M deals valued". M is every open deal; N is only
    // those contributing to the sum — deriving N from stage rows counted every
    // deal in any stage that held value, which overstated it.
    expect($kpis['active_pipeline_count'])->toBe(3)
        ->and($kpis['active_pipeline_valued_count'])->toBe(1);
});

// ─────────────────────────────────────────────── attention rows

it('carries the money each attention row is holding up', function () {
    commandCentreLead(['assigned_user_id' => null, 'expected_mrr' => 10000, 'expected_arr' => null]);
    commandCentreLead(['assigned_user_id' => null, 'expected_mrr' => 5000, 'expected_arr' => null]);

    $items = collect($this->metrics->attention($this->manager)['items'])->keyBy('key');

    expect($items['unassigned_leads']['count'])->toBe(2)
        // 15,000 MRR annualised — "₹1.8L is unowned" is a priority in a way
        // that "2 deals are unowned" is not.
        ->and($items['unassigned_leads']['value'])->toBe(180000.0);
});

it('reports deals that are invisible to the forecast', function () {
    commandCentreLead(['expected_mrr' => 50000]);
    commandCentreLead(['expected_mrr' => null, 'expected_arr' => null]);
    commandCentreLead(['expected_mrr' => 0, 'expected_arr' => null]);
    // Terminal deals are not pipeline and must not be counted as gaps in it.
    commandCentreLead(['status' => LeadStatus::Lost, 'expected_mrr' => null, 'expected_arr' => null]);

    $attention = $this->metrics->attention($this->manager);

    expect($attention['deals_missing_value'])->toBe(2);
});

/*
 * The Overview deliberately has no "deals with no next action" row.
 *
 * It was designed in, then removed once this test showed it could never fire:
 * LeadObserver runs NextBestActionService on every save, so an open deal always
 * carries a recommendation. A row that can only ever read zero is exactly the
 * decorative metric the rebuild set out to delete. This test stays as the
 * reason, so nobody adds it back.
 */
it('always gives an open deal a recommended next action', function () {
    $lead = commandCentreLead(['next_best_action' => null]);

    expect($lead->fresh()->next_best_action)->not->toBeNull();
});

/*
 * The rule that keeps the page honest: a count and the list it links to are
 * the same query. A figure that opens a list of a different size is worse than
 * no figure, because it teaches the reader not to trust the page.
 */
it('agrees with the Deals list each attention row links to', function () {
    commandCentreLead(['expected_mrr' => 50000, 'next_best_action' => 'Book a demo.']);
    commandCentreLead(['expected_mrr' => null, 'expected_arr' => null, 'next_best_action' => null]);
    commandCentreLead(['expected_mrr' => null, 'expected_arr' => null, 'next_best_action' => 'Run the demo.']);
    commandCentreLead(['assigned_user_id' => null, 'expected_mrr' => 20000]);

    $attention = $this->metrics->attention($this->manager);
    $deals = app(DealsQueryService::class);

    $missingValueRows = $deals->query($this->manager, ['missing_value' => 1])->count();
    $unassignedRows = $deals->query($this->manager, ['unassigned_only' => 1])->count();

    expect($missingValueRows)->toBe($attention['deals_missing_value'])
        ->and($unassignedRows)->toBe($attention['unassigned_leads']);
});

// ─────────────────────────────────────────────── pipeline diagnosis

it('names the funnel step losing the most, measured against its own denominator', function () {
    // Three reached Demo; one of them went on to a quotation. Trial is skipped
    // as a branch rather than a gate, so Demo -> Quotation is the real drop.
    commandCentreLead(['status' => LeadStatus::DemoCompleted, 'stage_durations' => ['contacted' => 1]]);
    commandCentreLead(['status' => LeadStatus::DemoCompleted, 'stage_durations' => ['contacted' => 1]]);
    commandCentreLead([
        'status' => LeadStatus::QuotationSent,
        'stage_durations' => ['contacted' => 1, 'demo_completed' => 1],
    ]);

    $drop = $this->metrics->pipelineHealth($this->manager)['biggest_drop'];

    expect($drop)->not->toBeNull()
        ->and($drop['to'])->toBe('Won')
        ->and($drop['of'])->toBe(1);
});

it('reports a real zero conversion with its denominator visible', function () {
    // One lead, never contacted. "0 of 1 got through" is a true finding and
    // carries its own denominator, so it is reported rather than suppressed.
    commandCentreLead(['status' => LeadStatus::New]);

    $drop = $this->metrics->pipelineHealth($this->manager)['biggest_drop'];

    expect($drop['to'])->toBe('Contacted')
        ->and($drop['reached'])->toBe(0)
        ->and($drop['of'])->toBe(1);
});

it('reports no drop at all when there is nothing to divide by', function () {
    // No leads means every denominator is empty. An undefined ratio is not a
    // zero one, and printing 0% here would be a fabricated finding.
    $drop = $this->metrics->pipelineHealth($this->manager)['biggest_drop'];

    expect($drop)->toBeNull();
});

// ─────────────────────────────────────────────── movement feed

it('reads stage movement and knows which way each deal went', function () {
    $lead = commandCentreLead(['status' => LeadStatus::DemoScheduled]);

    Activity::create([
        'subject_type' => 'lead',
        'subject_id' => $lead->id,
        'user_id' => $this->manager->id,
        'type' => 'system_update',
        'description' => 'Stage changed from contacted to demo_scheduled',
        'properties' => ['previous_stage' => 'contacted', 'new_stage' => 'demo_scheduled'],
    ]);

    Activity::create([
        'subject_type' => 'lead',
        'subject_id' => $lead->id,
        'user_id' => $this->manager->id,
        'type' => 'system_update',
        'description' => 'Stage changed from negotiation to lost',
        'properties' => ['previous_stage' => 'negotiation', 'new_stage' => 'lost'],
    ]);

    $movement = collect($this->metrics->pipelineMovement($this->manager))->keyBy('to');

    expect($movement)->toHaveCount(2)
        ->and($movement['demo_scheduled']['direction'])->toBe('forward')
        ->and($movement['lost']['direction'])->toBe('lost')
        ->and($movement['demo_scheduled']['company_name'])->toBe($lead->company_name);
});

it('skips stage changes whose payload it cannot read rather than parsing prose', function () {
    $lead = commandCentreLead();

    // Rows written before the structured payload existed carry the detail only
    // in their sentence. A sentence is not a contract.
    Activity::create([
        'subject_type' => 'lead',
        'subject_id' => $lead->id,
        'user_id' => $this->manager->id,
        'type' => 'system_update',
        'description' => 'Stage changed from contacted to demo_scheduled',
        'properties' => null,
    ]);

    expect($this->metrics->pipelineMovement($this->manager))->toBe([]);
});

it('never reports movement on a deal the viewer may not see', function () {
    $otherOrg = Organization::factory()->create();
    $foreign = Lead::factory()->create([
        'organization_id' => $otherOrg->id,
        'status' => LeadStatus::Contacted,
    ]);

    Activity::create([
        'subject_type' => 'lead',
        'subject_id' => $foreign->id,
        'user_id' => $this->manager->id,
        'type' => 'system_update',
        'description' => 'Stage changed from new to contacted',
        'properties' => ['previous_stage' => 'new', 'new_stage' => 'contacted'],
    ]);

    expect($this->metrics->pipelineMovement($this->manager))->toBe([]);
});

// ─────────────────────────────────────────────── page contract

it('sends the command centre exactly what it renders and nothing it does not', function () {
    commandCentreLead(['expected_mrr' => 40000]);

    $this->actingAs($this->manager)
        ->get('/company/crm/overview')
        ->assertStatus(200)
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Dashboard')
            ->has('kpis.active_pipeline_valued_count')
            ->has('attention.items')
            // The reader's own queue is part of the first paint now that the
            // Overview has absorbed My Work.
            ->has('personal')
            /*
             * Still absent, and deliberately so: these were retired when their
             * sections moved elsewhere, and nothing has brought them back.
             */
            ->missing('team_performance')
            ->missing('recent_activity')
            ->missing('schedule')
            ->missing('stale')
            /*
             * The heavy aggregates are deferred so the queue paints first.
             *
             * `forecast` was previously asserted absent — the earlier rebuild
             * had dropped it from this page. It is back by an explicit product
             * decision: the executive Overview is required to carry pipeline
             * and weighted forecast, and the computation had been sitting
             * tested and unsurfaced since the reporting work.
             */
            ->loadDeferredProps(['pipeline'], fn ($reload) => $reload
                ->has('pipeline.biggest_drop')
                ->has('top_deals')
                ->has('forecast')
            )
            /*
             * `movement` was a raw feed of stage changes that nobody could act on
             * from a dashboard; its proper home is the deal's own timeline,
             * which already carries it. What the people group defers now is the
             * pair of things a manager acts on: who is behind, and on what.
             */
            ->loadDeferredProps(['people'], fn ($reload) => $reload
                ->has('rep_triage')
                ->has('stalled')
                ->missing('movement')
            )
        );
});

it('offers only reporting periods that return a distinguishable answer', function () {
    $this->actingAs($this->manager)
        ->get('/company/crm/overview')
        ->assertInertia(fn ($page) => $page
            ->where('period.current', 'month')
            ->where('period.options', fn ($options) => collect($options)->pluck('value')->all() === ['week', 'month', 'quarter'])
        );
});

it('caps the top opportunities at four', function () {
    Lead::factory()->count(7)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->manager->id,
        'status' => LeadStatus::Contacted,
        'expected_mrr' => 50000,
    ]);

    $this->actingAs($this->manager)
        ->get('/company/crm/overview')
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['pipeline'], fn ($reload) => $reload->has('top_deals', 4))
        );
});
