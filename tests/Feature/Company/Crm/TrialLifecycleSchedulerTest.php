<?php

use App\Jobs\ProcessTrialLifecycleRemindersJob;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\TrialLifecycleService;
use App\Services\LeadWorkspaceService;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\TrialStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Phase 0 regression suite for trial lifecycle automation.
 *
 * checkAndGenerateTrialReminders() was fully implemented but had no caller, so
 * trials expired with no notice to the rep or the client. These tests pin both
 * the scheduler registration and the behaviour it drives.
 */
/**
 * A provisioned trial ending on a given date.
 *
 * The status is derived from the dates now, and derivation starts by asking
 * whether the customer has anything to log in to — so a trial with no clinic
 * reads as `preparing` however its dates look. These fixtures therefore have to
 * be real tenants, which is also closer to what production holds.
 */
function provisionedTrialEnding(User $rep, Carbon $endDate): Trial
{
    $org = Organization::factory()->create();
    $clinic = Clinic::factory()->create(['organization_id' => $org->id]);

    $lead = Lead::factory()->create([
        'status' => LeadStatus::TrialActive,
        'assigned_user_id' => $rep->id,
    ]);

    return Trial::create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $rep->id,
        'plan' => 'Starter',
        'duration_days' => 14,
        'start_date' => $endDate->copy()->subDays(14),
        'end_date' => $endDate,
        'grace_ends_at' => $endDate->copy()->endOfDay()->addDays(7),
        'customer_organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'provisioned_at' => $endDate->copy()->subDays(14),
        'status' => TrialStatus::Active,
    ]);
}

it('registers the trial lifecycle job on the scheduler', function () {
    $events = app(Schedule::class)->events();

    $summaries = collect($events)->map(fn ($event) => $event->getSummaryForDisplay())->implode(' | ');

    expect($summaries)->toContain(ProcessTrialLifecycleRemindersJob::class);
});

it('holds a recently ended trial in grace, and expires one whose grace is spent', function () {
    /*
     * This used to assert that any past end date meant Expired. It does not any
     * more, and that is the point of the grace period: the seven days after the
     * end date are full access, and they are when the decision actually gets
     * made. Both boundaries are pinned here so neither can drift.
     */
    $rep = User::factory()->companyUser()->create();

    $inGrace = provisionedTrialEnding($rep, now()->subDays(3));
    $lapsed = provisionedTrialEnding($rep, now()->subDays(10));

    app(ProcessTrialLifecycleRemindersJob::class)->handle(app(TrialLifecycleService::class));

    expect($inGrace->fresh()->status)->toBe(TrialStatus::InGrace)
        ->and($lapsed->fresh()->status)->toBe(TrialStatus::Expired);
});

it('leaves a trial that is still running untouched', function () {
    $rep = User::factory()->companyUser()->create();
    $trial = provisionedTrialEnding($rep, now()->addDays(10));

    app(ProcessTrialLifecycleRemindersJob::class)->handle(app(TrialLifecycleService::class));

    expect($trial->fresh()->status)->toBe(TrialStatus::Active);
});

it('does not raise duplicate rep notifications when run twice', function () {
    $org = Organization::factory()->create();
    $rep = User::factory()->companyUser()->create(['organization_id' => $org->id]);
    $lead = Lead::factory()->create([
        'status' => LeadStatus::TrialActive,
        'assigned_user_id' => $rep->id,
        'organization_id' => $org->id,
    ]);

    Trial::create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $rep->id,
        'plan' => 'Starter',
        'duration_days' => 14,
        'start_date' => now()->subDays(13),
        'end_date' => now()->addDay(),
        'status' => TrialStatus::Active,
    ]);

    $service = app(LeadWorkspaceService::class);
    app(ProcessTrialLifecycleRemindersJob::class)->handle(app(TrialLifecycleService::class));
    $countAfterFirstRun = EnterpriseNotification::count();

    app(ProcessTrialLifecycleRemindersJob::class)->handle(app(TrialLifecycleService::class));

    expect(EnterpriseNotification::count())->toBe($countAfterFirstRun);
});
