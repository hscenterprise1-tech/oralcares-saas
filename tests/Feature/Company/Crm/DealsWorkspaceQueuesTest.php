<?php

use App\Http\Controllers\Company\Crm\DealController;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The Deals workspace: four queues and one embedded deal.
 *
 * The page was a twelve-lane board. Twelve columns do not fit on a screen, so
 * it was a horizontal scroll — and each lane was a capped sample of its stage
 * presenting itself as the stage. What replaced it is four operational queues
 * over one full-width paginated table; a row opens the deal's own page.
 *
 * These tests hold the parts of that which are easy to get quietly wrong: that
 * a queue narrows the list but not the counts the other tabs are read from,
 * and that the narrower of two overlapping controls always wins.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->other = Organization::factory()->create();

    $this->admin = User::factory()->masterAdmin()->create(['organization_id' => $this->org->id]);
});

function queued(string $name, LeadStatus $status, Organization $org): Lead
{
    return Lead::factory()->create([
        'company_name' => $name,
        'organization_id' => $org->id,
        'status' => $status,
        'stage_entered_at' => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// The queues
// ─────────────────────────────────────────────────────────────────────────────

it('opens on the inflow queue and shows only the stages that queue covers', function () {
    queued('Fresh Enquiry', LeadStatus::New, $this->org);
    queued('Demo Booked', LeadStatus::DemoScheduled, $this->org);
    queued('Evaluating', LeadStatus::TrialActive, $this->org);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $names = collect($props['deals']['data'])->pluck('company_name');

            expect($props['queue'])->toBe('inflow')
                ->and($names)->toContain('Fresh Enquiry')
                ->and($names)->not->toContain('Demo Booked')
                ->and($names)->not->toContain('Evaluating')
                /* The board is gone, not hidden. */
                ->and($props)->not->toHaveKey('board');
        });
});

it('narrows the feed to the queue that was asked for', function () {
    queued('Fresh Enquiry', LeadStatus::New, $this->org);
    queued('Demo Booked', LeadStatus::DemoScheduled, $this->org);
    queued('Demo Done', LeadStatus::DemoCompleted, $this->org);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index', ['queue' => 'demos']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $names = collect($page->toArray()['props']['deals']['data'])->pluck('company_name');

            expect($names->sort()->values()->all())->toBe(['Demo Booked', 'Demo Done']);
        });
});

/*
 * The counts are what the tabs are navigated by, so they have to describe the
 * queues you are *not* looking at. Deriving them from the feed's own query —
 * the obvious implementation — would leave every tab but the current one
 * reading zero, which makes the strip unusable precisely when it matters.
 */
it('counts every queue while showing only one of them', function () {
    queued('Fresh Enquiry', LeadStatus::New, $this->org);
    queued('Demo Booked', LeadStatus::DemoScheduled, $this->org);
    queued('Evaluating', LeadStatus::TrialActive, $this->org);
    queued('Negotiating', LeadStatus::Negotiation, $this->org);
    queued('Signed', LeadStatus::Won, $this->org);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index', ['queue' => 'trials']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];
            $queues = collect($props['queues'])->keyBy('key');

            expect(collect($props['deals']['data']))->toHaveCount(1)
                ->and($queues->get('inflow')['count'])->toBe(1)
                ->and($queues->get('demos')['count'])->toBe(1)
                ->and($queues->get('trials')['count'])->toBe(1)
                ->and($queues->get('closing')['count'])->toBe(1)
                ->and($queues->get('archive')['count'])->toBe(1)
                /* Every queue the page offers is described, none omitted. */
                ->and($queues)->toHaveCount(count(DealController::QUEUES));
        });
});

it('widens to the whole book when the queue is explicitly all', function () {
    queued('Fresh Enquiry', LeadStatus::New, $this->org);
    queued('Signed', LeadStatus::Won, $this->org);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index', ['queue' => 'all']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $names = collect($page->toArray()['props']['deals']['data'])->pluck('company_name');

            expect($names)->toContain('Fresh Enquiry')->toContain('Signed');
        });
});

it('keeps the queue inside the tenant boundary', function () {
    queued('Ours', LeadStatus::New, $this->org);
    queued('Theirs', LeadStatus::New, $this->other);

    $rep = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
    ]));

    $this->actingAs($rep)
        ->get(route('company.crm.deals.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $names = collect($page->toArray()['props']['deals']['data'])->pluck('company_name');

            expect($names)->not->toContain('Theirs');
        });
});

// ─────────────────────────────────────────────────────────────────────────────
// Which control wins
// ─────────────────────────────────────────────────────────────────────────────

it('lets an exact stage filter outrank the queue that contains it', function () {
    queued('Demo Booked', LeadStatus::DemoScheduled, $this->org);
    queued('Demo Done', LeadStatus::DemoCompleted, $this->org);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index', ['queue' => 'demos', 'stage' => 'demo_completed']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $names = collect($page->toArray()['props']['deals']['data'])->pluck('company_name');

            expect($names->all())->toBe(['Demo Done']);
        });
});

/*
 * Clicking "Lost" and being shown nothing is the page contradicting the control
 * you just pressed — which is what happens if the default queue is still
 * applied underneath a named view. The queue is reported back as `all` so the
 * tab strip says what it is doing rather than highlighting a queue it is not.
 */
it('lets a named view outrank the queue, and says so', function () {
    queued('Gone', LeadStatus::Lost, $this->org);

    $this->actingAs($this->admin)
        ->get(route('company.crm.deals.index', ['view' => 'lost']))
        ->assertOk()
        ->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            expect($props['queue'])->toBe('all')
                ->and(collect($props['deals']['data'])->pluck('company_name'))->toContain('Gone');
        });
});
