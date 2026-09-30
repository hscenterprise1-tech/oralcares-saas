<?php

use App\LeadStatus;
use App\Models\Demo;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\CrmMetricsService;
use App\Services\LeadWorkspaceService;
use App\TaskStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Slice C — forecasting, stage velocity, rep performance and the operational
 * presentations for stale leads and win-back.
 *
 * The recurring rule under test: a metric the data cannot answer is null, never
 * zero. "No cycle-time history yet" and "a cycle time of zero days" mean
 * opposite things to a sales manager.
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

    $this->rep = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->org->id,
        'name' => 'Rhea Rep',
    ]);
    $this->rep->assignRole('Sales Representative');

    $this->metrics = app(CrmMetricsService::class);
});

// ---------------------------------------------------------------- forecasting

it('weights expected revenue by the probability of closing', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'expected_arr' => 200000,
        'expected_mrr' => 0,
        'probability_to_close' => 40,
    ]);

    $forecast = $this->metrics->forecast($this->manager);

    expect($forecast['expected_pipeline'])->toBe(200000.0)
        ->and($forecast['weighted_pipeline'])->toBe(80000.0);
});

it('annualises monthly value when no annual figure is recorded', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'expected_mrr' => 10000,
        'expected_arr' => 0,
        'probability_to_close' => 50,
    ]);

    $forecast = $this->metrics->forecast($this->manager);

    expect($forecast['expected_pipeline'])->toBe(120000.0)
        ->and($forecast['weighted_pipeline'])->toBe(60000.0);
});

it('excludes won and lost deals from the forecast', function () {
    foreach ([LeadStatus::Won, LeadStatus::Lost] as $status) {
        Lead::factory()->create([
            'organization_id' => $this->org->id,
            'assigned_user_id' => $this->rep->id,
            'status' => $status,
            'expected_arr' => 500000,
            'probability_to_close' => 100,
        ]);
    }

    $forecast = $this->metrics->forecast($this->manager);

    expect($forecast['open_deal_count'])->toBe(0)
        ->and($forecast['expected_pipeline'])->toBe(0.0)
        ->and($forecast['weighted_pipeline'])->toBe(0.0);
});

it('breaks the forecast down by stage and by rep', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'expected_arr' => 100000,
        'expected_mrr' => 0,
        'probability_to_close' => 60,
    ]);

    $forecast = $this->metrics->forecast($this->manager);

    expect($forecast['by_stage'])->toHaveCount(1)
        ->and($forecast['by_stage'][0]['stage'])->toBe('negotiation')
        ->and($forecast['by_stage'][0]['weighted_value'])->toBe(60000.0)
        ->and($forecast['by_rep'][0]['name'])->toBe('Rhea Rep')
        ->and($forecast['by_rep'][0]['weighted_value'])->toBe(60000.0);
});

it('groups the forecast by expected go-live period', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'expected_arr' => 100000,
        'probability_to_close' => 50,
        'expected_go_live_date' => '2026-11-15',
    ]);

    $forecast = $this->metrics->forecast($this->manager);

    expect($forecast['by_period'])->toHaveCount(1)
        ->and($forecast['by_period'][0]['period'])->toBe('2026-11')
        ->and($forecast['by_period'][0]['weighted_value'])->toBe(50000.0);
});

it('scopes the forecast to the acting rep own book', function () {
    $otherRep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);

    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'expected_arr' => 100000, 'expected_mrr' => 0, 'probability_to_close' => 100,
    ]);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $otherRep->id,
        'status' => LeadStatus::Negotiation,
        'expected_arr' => 900000, 'expected_mrr' => 0, 'probability_to_close' => 100,
    ]);

    expect($this->metrics->forecast($this->rep)['weighted_pipeline'])->toBe(100000.0)
        ->and($this->metrics->forecast($this->manager)['weighted_pipeline'])->toBe(1000000.0);
});

// ------------------------------------------------------------- stage velocity

it('reports average stage duration from banked stage history', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_durations' => ['contacted' => 2 * 86400, 'demo_completed' => 6 * 86400],
    ]);

    $velocity = $this->metrics->stageVelocity($this->manager);
    $byStage = collect($velocity['stage_averages'])->keyBy('stage');

    expect($byStage['contacted']['average_days'])->toBe(2.0)
        ->and($byStage['demo_completed']['average_days'])->toBe(6.0)
        ->and($byStage['contacted']['sample_size'])->toBe(1);
});

it('names the slowest stage as the bottleneck', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_durations' => ['contacted' => 1 * 86400, 'negotiation' => 21 * 86400],
    ]);

    $velocity = $this->metrics->stageVelocity($this->manager);

    expect($velocity['bottleneck_stage']['stage'])->toBe('negotiation')
        ->and($velocity['bottleneck_stage']['average_days'])->toBe(21.0);
});

it('computes cycle time as the sum of a won deal stage durations', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Won,
        'stage_durations' => ['new' => 86400, 'contacted' => 2 * 86400, 'negotiation' => 4 * 86400],
    ]);

    $velocity = $this->metrics->stageVelocity($this->manager);

    expect($velocity['average_cycle_time_days'])->toBe(7.0)
        ->and($velocity['cycle_time_sample_size'])->toBe(1);
});

it('reports velocity as unavailable rather than zero when no history exists', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Won,
        'stage_durations' => null,
    ]);

    $velocity = $this->metrics->stageVelocity($this->manager);

    expect($velocity['average_cycle_time_days'])->toBeNull()
        ->and($velocity['cycle_time_sample_size'])->toBe(0)
        ->and($velocity['bottleneck_stage'])->toBeNull();
});

