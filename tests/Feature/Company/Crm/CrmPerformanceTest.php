<?php

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\Trial;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use App\TaskStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — performance characteristics of the new management surfaces.
 *
 * These assert *shape*, not speed: that query count stays flat as the data set
 * grows, and that no list ships unbounded to the client. A wall-clock
 * assertion would be flaky; a query-count assertion catches the actual defect,
 * which is a loop issuing one query per row.
 */
beforeEach(function () {
    setPermissionsTeamId(0);
    Role::firstOrCreate(['name' => 'CRM Manager', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Sales Representative', 'guard_name' => 'web']);
    grantCrmManagerPermissionsToRole(Role::findByName('CRM Manager', 'web'));
    grantCrmPermissionsToRole(Role::findByName('Sales Representative', 'web'));

    $this->org = Organization::factory()->create();

    $this->manager = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $this->manager->assignRole('CRM Manager');
});

/**
 * Run a request and return how many queries it issued.
 */
function queryCountFor(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Populate the organization with $repCount reps each holding $leadsPerRep leads
 * plus related demos, trials, quotations and tasks.
 *
 * @return list<User>
 */
function seedSalesData(Organization $org, int $repCount, int $leadsPerRep): array
{
    $reps = [];

    for ($r = 0; $r < $repCount; $r++) {
        $rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $org->id]);
        $rep->assignRole('Sales Representative');
        $reps[] = $rep;

        for ($l = 0; $l < $leadsPerRep; $l++) {
            $lead = Lead::factory()->create([
                'organization_id' => $org->id,
                'assigned_user_id' => $rep->id,
                'status' => LeadStatus::Contacted,
                'expected_mrr' => 5000,
                'probability_to_close' => 50,
                'stage_entered_at' => now()->subDays(5),
                'stage_durations' => ['contacted' => 3 * 86400],
            ]);

            Demo::factory()->create(['lead_id' => $lead->id, 'scheduled_at' => now()->addDay()]);
            Trial::factory()->create(['lead_id' => $lead->id, 'status' => 'active', 'end_date' => now()->addDays(5)]);
            CompanyQuotation::factory()->create(['lead_id' => $lead->id, 'sent_at' => now()]);
            Task::factory()->create([
                'assigned_user_id' => $rep->id,
                'status' => TaskStatus::Pending,
                'due_at' => now()->subDays(2),
            ]);
        }
    }

    return $reps;
}

it('keeps the CRM overview query count flat as reps and leads grow', function () {
    seedSalesData($this->org, 2, 2);

    $small = queryCountFor(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.overview'))
        ->assertSuccessful());

    seedSalesData($this->org, 6, 4);

    $large = queryCountFor(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.overview'))
        ->assertSuccessful());

    // Team performance previously issued roughly seven queries per rep, so an
    // extra six reps would have added around forty queries.
    expect($large - $small)->toBeLessThanOrEqual(3)
        ->and($large)->toBeLessThan(60);
});

it('keeps the sales teams index query count flat as teams and members grow', function () {
    $reps = seedSalesData($this->org, 4, 2);

    $team = Team::create([
        'organization_id' => $this->org->id,
        'name' => 'Team One',
        'key' => 'team-one-'.uniqid(),
        'assignment_strategy' => Team::STRATEGY_ROUND_ROBIN,
    ]);
    $team->users()->attach([$reps[0]->id, $reps[1]->id]);

    $small = queryCountFor(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.teams.index'))
        ->assertSuccessful());

    for ($i = 2; $i <= 5; $i++) {
        $extra = Team::create([
            'organization_id' => $this->org->id,
            'name' => "Team {$i}",
            'key' => "team-{$i}-".uniqid(),
            'assignment_strategy' => Team::STRATEGY_ROUND_ROBIN,
        ]);
        $extra->users()->attach([$reps[2]->id, $reps[3]->id]);
    }

    $large = queryCountFor(fn () => $this->actingAs($this->manager)
        ->get(route('company.crm.teams.index'))
        ->assertSuccessful());

    expect($large - $small)->toBeLessThanOrEqual(2);
});

it('keeps the my workspace query count flat as the rep book grows', function () {
    $reps = seedSalesData($this->org, 1, 2);
    $rep = $reps[0];

    $small = queryCountFor(fn () => $this->actingAs($rep)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful());

    for ($i = 0; $i < 12; $i++) {
        $lead = Lead::factory()->create([
            'organization_id' => $this->org->id,
            'assigned_user_id' => $rep->id,
            'status' => LeadStatus::Contacted,
            'next_followup_at' => now()->subDay(),
        ]);
        Demo::factory()->create(['lead_id' => $lead->id, 'scheduled_at' => now()->addDay()]);
    }

    $large = queryCountFor(fn () => $this->actingAs($rep)
        ->get(route('company.crm.my-workspace'))
        ->assertSuccessful());

    expect($large - $small)->toBeLessThanOrEqual(2);
});

it('bounds every my workspace list', function () {
    $rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $rep->assignRole('Sales Representative');

    Lead::factory()->count(120)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Contacted,
    ]);

    $this->actingAs($rep)
        ->get(route('company.crm.my-workspace'))
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            expect(count($props['workload']['assigned_leads']))
                ->toBeLessThanOrEqual(LeadWorkspaceService::WORKSPACE_LIST_LIMIT);
        });
});

