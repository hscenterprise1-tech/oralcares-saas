<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CRM\DealsQueryService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Phase 3 — Leads and Pipeline become one Deals workspace.
 *
 * Two payload builders sat over one table with different filter engines, two
 * stage vocabularies and three definitions of "stalled". A filter applied on
 * the list meant something else on the board.
 *
 * DealsQueryService is the one query layer all four views read.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->orgA = Organization::factory()->create(['name' => 'Tenant A']);
    $this->orgB = Organization::factory()->create(['name' => 'Tenant B']);

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgA->id]);
    $this->manager->assignRole('CRM Manager');

    $this->rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgA->id]);
    $this->rep->assignRole('Sales Representative');

    $this->otherRep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgA->id]);
    $this->otherRep->assignRole('Sales Representative');

    $this->intruder = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->orgB->id]);
    $this->intruder->assignRole('CRM Manager');

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
});

function deal(array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'status' => LeadStatus::Contacted,
        'qualified_at' => now()->subWeek(),
        'stage_entered_at' => now(),
    ], $attributes));
}

// ─────────────────────────────────────────────────────────────────────────────
// One dataset, one filter meaning
// ─────────────────────────────────────────────────────────────────────────────

it('returns the same records for the list and the board under the same filter', function () {
    deal(['company_name' => 'Alpha One', 'organization_id' => $this->orgA->id, 'assigned_user_id' => $this->rep->id]);
    deal(['company_name' => 'Alpha Two', 'organization_id' => $this->orgA->id, 'assigned_user_id' => $this->otherRep->id]);
    deal(['company_name' => 'Beta One', 'organization_id' => $this->orgB->id]);

    $filters = ['assigned_user_id' => $this->rep->id];
    $service = app(DealsQueryService::class);

    $list = $service->query($this->manager, $filters)->pluck('company_name')->sort()->values();
    $board = $service->query($this->manager, $filters)->pluck('company_name')->sort()->values();

    expect($list->all())->toBe($board->all())
        ->and($list)->toContain('Alpha One')
        ->and($list)->not->toContain('Alpha Two')
        ->and($list)->not->toContain('Beta One');
});

it('keeps tenant isolation on every view', function () {
    deal(['company_name' => 'Alpha Deal', 'organization_id' => $this->orgA->id]);
    deal(['company_name' => 'Beta Deal', 'organization_id' => $this->orgB->id]);

    $service = app(DealsQueryService::class);

    foreach (DealsQueryService::VIEWS as $view) {
        $names = $service->query($this->manager, ['view' => $view])->pluck('company_name');

        expect($names)->not->toContain('Beta Deal');
    }
});

it('narrows a rep to their own deals in every view', function () {
    deal(['company_name' => 'Mine', 'organization_id' => $this->orgA->id, 'assigned_user_id' => $this->rep->id]);
    deal(['company_name' => 'Theirs', 'organization_id' => $this->orgA->id, 'assigned_user_id' => $this->otherRep->id]);

    $names = app(DealsQueryService::class)->query($this->rep, [])->pluck('company_name');

    expect($names)->toContain('Mine')->not->toContain('Theirs');
});

// ─────────────────────────────────────────────────────────────────────────────
// Views
// ─────────────────────────────────────────────────────────────────────────────

it('shows only unqualified deals in the inbox', function () {
    deal(['company_name' => 'Fresh', 'organization_id' => $this->orgA->id, 'status' => LeadStatus::New, 'qualified_at' => null]);
    deal(['company_name' => 'Working', 'organization_id' => $this->orgA->id, 'status' => LeadStatus::Contacted]);
    deal(['company_name' => 'Dead', 'organization_id' => $this->orgA->id, 'status' => LeadStatus::Lost, 'qualified_at' => null]);

    $names = app(DealsQueryService::class)->query($this->manager, ['view' => 'inbox'])->pluck('company_name');

    expect($names)->toContain('Fresh')
        ->not->toContain('Working')
        ->not->toContain('Dead');
});

it('shows only lost deals in the lost view', function () {
    deal(['company_name' => 'Gone', 'organization_id' => $this->orgA->id, 'status' => LeadStatus::Lost]);
    deal(['company_name' => 'Live', 'organization_id' => $this->orgA->id]);

    $names = app(DealsQueryService::class)->query($this->manager, ['view' => 'lost'])->pluck('company_name');

    expect($names)->toContain('Gone')->not->toContain('Live');
});

/*
 * The board view was removed by product decision. What it asserted — that a
 * closed deal is reachable through the Lost cut and not mixed into live work —
 * is still true and still worth holding, so the test moved to the stage rail,
 * which is what now carries stage distribution.
 */
