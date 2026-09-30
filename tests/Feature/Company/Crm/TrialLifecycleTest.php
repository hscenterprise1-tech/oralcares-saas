<?php

use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\SubscriptionEvent;
use App\Models\Task;
use App\Models\Trial;
use App\Models\User;
use App\Services\CRM\TrialLifecycleService;
use App\Services\CustomerProvisioningEngine;
use App\Services\LeadWorkspaceService;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Provisioning\ProvisioningIntent;
use App\Services\Provisioning\TrialProvisioningService;
use App\SubscriptionStatus;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase 3 — the lifecycle, boundary by boundary.
 *
 * Every test here fixes a point in time relative to the trial's end date and
 * asks what the product should be doing on that day. The offsets are the ones
 * that matter commercially: the warnings before the end, the end itself, and
 * each day of the grace period that follows it.
 */
function lifecyclePlan(): CompanyPlan
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
function runningTrial(array $overrides = []): Trial
{
    $lead = Lead::factory()->create([
        'company_name' => 'Kalyani Multispeciality',
        'contact_name' => 'Ravi Kulkarni',
        'email' => 'ravi-'.uniqid().'@kalyani.test',
        'phone' => '919800000000',
        'status' => LeadStatus::DemoCompleted->value,
        'assigned_user_id' => User::factory()->companyUser()->create()->id,
    ]);

    $trial = Trial::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'assigned_user_id' => $lead->assigned_user_id,
        'company_plan_id' => lifecyclePlan()->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'duration_days' => 14,
    ], $overrides));

    app(TrialProvisioningService::class)->provision($trial);

    return $trial->fresh();
}

/** Travel to a day offset from the trial's end date. */
function atOffset(Trial $trial, int $days): Carbon
{
    return $trial->end_date->copy()->addDays($days)->setTime(9, 0);
}

/* -------------------------------------------------------- status derivation */

it('derives status from the dates rather than from what anybody last clicked', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    expect($lifecycle->resolveStatus($trial, atOffset($trial, -10)))->toBe(TrialStatus::Active)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, -3)))->toBe(TrialStatus::EndingSoon)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, -1)))->toBe(TrialStatus::EndingSoon)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, 1)))->toBe(TrialStatus::InGrace)
        /* Day 0 is the last day of the trial itself, not the first day of grace. */
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, 0)))->toBe(TrialStatus::EndingSoon)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, 6)))->toBe(TrialStatus::InGrace)
        /* Seven full days of grace means +7 is the last one, not the first one gone. */
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, 7)))->toBe(TrialStatus::InGrace)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, 8)))->toBe(TrialStatus::Expired)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, 30)))->toBe(TrialStatus::Expired);
});

it('reports an unprovisioned trial as preparing whatever its dates say', function () {
    $lead = Lead::factory()->create();
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => TrialStatus::Active,
        'start_date' => now()->subDays(20)->toDateString(),
        'end_date' => now()->subDays(6)->toDateString(),
    ]);

    /* Dates say grace; there is nothing to log in to, so it says preparing. */
    expect(app(TrialLifecycleService::class)->resolveStatus($trial))->toBe(TrialStatus::Preparing);
});

it('shows an extended trial as extended, then as ending soon as the new date nears', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    $trial = $lifecycle->extend($trial, 7, 'Client wants another week on EMR.');

    expect($lifecycle->resolveStatus($trial, atOffset($trial, -7)))->toBe(TrialStatus::Extended)
        ->and($lifecycle->resolveStatus($trial, atOffset($trial, -2)))->toBe(TrialStatus::EndingSoon);
});

/* ------------------------------------------------------------- -3 boundary */

it('warns the client and the rep three days out', function () {
    $trial = runningTrial();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, -3));

    expect($result['fired'])->toBe(['pre_3'])
        ->and($result['status'])->toBe(TrialStatus::EndingSoon);
});

it('fires nothing four days out', function () {
    $trial = runningTrial();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, -4));

    expect($result['fired'])->toBe([])
        ->and($result['status'])->toBe(TrialStatus::Active);
});

/* ------------------------------------------------------------- -1 boundary */

it('raises a call task the day before the trial ends', function () {
    $trial = runningTrial();
    $before = Task::count();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, -1));

    expect($result['fired'])->toBe(['pre_3', 'pre_1'])
        ->and(Task::count())->toBeGreaterThan($before);
});

/* -------------------------------------------------------------- day 0 */