it('lists the leads sitting longest in their current stage', function () {
    $stuck = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Negotiation,
        'stage_entered_at' => now()->subDays(45),
    ]);

    $velocity = $this->metrics->stageVelocity($this->manager);

    expect($velocity['longest_in_stage'][0]['id'])->toBe($stuck->id)
        ->and($velocity['longest_in_stage'][0]['stage_age_days'])->toBeGreaterThanOrEqual(45);
});

// ------------------------------------------------------------ rep performance

it('builds rep performance from grouped aggregates', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Won,
        'stage_durations' => ['new' => 5 * 86400],
    ]);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
    ]);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::New,
    ]);

    Demo::factory()->create(['lead_id' => $lead->id, 'status' => 'completed']);
    Demo::factory()->create(['lead_id' => $lead->id, 'status' => 'scheduled']);

    Task::factory()->create([
        'assigned_user_id' => $this->rep->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDays(4),
    ]);

    $row = collect($this->metrics->repPerformance($this->manager))
        ->firstWhere('user_id', $this->rep->id);

    expect($row['leads_assigned'])->toBe(3)
        ->and($row['leads_contacted'])->toBe(2)   // the New lead has not been worked
        ->and($row['demos_scheduled'])->toBe(2)
        ->and($row['demos_completed'])->toBe(1)
        ->and($row['wins'])->toBe(1)
        ->and($row['losses'])->toBe(1)
        ->and($row['conversion_rate'])->toBe(50.0)
        ->and($row['average_cycle_time_days'])->toBe(5.0)
        ->and($row['overdue_followups'])->toBe(1);
});

it('reports an unmeasurable conversion rate as null, not zero', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::New,
    ]);

    $row = collect($this->metrics->repPerformance($this->manager))
        ->firstWhere('user_id', $this->rep->id);

    // Nothing has closed, so there is no rate yet — distinct from a 0% rate.
    expect($row['conversion_rate'])->toBeNull()
        ->and($row['average_cycle_time_days'])->toBeNull()
        ->and($row['cycle_time_sample_size'])->toBe(0);
});

it('limits a rep performance view to themselves', function () {
    $otherRep = User::factory()->create(['user_type' => UserType::CompanyUser, 'organization_id' => $this->org->id]);
    Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $this->rep->id]);
    Lead::factory()->create(['organization_id' => $this->org->id, 'assigned_user_id' => $otherRep->id]);

    $ids = array_column($this->metrics->repPerformance($this->rep), 'user_id');

    expect($ids)->toBe([$this->rep->id]);
});

// -------------------------------------------------------------- stale & winback

it('presents stale leads by owner and by stage without changing any stage', function () {
    config(['crm.stale_lead_days' => 14]);

    $stale = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Contacted,
        'stage_entered_at' => now()->subDays(40),
    ]);

    $report = $this->metrics->staleLeads($this->manager);

    expect($report['total'])->toBe(1)
        ->and($report['threshold_days'])->toBe(14)
        ->and($report['by_owner'][0]['name'])->toBe('Rhea Rep')
        ->and($report['by_stage'][0]['stage'])->toBe('contacted')
        ->and($report['oldest'][0]['id'])->toBe($stale->id);

    // Reporting must never mutate the pipeline.
    expect($stale->fresh()->status)->toBe(LeadStatus::Contacted);
});

it('excludes closed deals from the stale report', function () {
    config(['crm.stale_lead_days' => 14]);

    foreach ([LeadStatus::Won, LeadStatus::Lost] as $status) {
        Lead::factory()->create([
            'organization_id' => $this->org->id,
            'assigned_user_id' => $this->rep->id,
            'status' => $status,
            'stage_entered_at' => now()->subDays(90),
        ]);
    }

    expect($this->metrics->staleLeads($this->manager)['total'])->toBe(0);
});

it('separates scheduled, due and awaiting-execution win-backs', function () {
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->addDays(10),
    ]);
    Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->subDay(),
    ]);

    $winback = $this->metrics->winBack($this->manager);

    expect($winback['scheduled'])->toBe(1)
        ->and($winback['due'])->toBe(1)
        ->and($winback['executed'])->toBe(0)
        // Due but not yet acted on: a stalled job must be visible, not counted
        // as executed.
        ->and($winback['pending_execution'])->toBe(1)
        ->and($winback['upcoming'])->toHaveCount(2);
});

it('counts a win-back as executed once the job has logged it, and idempotently', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->subDay(),
    ]);

    app(LeadWorkspaceService::class)->processDueWinBacks();
    app(LeadWorkspaceService::class)->processDueWinBacks();

    $winback = $this->metrics->winBack($this->manager);

    expect($winback['executed'])->toBe(1)
        ->and($winback['pending_execution'])->toBe(0)
        ->and($winback['lost_again'])->toBe(1)
        ->and($winback['reactivated'])->toBe(0);

    // Running twice must not double-log.
    expect($lead->activities()->where('description', 'like', 'Win-back re-engagement due%')->count())->toBe(1);
});

it('counts a reactivated lead separately once it leaves the lost stage', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->org->id,
        'assigned_user_id' => $this->rep->id,
        'status' => LeadStatus::Lost,
        'winback_scheduled_at' => now()->subDay(),
    ]);

    app(LeadWorkspaceService::class)->processDueWinBacks();
    $lead->update(['status' => LeadStatus::Negotiation]);

    $winback = $this->metrics->winBack($this->manager);

    expect($winback['reactivated'])->toBe(1)
        ->and($winback['lost_again'])->toBe(0);
});
