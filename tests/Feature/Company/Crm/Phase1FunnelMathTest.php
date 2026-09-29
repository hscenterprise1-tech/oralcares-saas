<?php

use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CrmMetricsService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 7 — funnel mathematics on a known fixture.
 *
 * DEFINITIONS
 *
 *   Leads       every lead in scope.
 *   Stage count leads that EVER REACHED the stage, from `stage_durations`
 *               (a key is banked for each stage left) plus the current status.
 *   Conversion  count ÷ count of the step above, as a percentage. Null when
 *               that population is empty — an undefined ratio is not zero.
 *
 * Lost is handled deliberately: a lost deal counts toward every stage it
 * genuinely reached and then stops advancing. It is not removed from history.
 *
 * THE FIXTURE — 10 leads
 *
 *   2  never contacted            (new)
 *   2  contacted, then lost       reached Contacted
 *   2  demoed, still open         reached Contacted, Demo
 *   1  trialled, then lost        reached Contacted, Demo, Trial
 *   2  quoted, still open         reached Contacted, Demo, Trial, Quotation
 *   1  won                        reached all five
 *
 *   Leads      10
 *   Contacted   8  = 80.0%  of 10
 *   Demo        6  = 75.0%  of 8
 *   Trial       4  = 66.7%  of 6
 *   Quotation   3  = 75.0%  of 4
 *   Won         1  = 33.3%  of 3
 */
function funnelLead(string $status, array $everReached, Organization $org): Lead
{
    $lead = Lead::factory()->create([
        'organization_id' => $org->id,
        'status' => LeadStatus::from($status),
    ]);

    // Write the banked history directly: the observer only records a stage
    // once the lead leaves it, and this fixture describes finished journeys.
    Lead::withoutGlobalScopes()->whereKey($lead->id)->update([
        'stage_durations' => json_encode(array_fill_keys($everReached, 3600)),
    ]);

    return $lead->fresh();
}

beforeEach(function () {
    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $this->org = Organization::factory()->create();

    // 2 never contacted
    funnelLead('new', [], $this->org);
    funnelLead('new', [], $this->org);

    // 2 contacted then lost
    funnelLead('lost', ['new', 'contacted'], $this->org);
    funnelLead('lost', ['new', 'contacted'], $this->org);

    // 2 demoed, still open
    funnelLead('demo_scheduled', ['new', 'contacted'], $this->org);
    funnelLead('demo_completed', ['new', 'contacted', 'demo_scheduled'], $this->org);

    // 1 trialled then lost
    funnelLead('lost', ['new', 'contacted', 'demo_scheduled', 'trial_active'], $this->org);

    // 2 quoted, still open
    funnelLead('quotation_sent', ['new', 'contacted', 'demo_scheduled', 'trial_active'], $this->org);
    funnelLead('quotation_approved', ['new', 'contacted', 'demo_completed', 'trial_active', 'quotation_sent'], $this->org);

    // 1 won
    funnelLead('won', ['new', 'contacted', 'demo_completed', 'trial_active', 'quotation_sent'], $this->org);
});

function funnelRows(User $admin): array
{
    return collect(app(CrmMetricsService::class)->funnel($admin))
        ->keyBy('stage')
        ->all();
}

it('counts every stage a lead genuinely reached', function () {
    $rows = funnelRows($this->admin);

    expect($rows['Leads']['count'])->toBe(10)
        ->and($rows['Contacted']['count'])->toBe(8)
        ->and($rows['Demo']['count'])->toBe(6)
        ->and($rows['Trial']['count'])->toBe(4)
        ->and($rows['Quotation']['count'])->toBe(3)
        ->and($rows['Won']['count'])->toBe(1);
});

it('asserts every conversion percentage on the fixture', function () {
    // Denominators: Contacted of Leads, Demo of Contacted, Trial of Demo,
    // Quotation of Demo (a trial is optional), Won of Quotation.
    $rows = funnelRows($this->admin);

    expect($rows['Leads']['conversion'])->toBe(100.0)
        ->and($rows['Contacted']['conversion'])->toBe(80.0)   // 8 of 10
        ->and($rows['Demo']['conversion'])->toBe(75.0)        // 6 of 8
        ->and($rows['Trial']['conversion'])->toBe(66.7)       // 4 of 6
        ->and($rows['Quotation']['conversion'])->toBe(50.0)   // 3 of 6, not of 4
        ->and($rows['Won']['conversion'])->toBe(33.3);        // 1 of 3
});

it('names the step each conversion is measured against', function () {
    $rows = funnelRows($this->admin);

    expect($rows['Demo']['of'])->toBe('Contacted')
        ->and($rows['Trial']['of'])->toBe('Demo')
        ->and($rows['Quotation']['of'])->toBe('Demo')
        ->and($rows['Won']['of'])->toBe('Quotation');
});

it('handles a pipeline where trials are skipped entirely', function () {
    // The real database exposed this: deals go Demo -> Quotation without a
    // trial, so measuring Quotation against Trial produced conversions above
    // 100% and a funnel that widened as it descended.
    Lead::query()->forceDelete();
    $org = Organization::factory()->create();

    funnelLead('contacted', ['new'], $org);
    funnelLead('quotation_sent', ['new', 'contacted', 'demo_completed'], $org);
    funnelLead('quotation_sent', ['new', 'contacted', 'demo_completed'], $org);

    $rows = funnelRows($this->admin);

    expect($rows['Trial']['count'])->toBe(0)
        ->and($rows['Quotation']['count'])->toBe(2)
        ->and($rows['Quotation']['conversion'])->toBe(100.0)
        ->and($rows['Trial']['conversion'])->toBe(0.0);
});

it('keeps lost deals in the history of the stages they reached', function () {
    // The three lost deals are why Contacted is 8 and not 6: dropping them
    // would understate every step, which is the defect this replaces.
    $rows = funnelRows($this->admin);

    expect($rows['Contacted']['count'])->toBe(8)
        ->and($rows['Trial']['count'])->toBe(4);
});

it('never lets a step exceed the step it is measured against', function () {
    // Not the row above — Trial is an optional branch, so Quotation can and
    // does exceed it. Each step must not exceed its own denominator.
    $rows = collect(app(CrmMetricsService::class)->funnel($this->admin))->keyBy('stage');

    foreach ($rows as $stage => $row) {
        if ($stage === 'Leads') {
            continue;
        }

        expect($row['count'])->toBeLessThanOrEqual($rows[$row['of']]['count']);
    }
});

it('never reports a conversion above 100 percent', function () {
    foreach (app(CrmMetricsService::class)->funnel($this->admin) as $row) {
        if ($row['conversion'] !== null) {
            expect($row['conversion'])->toBeLessThanOrEqual(100.0);
        }
    }
});

it('returns null conversions rather than zero when there is no population', function () {
    $emptyAdmin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    Lead::query()->forceDelete();

    $rows = collect(app(CrmMetricsService::class)->funnel($emptyAdmin))->keyBy('stage');

    expect($rows['Leads']['count'])->toBe(0)
        ->and($rows['Leads']['conversion'])->toBeNull()
        ->and($rows['Contacted']['conversion'])->toBeNull()
        ->and($rows['Won']['conversion'])->toBeNull();
});

it('counts a won deal in every stage it passed through', function () {
    $rows = funnelRows($this->admin);

    // The single won deal is inside Contacted, Demo, Trial and Quotation too.
    expect($rows['Won']['count'])->toBe(1)
        ->and($rows['Quotation']['count'])->toBeGreaterThanOrEqual($rows['Won']['count']);
});