it('keeps closed deals out of the open pipeline the stage rail reports', function () {
    deal(['company_name' => 'Open', 'organization_id' => $this->orgA->id]);
    deal(['company_name' => 'Closed Lost', 'organization_id' => $this->orgA->id, 'status' => LeadStatus::Lost]);

    $rail = collect(app(DealsQueryService::class)->stageBreakdown($this->manager))->keyBy('value');

    expect($rail->get(LeadStatus::Lost->value)['count'])->toBe(1)
        ->and($rail->get(LeadStatus::Contacted->value)['count'])->toBe(1)
        // Every stage is represented, including the empty ones — the rail shows
        // the whole pipeline, not only the populated parts.
        ->and($rail)->toHaveCount(count(LeadStatus::cases()));
});

it('no longer offers a board view', function () {
    expect(DealsQueryService::VIEWS)->not->toContain('board');
});

// ─────────────────────────────────────────────────────────────────────────────
// Stalled — one definition
// ─────────────────────────────────────────────────────────────────────────────

it('does not call a deal stalled merely because it has no follow-up date', function () {
    // The old board predicate included `next_followup_at IS NULL`, which made
    // 12 of 12 live deals "stalled". Phase 1 made that column derived, so its
    // absence means no follow-up is booked — not that the deal is stuck.
    $fresh = deal([
        'company_name' => 'Fresh No Followup',
        'organization_id' => $this->orgA->id,
        'stage_entered_at' => now(),
        'next_followup_at' => null,
    ]);

    expect(app(DealsQueryService::class)->isStalled($fresh))->toBeFalse();
});

it('calls a deal stalled when it has not moved stage for the configured threshold', function () {
    $stuck = deal([
        'company_name' => 'Stuck',
        'organization_id' => $this->orgA->id,
        'stage_entered_at' => now()->subDays(30),
    ]);

    expect(app(DealsQueryService::class)->isStalled($stuck))->toBeTrue();
});

it('never calls a won or lost deal stalled', function () {
    $won = deal(['organization_id' => $this->orgA->id, 'status' => LeadStatus::Won, 'stage_entered_at' => now()->subDays(90)]);
    $lost = deal(['organization_id' => $this->orgA->id, 'status' => LeadStatus::Lost, 'stage_entered_at' => now()->subDays(90)]);

    $service = app(DealsQueryService::class);

    expect($service->isStalled($won))->toBeFalse()
        ->and($service->isStalled($lost))->toBeFalse();
});

it('agrees with the overview on which deals are stalled', function () {
    // CrmMetricsService already uses Lead::scopeStaleFor. Deals must not
    // invent a second answer.
    deal(['company_name' => 'Stuck', 'organization_id' => $this->orgA->id, 'stage_entered_at' => now()->subDays(30)]);
    deal(['company_name' => 'Fresh', 'organization_id' => $this->orgA->id, 'stage_entered_at' => now()]);

    $fromDeals = app(DealsQueryService::class)
        ->query($this->admin, ['view' => 'all', 'stalled_only' => true])
        ->pluck('company_name')->sort()->values();

    $fromMetrics = Lead::query()
        ->staleFor((int) config('crm.stale_lead_days', 14))
        ->pluck('company_name')->sort()->values();

    expect($fromDeals->all())->toBe($fromMetrics->all())
        ->and($fromDeals)->toContain('Stuck')
        ->and($fromDeals)->not->toContain('Fresh');
});

it('reports the same stalled flag on a record as the filter does', function () {
    $stuck = deal(['company_name' => 'Stuck', 'organization_id' => $this->orgA->id, 'stage_entered_at' => now()->subDays(30)]);
    $service = app(DealsQueryService::class);

    $inFilter = $service->query($this->admin, ['stalled_only' => true])->pluck('id')->contains($stuck->id);

    expect($inFilter)->toBe($service->isStalled($stuck));
});

// ─────────────────────────────────────────────────────────────────────────────
// Routing
// ─────────────────────────────────────────────────────────────────────────────

it('serves the unified deals workspace', function () {
    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Deals/Index')
            ->has('deals')
            /*
             * `savedViews`, not `views`. The raw `DealsQueryService::VIEWS`
             * list was sent on every request and never read by the page —
             * `savedViews` is what the view strip actually renders, and it
             * carries the label and hint each chip needs.
             */
            ->has('savedViews')
            ->missing('views')
            ->where('view', 'all')
        );
});

