<?php

use App\LeadStatus;
use App\Models\CompanyPlan;
use App\Models\Lead;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\TrialHealthService;
use App\Services\CRM\TrialPageService;
use App\Services\CrmMetricsService;
use App\Services\CustomerProvisioningEngine;
use App\Services\Provisioning\ProvisioningIntent;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase 3 — centralized Trial Health.
 *
 * Before this suite, "healthy" / "at risk" / "expiring" were computed twice:
 * once in `TrialPageService::healthCounts()` for the Trials watchtower, once
 * in `CrmMetricsService::liveTrialHealth()` for the Dashboard's KPI cards. The
 * thresholds agreed, but the *population* did not — the watchtower excluded a
 * trial the moment its lifecycle-derived phase closed, while the dashboard
 * kept counting it until the raw `status` column caught up on the next
 * scheduler run, and "expiring" was measured against two different clocks.
 * `TrialHealthService::aggregateFromRows()` is now the one place both compute
 * their buckets, so the same trial cannot read differently on the two pages.
 */
function healthPlan(): CompanyPlan
{
    return CompanyPlan::firstWhere('key', 'growth') ?? CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ]);
}

/** A provisioned, running trial ending 14 days from today. */
function healthTrial(array $overrides = []): Trial
{
    $lead = Lead::factory()->create([
        'status' => LeadStatus::DemoCompleted->value,
        'assigned_user_id' => User::factory()->companyUser()->create()->id,
    ]);

    $trial = Trial::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'assigned_user_id' => $lead->assigned_user_id,
        'company_plan_id' => healthPlan()->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'duration_days' => 14,
    ], $overrides));

    app(TrialProvisioningService::class)->provision($trial);

    return $trial->fresh();
}

/* ── Pure aggregation — bucket boundaries ────────────────────────────────── */

it('buckets a live trial as healthy at exactly the prescription threshold', function () {
    $rows = collect([
        ['phase' => 'active', 'lost' => null, 'telemetry' => ['prescriptions' => 3, 'is_inactive' => false], 'windowState' => 'trial', 'daysRemaining' => 10],
        ['phase' => 'active', 'lost' => null, 'telemetry' => ['prescriptions' => 2, 'is_inactive' => false], 'windowState' => 'trial', 'daysRemaining' => 10],
    ]);

    $counts = app(TrialHealthService::class)->aggregateFromRows($rows);

    expect($counts['active'])->toBe(2)
        ->and($counts['healthy'])->toBe(1);
});

it('buckets a live trial as at risk from its telemetry inactivity flag alone', function () {
    $rows = collect([
        ['phase' => 'active', 'lost' => null, 'telemetry' => ['prescriptions' => 5, 'is_inactive' => true], 'windowState' => 'trial', 'daysRemaining' => 10],
    ]);

    expect(app(TrialHealthService::class)->aggregateFromRows($rows)['at_risk'])->toBe(1);
});

it('buckets expiring only inside the two-day window and only while the window state is trial', function () {
    $rows = collect([
        ['phase' => 'ending_soon', 'lost' => null, 'telemetry' => null, 'windowState' => 'trial', 'daysRemaining' => 2],
        ['phase' => 'ending_soon', 'lost' => null, 'telemetry' => null, 'windowState' => 'trial', 'daysRemaining' => 3],
        // A won deal's paid term: inside the day cutoff, but not a trial window.
        ['phase' => 'active', 'lost' => null, 'telemetry' => null, 'windowState' => 'active', 'daysRemaining' => 1],
    ]);

    expect(app(TrialHealthService::class)->aggregateFromRows($rows)['expiring_48h'])->toBe(1);
});

it('excludes closed and lost trials from every bucket', function () {
    $rows = collect([
        ['phase' => 'expired', 'lost' => null, 'telemetry' => ['prescriptions' => 10, 'is_inactive' => false], 'windowState' => 'none', 'daysRemaining' => null],
        ['phase' => 'active', 'lost' => now(), 'telemetry' => ['prescriptions' => 10, 'is_inactive' => false], 'windowState' => 'trial', 'daysRemaining' => 1],
    ]);

    expect(app(TrialHealthService::class)->aggregateFromRows($rows))
        ->toBe(['active' => 0, 'healthy' => 0, 'at_risk' => 0, 'expiring_48h' => 0, 'unmeasured' => 0]);
});