it('tells the client on the end date that grace has started, without cutting access off', function () {
    $trial = runningTrial();

    Carbon::setTestNow(atOffset($trial, 0));
    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, 0));

    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();
    $entitlement = app(EntitlementResolver::class)->fromSubscription($subscription);
    Carbon::setTestNow();

    /*
     * The end date is still a trial day — the customer has it in full. What
     * fires today is the message telling them what happens tomorrow.
     */
    expect($result['status'])->toBe(TrialStatus::EndingSoon)
        ->and($result['fired'])->toContain('end_day')
        ->and($entitlement['grants_access'])->toBeTrue()
        ->and($entitlement['window']['state'])->toBe('trial');
});

/* ------------------------------------------------- grace access, +3 and +6 */

it('grants full access on every day of the grace period', function () {
    $trial = runningTrial();
    $resolver = app(EntitlementResolver::class);
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    foreach ([1, 3, 6, 7] as $offset) {
        Carbon::setTestNow(atOffset($trial, $offset));

        $entitlement = $resolver->fromSubscription($subscription->fresh());

        expect($entitlement['grants_access'])->toBeTrue("day +{$offset} should still have access")
            ->and($entitlement['window']['state'])->toBe('grace');
    }

    Carbon::setTestNow();
});

it('nudges the rep at the halfway point of grace', function () {
    $trial = runningTrial();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, 3));

    expect($result['fired'])->toContain('grace_3')
        ->and($result['fired'])->not->toContain('grace_6')
        ->and($result['status'])->toBe(TrialStatus::InGrace);
});

it('escalates on the last full day of access', function () {
    $trial = runningTrial();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, 6));

    expect($result['fired'])->toContain('grace_6')
        ->and($result['status'])->toBe(TrialStatus::InGrace);
});

/* ------------------------------------------------------- +7: post-grace */

it('expires the trial and blocks access once grace is spent', function () {
    $trial = runningTrial();
    $resolver = app(EntitlementResolver::class);
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, 8));

    Carbon::setTestNow(atOffset($trial, 8));
    $entitlement = $resolver->fromSubscription($subscription->fresh());
    Carbon::setTestNow();

    expect($result['status'])->toBe(TrialStatus::Expired)
        ->and($result['fired'])->toContain('lapsed')
        ->and($entitlement['grants_access'])->toBeFalse()
        ->and($entitlement['window']['state'])->toBe('lapsed');
});

it('still has access on the last day of grace', function () {
    /*
     * The boundary most likely to be got wrong by one day, and the one where
     * getting it wrong costs a sale: this is the day the customer is most
     * likely to be deciding.
     */
    $trial = runningTrial();
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, 7));

    Carbon::setTestNow(atOffset($trial, 7));
    $entitlement = app(EntitlementResolver::class)->fromSubscription($subscription->fresh());
    Carbon::setTestNow();

    expect($result['status'])->toBe(TrialStatus::InGrace)
        ->and($result['fired'])->not->toContain('lapsed')
        ->and($entitlement['grants_access'])->toBeTrue();
});

/* --------------------------------------------------- catch-up + idempotency */

it('fires every milestone it owes when the scheduler misses a week', function () {
    /*
     * The failure the old `isTomorrow()` check had: a run that did not happen
     * on the day skipped that milestone forever. A single late run must now
     * deliver everything it missed.
     */
    $trial = runningTrial();

    $result = app(TrialLifecycleService::class)->sync($trial, atOffset($trial, 8));

    expect($result['fired'])->toBe(['pre_3', 'pre_1', 'end_day', 'grace_3', 'grace_6', 'lapsed'])
        ->and($result['status'])->toBe(TrialStatus::Expired);
});

it('does nothing on a second run the same day', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    $first = $lifecycle->sync($trial, atOffset($trial, 0));
    $tasks = Task::count();

    $second = $lifecycle->sync($trial->fresh(), atOffset($trial, 0));

    expect($first['fired'])->not->toBeEmpty()
        ->and($second['fired'])->toBe([])
        ->and($second['changed'])->toBeFalse()
        ->and(Task::count())->toBe($tasks);
});

it('does not re-send milestones already delivered when it runs again later', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    $lifecycle->sync($trial, atOffset($trial, -1));
    $later = $lifecycle->sync($trial->fresh(), atOffset($trial, 3));

    /* The two it already sent are not repeated. */
    expect($later['fired'])->toBe(['end_day', 'grace_3']);
});

/* ------------------------------------------------------------- extension */