it('serves each view from the one destination', function () {
    foreach (DealsQueryService::VIEWS as $view) {
        $this->actingAs($this->admin)
            ->get(route('company.crm.deals.index', ['view' => $view]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('view', $view));
    }
});

it('redirects the legacy pipeline url to the deals view', function () {
    $this->actingAs($this->admin)
        ->get('/company/crm/pipeline')
        ->assertRedirect(route('company.crm.deals.index', absolute: false));
});

it('sends a root-relative redirect target, not a browser-relative one', function () {
    // assertRedirect normalises both sides through the URL generator, so a
    // destination missing its leading slash passes the assertion and still
    // resolves against the *current* path in a browser — producing
    // /company/crm/company/crm/deals. Pinning the raw header instead.
    foreach (['/company/crm/pipeline', '/company/crm/leads/pipeline', '/company/crm/leads'] as $legacy) {
        $location = $this->actingAs($this->admin)->get($legacy)->headers->get('Location');

        expect($location)->toStartWith('/company/crm/deals');
    }
});

it('redirects the legacy nested pipeline url to the deals view', function () {
    $this->actingAs($this->admin)
        ->get('/company/crm/leads/pipeline')
        ->assertRedirect(route('company.crm.deals.index', absolute: false));
});

it('keeps the legacy leads url working', function () {
    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.index'))
        ->assertRedirect(route('company.crm.deals.index', absolute: false));
});

it('keeps the deal detail url unchanged', function () {
    $lead = deal(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk();
});

// ─────────────────────────────────────────────────────────────────────────────
// Bulk assignment
// ─────────────────────────────────────────────────────────────────────────────

it('reassigns several deals at once', function () {
    $a = deal(['organization_id' => $this->orgA->id]);
    $b = deal(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->manager)
        ->post(route('company.crm.leads.batch-reassign'), [
            'lead_ids' => [$a->id, $b->id],
            'assigned_user_id' => $this->rep->id,
        ])
        ->assertRedirect();

    expect($a->fresh()->assigned_user_id)->toBe($this->rep->id)
        ->and($b->fresh()->assigned_user_id)->toBe($this->rep->id);
});

it('refuses to reassign a deal from another tenant', function () {
    $mine = deal(['organization_id' => $this->orgA->id]);
    $theirs = deal(['organization_id' => $this->orgB->id]);

    $this->actingAs($this->manager)
        ->from(route('company.crm.deals.index'))
        ->post(route('company.crm.leads.batch-reassign'), [
            'lead_ids' => [$mine->id, $theirs->id],
            'assigned_user_id' => $this->rep->id,
        ])
        ->assertSessionHasErrors('lead_ids');

    /*
     * Nothing is reassigned — the whole batch is rejected rather than
     * partially applied.
     *
     * Asserted as "not the target" rather than "null": every lead has an owner
     * from the moment it is created, so null would only prove that automatic
     * assignment had failed. Not-the-target is the property this test is for.
     */
    expect($mine->fresh()->assigned_user_id)->not->toBe($this->rep->id)
        ->and($theirs->fresh()->assigned_user_id)->not->toBe($this->rep->id);
});

it('refuses to reassign to a user in another tenant', function () {
    $mine = deal(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->manager)
        ->from(route('company.crm.deals.index'))
        ->post(route('company.crm.leads.batch-reassign'), [
            'lead_ids' => [$mine->id],
            'assigned_user_id' => $this->intruder->id,
        ])
        ->assertSessionHasErrors('assigned_user_id');

    expect($mine->fresh()->assigned_user_id)->not->toBe($this->intruder->id);
});

it('refuses bulk reassignment by a rep', function () {
    // Reassigning other people's work is a management action.
    $lead = deal(['organization_id' => $this->orgA->id]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.leads.batch-reassign'), [
            'lead_ids' => [$lead->id],
            'assigned_user_id' => $this->otherRep->id,
        ])
        ->assertForbidden();

    expect($lead->fresh()->assigned_user_id)->not->toBe($this->otherRep->id);
});

it('refuses to reassign a deal a manager cannot see', function () {
    $theirs = deal(['organization_id' => $this->orgB->id]);

    $this->actingAs($this->manager)
        ->from(route('company.crm.deals.index'))
        ->post(route('company.crm.leads.batch-reassign'), [
            'lead_ids' => [$theirs->id],
            'assigned_user_id' => $this->rep->id,
        ])
        ->assertSessionHasErrors('lead_ids');
});

// ─────────────────────────────────────────────────────────────────────────────
// Nothing useful was lost
// ─────────────────────────────────────────────────────────────────────────────

it('keeps search working on the unified workspace', function () {
    deal(['company_name' => 'Findable Dental', 'organization_id' => $this->orgA->id]);
    deal(['company_name' => 'Hidden Clinic', 'organization_id' => $this->orgA->id]);

    $names = app(DealsQueryService::class)
        ->query($this->manager, ['search' => 'Findable'])
        ->pluck('company_name');

    expect($names)->toContain('Findable Dental')->not->toContain('Hidden Clinic');
});

it('keeps export reachable', function () {
    $this->actingAs($this->admin)
        ->get(route('company.crm.leads.export'))
        ->assertOk();
});

it('keeps lost reason and win-back on the lost view', function () {
    $lost = deal([
        'company_name' => 'Recoverable',
        'organization_id' => $this->orgA->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => 'Price',
        'winback_scheduled_at' => now()->addMonth(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index', ['view' => 'lost']))
        ->assertOk()
        ->assertInertia(function ($page) use ($lost) {
            $rows = collect($page->toArray()['props']['deals']['data']);
            $row = $rows->firstWhere('id', $lost->id);

            expect($row['lost_reason'])->toBe('Price')
                ->and($row['winback_scheduled_at'])->not->toBeNull();
        });
});