it('bounds the deals list however large the pipeline grows', function () {
    $rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    $rep->assignRole('Sales Representative');

    Lead::factory()->count(LeadWorkspaceService::PIPELINE_CARD_LIMIT + 25)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Contacted,
    ]);

    /*
     * The board is gone, and with it the client-side card cap it needed. The
     * list was always server-paginated, which is the stronger guarantee: the
     * page ships one page of rows however large the pipeline grows, and the
     * honest total still comes back.
     */
    $this->actingAs($this->manager)
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful()
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            expect(count($props['deals']['data']))->toBeLessThanOrEqual(25)
                ->and($props['deals']['meta']['total'])->toBe(LeadWorkspaceService::PIPELINE_CARD_LIMIT + 25)
                ->and($props)->not->toHaveKey('board');
        });
});

/*
 * Three feeds have occupied this slot: a generic activity log, then a stage
 * movement feed, now the stalled list. Each was replaced because it reported
 * history rather than work. The boundedness this test guards outlived all
 * three, so the test follows the slot rather than retiring with its occupant.
 */
it('bounds the stalled list on the overview', function () {
    $rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);

    /*
     * Forty neglected deals. The Overview must summarise them, not list them —
     * a dashboard that grows with the size of the problem it is reporting stops
     * being readable exactly when it matters most.
     */
    Lead::factory()->count(40)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $rep->id,
        'status' => LeadStatus::Contacted->value,
        'stage_entered_at' => now()->subDays(60),
    ]);

    /*
     * This replaced the movement-feed bound. That feed listed every stage
     * change in the period and has been removed from the page — it is an audit
     * trail, which belongs on the deal's own timeline — so the guard now covers
     * what took its slot. The property under test is unchanged: an unbounded
     * number of rows must reach the page as a bounded number.
     */
    $this->actingAs($this->manager)
        ->get(route('company.crm.overview'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(['people'], function (Assert $reload) {
                $stalled = $reload->toArray()['props']['stalled'];

                expect(count($stalled['oldest']))->toBeLessThanOrEqual(10)
                    // The total is still the honest figure, uncapped.
                    ->and($stalled['total'])->toBeGreaterThanOrEqual(40);
            })
        );
});
it('bounds the leads directory with server-side pagination', function () {
    $rep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);

    Lead::factory()->count(60)->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $rep->id,
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.crm.deals.index'))
        ->assertSuccessful()
        ->assertInertia(function (Assert $page) {
            expect(count($page->toArray()['props']['deals']['data']))->toBeLessThanOrEqual(25);
        });
});