it('buckets a live trial with no telemetry yet as unmeasured, not unhealthy', function () {
    $rows = collect([
        ['phase' => 'active', 'lost' => null, 'telemetry' => null, 'windowState' => 'trial', 'daysRemaining' => 10],
    ]);

    $counts = app(TrialHealthService::class)->aggregateFromRows($rows);

    expect($counts['unmeasured'])->toBe(1)
        ->and($counts['healthy'])->toBe(0)
        ->and($counts['at_risk'])->toBe(0);
});

/* ── Cross-surface consistency ───────────────────────────────────────────── */

it('agrees between the Trials watchtower and the Dashboard KPI cards for the same trials', function () {
    $rep = User::factory()->companyUser()->create();

    $healthy = healthTrial(['assigned_user_id' => $rep->id]);
    DB::table('trials')->where('id', $healthy->id)->update([
        'usage_telemetry' => json_encode(['prescriptions' => 5, 'is_inactive' => false]),
    ]);

    $atRisk = healthTrial(['assigned_user_id' => $rep->id]);
    DB::table('trials')->where('id', $atRisk->id)->update([
        'usage_telemetry' => json_encode(['prescriptions' => 0, 'is_inactive' => true]),
    ]);

    $watchtower = app(TrialPageService::class)->listPayload($rep)['health'];
    $dashboard = app(CrmMetricsService::class)->kpis($rep);

    expect($dashboard['trials_healthy'])->toBe($watchtower['healthy'])
        ->and($dashboard['trials_at_risk'])->toBe($watchtower['at_risk'])
        ->and($dashboard['trials_expiring_48h'])->toBe($watchtower['expiring_48h'])
        ->and($watchtower['healthy'])->toBeGreaterThanOrEqual(1)
        ->and($watchtower['at_risk'])->toBeGreaterThanOrEqual(1);
});

/**
 * The bug this service closes: a trial's raw `status` column can still read
 * Active days after its end date and grace period have both passed, because
 * nothing writes that column except the nightly scheduler. The dashboard used
 * to trust that stale column directly; the watchtower always derived the
 * phase fresh. Now both derive it fresh, so a trial that has actually expired
 * cannot still show up as "active" on one page and not the other.
 */
it('agrees a trial has expired even while its stored status column is stale', function () {
    $trial = healthTrial([
        'start_date' => now()->subDays(40)->toDateString(),
        'end_date' => now()->subDays(26)->toDateString(),
    ]);

    // Simulate the scheduler never having run: the stored column still says
    // Active, though the dates say the trial (and its grace period) are long over.
    DB::table('trials')->where('id', $trial->id)->update(['status' => TrialStatus::Active->value]);

    $rep = User::find($trial->assigned_user_id);

    $watchtower = app(TrialPageService::class)->listPayload($rep)['health'];
    $dashboard = app(CrmMetricsService::class)->kpis($rep);

    expect($watchtower['active'])->toBe(0)
        ->and($dashboard['trials_healthy'])->toBe(0);
});

/**
 * A won deal's trial keeps running dates in the background (nothing deletes
 * it), but its access now comes from a paid subscription window, not a trial
 * window. Counting it as "expiring" would tell a rep to go chase a renewal
 * that already happened.
 */
it('does not count a won deal\'s trial as expiring on either surface', function () {
    $trial = healthTrial([
        'end_date' => now()->addDay()->toDateString(),
    ]);

    app(CustomerProvisioningEngine::class)->provision(
        $trial->lead->fresh(),
        'Main Branch',
        ProvisioningIntent::sale(),
    );

    $rep = User::find($trial->assigned_user_id);

    $watchtower = app(TrialPageService::class)->listPayload($rep)['health'];
    $dashboard = app(CrmMetricsService::class)->kpis($rep);

    expect($watchtower['expiring_48h'])->toBe(0)
        ->and($dashboard['trials_expiring_48h'])->toBe(0);
});

it('keeps tenant isolation on the centralized health counts', function () {
    healthTrial();
    $otherRep = User::factory()->companyUser()->create();

    $dashboard = app(CrmMetricsService::class)->kpis($otherRep);
    $watchtower = app(TrialPageService::class)->listPayload($otherRep)['health'];

    expect($dashboard['active_trials'])->toBe(0)
        ->and($watchtower['active'])->toBe(0);
});
