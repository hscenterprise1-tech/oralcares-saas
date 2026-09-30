<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CRM\DealsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Deals list must not ask the database a question per row.
 *
 * `CrmPerformanceTest` pins the Overview and the Sales Teams index; the Deals
 * list — the busiest page in the module — had no such guard, which is how it
 * came to issue 44 queries, 25 of them a `SELECT EXISTS` per row to answer
 * whether that deal was stalled. The predicate reads three attributes the row
 * already carries.
 *
 * Two properties are asserted, and the second matters more than the first: the
 * count must be under the ceiling, *and* it must not move when the page fills
 * up. A flat-but-high number is a tuning problem; a number that grows with rows
 * is the bug coming back.
 */

/** The ceiling. Measured at 11 on a full page after the N+1 was removed. */
const DEALS_INDEX_QUERY_CEILING = 18;

function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

/**
 * @param  int  $count  How many deals to spread across the open stages.
 */
function seedDeals(Organization $organization, User $owner, int $count): void
{
    $stages = [
        LeadStatus::New->value,
        LeadStatus::Contacted->value,
        LeadStatus::DemoScheduled->value,
        LeadStatus::Negotiation->value,
        LeadStatus::QuotationSent->value,
    ];

    for ($i = 0; $i < $count; $i++) {
        Lead::factory()->create([
            'organization_id' => $organization->id,
            'assigned_user_id' => $i % 3 === 0 ? null : $owner->id,
            'status' => $stages[$i % count($stages)],
            /*
             * Half of them old enough to be stalled, so the stalled predicate is
             * genuinely exercised rather than short-circuiting on every row.
             */
            'stage_entered_at' => now()->subDays($i % 2 === 0 ? 40 : 1),
            'next_followup_at' => $i % 4 === 0 ? now()->subDay() : null,
        ]);
    }
}

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->manager = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);
});

it('keeps the deals index under the query ceiling on a full page', function () {
    // More than one page, so the mapper runs over a full 25 rows.
    seedDeals($this->organization, $this->manager, 40);

    $queries = countQueries(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful());

    expect($queries)->toBeLessThanOrEqual(DEALS_INDEX_QUERY_CEILING);
});

it('keeps the deals index query count flat as the pipeline grows', function () {
    seedDeals($this->organization, $this->manager, 5);

    $small = countQueries(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful());

    // Past a full page, which is where a per-row query used to cost 25.
    seedDeals($this->organization, $this->manager, 60);

    $large = countQueries(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful());

    /*
     * Exactly flat is the honest expectation — nothing on this page is
     * per-row any more. The allowance of one absorbs an eager-load that
     * legitimately appears only once there are rows of that kind to load.
     */
    expect($large - $small)->toBeLessThanOrEqual(1)
        ->and($large)->toBeLessThanOrEqual(DEALS_INDEX_QUERY_CEILING);
});

it('answers stalled from the loaded row rather than with a query each', function () {
    seedDeals($this->organization, $this->manager, 30);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($this->manager)->get(route('company.crm.deals.index'))->assertSuccessful();

    /*
     * `select exists(...)` at the *start* of the statement — the shape
     * `->exists()` compiles to, which is what ran once per row. Matching the
     * bare word "exists" anywhere would also catch the `where exists (select
     * ... from trials)` sub-clause inside the legitimate counts union, which is
     * one query for the whole page and not what this guards against.
     */
    $perRowExistsQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q) => str_starts_with(ltrim($q['query']), 'select exists('))
        ->count();

    DB::disableQueryLog();

    expect($perRowExistsQueries)->toBe(0);
});

it('takes one round trip for every saved-view count, not one each', function () {
    seedDeals($this->organization, $this->manager, 12);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $counts = app(DealsQueryService::class)->counts($this->manager);

    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    /*
     * Nine views, one query. The union is built from the same nine builders the
     * views themselves render, so collapsing the round trips cannot make a chip
     * disagree with the list it opens — which re-expressing each view as a
     * conditional aggregate would have risked.
     */
    expect($queries)->toBe(1)
        ->and($counts)->toHaveKeys([
            'all', 'mine', 'unassigned', 'at_risk', 'stale',
            'trial_active', 'payment_pending', 'lost', 'inbox',
        ]);
});

it('reports the same count through the union as through the view itself', function () {
    seedDeals($this->organization, $this->manager, 20);

    $service = app(DealsQueryService::class);
    $counts = $service->counts($this->manager);

    foreach (['all', 'mine', 'unassigned', 'at_risk', 'stale', 'lost', 'inbox'] as $view) {
        expect($counts[$view])
            ->toBe($service->query($this->manager, ['view' => $view])->count(), "view [{$view}] disagreed");
    }
});