it('pushes the subscription window out when a trial is extended', function () {
    /*
     * Extending used to move `trials.end_date` alone, so the customer was
     * promised another week and locked out on the original date.
     */
    $trial = runningTrial();
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();
    $originalEnd = $subscription->trial_ends_at->copy();

    app(TrialLifecycleService::class)->extend($trial, 7, 'Client wants more time.');

    $subscription->refresh();
    $trial->refresh();

    expect($subscription->trial_ends_at->toDateString())->toBe($originalEnd->addDays(7)->toDateString())
        ->and($subscription->grace_ends_at->toDateString())->toBe($trial->end_date->copy()->addDays(7)->toDateString())
        ->and($subscription->ends_at->toDateString())->toBe($subscription->grace_ends_at->toDateString())
        ->and($trial->extension_count)->toBe(1)
        ->and($trial->status)->toBe(TrialStatus::Extended);
});

it('gives an extended trial its warnings again on the new schedule', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    $lifecycle->sync($trial, atOffset($trial, 0));
    expect($trial->fresh()->lifecycle_events)->toHaveKey('end_day');

    $trial = $lifecycle->extend($trial->fresh(), 7, 'One more week.');

    /* Pre-end warnings already received stay received; the rest reset. */
    expect($trial->lifecycle_events)->toHaveKey('pre_3')
        ->and($trial->lifecycle_events)->not->toHaveKey('end_day');

    $result = $lifecycle->sync($trial, atOffset($trial, 0));

    expect($result['fired'])->toContain('end_day');
});

it('logs the extension against the subscription', function () {
    $trial = runningTrial();

    app(TrialLifecycleService::class)->extend($trial, 5, 'Awaiting board sign-off.');

    $event = SubscriptionEvent::where('event', SubscriptionEvent::TRIAL_EXTENDED)->first();

    expect($event)->not->toBeNull()
        ->and($event->reason)->toBe('Awaiting board sign-off.')
        ->and($event->lead_id)->toBe($trial->lead_id);
});

it('refuses to extend a trial that has already been closed', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    $lifecycle->cancel($trial, 'Duplicate account.');

    expect(fn () => $lifecycle->extend($trial->fresh(), 7, 'Changed our mind.'))
        ->toThrow(InvalidArgumentException::class, 'cannot be extended');
});

it('moves the dates but not the status when an unprovisioned trial is extended', function () {
    /*
     * Extending is allowed before an environment exists — a rep tidying dates
     * ahead of a retry is reasonable, and the subscription move simply has
     * nothing to act on. What must not happen is the trial claiming to be
     * extended and running when there is still nothing to log in to.
     */
    $lead = Lead::factory()->create();
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
    ]);

    $extended = app(TrialLifecycleService::class)->extend($trial, 7, 'Waiting on provisioning.');

    expect($extended->end_date->toDateString())->toBe(now()->addDays(21)->toDateString())
        ->and($extended->status)->toBe(TrialStatus::Preparing);
});

/* ---------------------------------------------------------- cancellation */

it('revokes access immediately when a trial is cancelled', function () {
    $trial = runningTrial();
    $resolver = app(EntitlementResolver::class);

    app(TrialLifecycleService::class)->cancel($trial, 'Duplicate account.');

    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();
    $entitlement = $resolver->fromSubscription($subscription);

    expect($trial->fresh()->status)->toBe(TrialStatus::Cancelled)
        ->and($trial->fresh()->cancellation_reason)->toBe('Duplicate account.')
        ->and($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($entitlement['grants_access'])->toBeFalse();
});

it('keeps the tenant and its data when a trial is cancelled', function () {
    /*
     * Revoking access is reversible; deleting a clinic somebody has entered
     * patients into is not. That decision belongs to a retention policy.
     */
    $trial = runningTrial();
    $clinicId = $trial->clinic_id;

    app(TrialLifecycleService::class)->cancel($trial, 'Prospect asked to be removed.');

    expect(Clinic::find($clinicId))->not->toBeNull()
        ->and(DB::table('clinic_operating_hours')->where('clinic_id', $clinicId)->count())->toBe(7);
});

it('leaves a cancelled trial alone on later scheduler runs', function () {
    $trial = runningTrial();
    $lifecycle = app(TrialLifecycleService::class);

    $lifecycle->cancel($trial, 'Duplicate account.');
    $result = $lifecycle->sync($trial->fresh(), atOffset($trial, 10));

    expect($result['skipped'])->toBe('trial_closed')
        ->and($result['fired'])->toBe([])
        ->and($trial->fresh()->status)->toBe(TrialStatus::Cancelled);
});

/* ------------------------------------------------------- Won protection */

it('never expires the trial of a won deal', function () {
    $trial = runningTrial();

    app(CustomerProvisioningEngine::class)->provision(
        $trial->lead->fresh(),
        'Main Branch',
        ProvisioningIntent::sale(),
    );

    $result = app(TrialLifecycleService::class)->sync($trial->fresh(), atOffset($trial, 30));

    expect($result['skipped'])->toBe('deal_won')
        ->and($trial->fresh()->status)->not->toBe(TrialStatus::Expired);
});

it('keeps a won customer access long after the trial dates have passed', function () {
    $trial = runningTrial();

    app(CustomerProvisioningEngine::class)->provision(
        $trial->lead->fresh(),
        'Main Branch',
        ProvisioningIntent::sale(),
    );

    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    Carbon::setTestNow(atOffset($trial, 60));
    $entitlement = app(EntitlementResolver::class)->fromSubscription($subscription);
    Carbon::setTestNow();

    expect($entitlement['grants_access'])->toBeTrue()
        ->and($entitlement['window']['state'])->toBe('paid');
});

it('refuses to cancel the trial of a won deal', function () {
    $trial = runningTrial();

    app(CustomerProvisioningEngine::class)->provision(
        $trial->lead->fresh(),
        'Main Branch',
        ProvisioningIntent::sale(),
    );

    expect(fn () => app(TrialLifecycleService::class)->cancel($trial->fresh(), 'Oops.'))
        ->toThrow(InvalidArgumentException::class, 'deal has been won');
});

it('does not drag a won deal back out of won when its trial is completed', function () {
    /*
     * `completeTrial()` fell back to moving the lead to TrialActive, so
     * recording an outcome on a won deal reopened it in the pipeline.
     */
    $trial = runningTrial();

    app(CustomerProvisioningEngine::class)->provision(
        $trial->lead->fresh(),
        'Main Branch',
        ProvisioningIntent::sale(),
    );

    app(LeadWorkspaceService::class)->completeTrial($trial->fresh(), [
        'outcome' => 'interested',
        'feedback' => 'Went well.',
    ]);

    expect($trial->lead->fresh()->status)->toBe(LeadStatus::Won)
        ->and($trial->fresh()->feedback)->toBe('Went well.');
});

it('leaves a lost deal trial alone', function () {
    $trial = runningTrial();
    $trial->lead->update(['status' => LeadStatus::Lost->value]);

    $result = app(TrialLifecycleService::class)->sync($trial->fresh(), atOffset($trial, 8));

    expect($result['skipped'])->toBe('deal_lost')
        ->and($result['fired'])->toBe([]);
});

/* --------------------------------------------------------- provisioning */

it('does not mark a trial live when provisioning failed', function () {
    $lead = Lead::factory()->create(['email' => 'nope@example.test', 'phone' => '919000000001']);
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'company_plan_id' => null,
        'plan' => 'Nothing Matching',
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
    ]);

    $result = app(TrialProvisioningService::class)->provision($trial);

    expect($result['provisioned'])->toBeFalse()
        ->and($trial->fresh()->status)->toBe(TrialStatus::Preparing)
        ->and($trial->fresh()->provisioning_error)->not->toBeNull()
        /* And the deal is not moved into TrialActive on a trial that does not exist. */
        ->and($lead->fresh()->status)->not->toBe(LeadStatus::TrialActive);
});

it('moves the deal to trial active once the environment really exists', function () {
    $trial = runningTrial();

    expect($trial->status)->toBe(TrialStatus::Active)
        ->and($trial->lead->fresh()->status)->toBe(LeadStatus::TrialActive)
        ->and($trial->isProvisioned())->toBeTrue();
});

it('goes live on a successful retry after an earlier failure', function () {
    $lead = Lead::factory()->create(['email' => 'retry@example.test', 'phone' => '919000000002']);
    $plan = lifecyclePlan();
    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'company_plan_id' => null,
        'plan' => 'Nothing Matching',
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
    ]);

    $service = app(TrialProvisioningService::class);
    $service->provision($trial);

    $trial->forceFill(['company_plan_id' => $plan->id])->save();
    $result = $service->retry($trial->fresh());

    expect($result['provisioned'])->toBeTrue()
        ->and($trial->fresh()->status)->toBe(TrialStatus::Active)
        ->and($trial->fresh()->provisioning_error)->toBeNull()
        ->and($lead->fresh()->status)->toBe(LeadStatus::TrialActive);
});

/* ------------------------------------------------------------- syncAll */

it('syncs every open trial in one pass and reports what it did', function () {
    $running = runningTrial();
    $ending = runningTrial(['end_date' => now()->addDays(2)->toDateString()]);

    $result = app(TrialLifecycleService::class)->syncAll();

    expect($result['synced'])->toBe(2)
        ->and($result['changed'])->toBeGreaterThanOrEqual(1)
        ->and($ending->fresh()->status)->toBe(TrialStatus::EndingSoon)
        ->and($running->fresh()->status)->toBe(TrialStatus::Active);
});
