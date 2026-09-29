<?php

use App\Http\Middleware\EnforceSubscriptionWindow;
use App\Models\ClinicMembership;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\Trial;
use App\Models\User;
use App\Services\ClinicContext;
use App\Services\CRM\TrialLifecycleService;
use App\Services\CustomerProvisioningEngine;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Provisioning\ProvisioningIntent;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Phase 3 — the door.
 *
 * Before this there was no door at all: `CheckLicenseLimit` was registered in no
 * alias list and `RequireFeatureEntitlement` is applied to no route, so a
 * subscription could expire and the clinic carried on working.
 *
 * These tests drive the middleware directly rather than through the clinic
 * portal, because what is under test is the decision — let them in, or do not —
 * and routing it through a login would test session plumbing instead.
 */
function enforcementTrial(): Trial
{
    $plan = CompanyPlan::firstWhere('key', 'growth') ?? CompanyPlan::factory()->create([
        'key' => 'growth',
        'name' => 'Growth',
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ]);

    $lead = Lead::factory()->create([
        'company_name' => 'Sundaram Eye Hospital',
        'contact_name' => 'Meera Sundaram',
        'email' => 'meera-'.uniqid().'@sundaram.test',
        'phone' => '919700000000',
        'assigned_user_id' => User::factory()->companyUser()->create()->id,
    ]);

    $trial = Trial::factory()->create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $lead->assigned_user_id,
        'company_plan_id' => $plan->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'duration_days' => 14,
    ]);

    app(TrialProvisioningService::class)->provision($trial);

    return $trial->fresh();
}

/**
 * Ask the middleware whether this tenant gets in, at a given moment.
 *
 * @return array{allowed: bool, status: int}
 */
function askTheDoor(Trial $trial, ?Carbon $at = null, bool $json = true): array
{
    if ($at) {
        Carbon::setTestNow($at);
    }

    $context = app(ClinicContext::class);
    $membership = ClinicMembership::where('clinic_id', $trial->clinic_id)->first();

    $context->setUser(User::find($membership->user_id));
    $context->setMembership($membership);

    $reached = false;

    $response = app(EnforceSubscriptionWindow::class)->handle(
        Request::create('/clinic/dashboard', 'GET', server: $json ? ['HTTP_ACCEPT' => 'application/json'] : []),
        function ($request) use (&$reached) {
            $reached = true;

            return response('ok');
        },
    );

    Carbon::setTestNow();

    return ['allowed' => $reached, 'status' => $response->getStatusCode(), 'body' => $response->getContent()];
}

it('lets a running trial through', function () {
    $trial = enforcementTrial();

    expect(askTheDoor($trial)['allowed'])->toBeTrue();
});

it('lets the customer in on every day of the grace period', function () {
    /*
     * The days after a trial ends are when the decision actually gets made.
     * Locking the customer out then would sabotage the one thing grace exists
     * to achieve, so this is the assertion that matters most in the file.
     */
    $trial = enforcementTrial();

    foreach ([1, 3, 6, 7] as $offset) {
        $result = askTheDoor($trial, $trial->end_date->copy()->addDays($offset)->setTime(9, 0));

        expect($result['allowed'])->toBeTrue("day +{$offset} should still be let in");
    }
});

it('turns the customer away once grace is spent', function () {
    $trial = enforcementTrial();

    $result = askTheDoor($trial, $trial->end_date->copy()->addDays(8)->setTime(9, 0));

    expect($result['allowed'])->toBeFalse()
        /* 402, not 403: this is a billing state, not a permission one. */
        ->and($result['status'])->toBe(402);
});

it('turns the customer away the moment a trial is cancelled', function () {
    $trial = enforcementTrial();

    app(TrialLifecycleService::class)->cancel($trial, 'Duplicate account.');

    expect(askTheDoor($trial->fresh())['allowed'])->toBeFalse();
});

it('lets the customer back in when the trial is extended', function () {
    $trial = enforcementTrial();

    /* Locked out on day +8... */
    expect(askTheDoor($trial, $trial->end_date->copy()->addDays(8)->setTime(9, 0))['allowed'])->toBeFalse();

    $extended = app(TrialLifecycleService::class)->extend($trial->fresh(), 14, 'Client asked for more time.');

    /* ...and back in on that same day, because the window moved with them. */
    expect(askTheDoor($extended, $trial->end_date->copy()->addDays(8)->setTime(9, 0))['allowed'])->toBeTrue();
});

it('never turns away a customer whose deal was won', function () {
    $trial = enforcementTrial();

    app(CustomerProvisioningEngine::class)->provision(
        $trial->lead->fresh(),
        'Main Branch',
        ProvisioningIntent::sale(),
    );

    $result = askTheDoor($trial->fresh(), $trial->end_date->copy()->addDays(60)->setTime(9, 0));

    expect($result['allowed'])->toBeTrue();
});

it('leaves a tenant with no subscription alone rather than locking it out', function () {
    /*
     * Every clinic provisioned before subscriptions were written would
     * otherwise lose access the moment this shipped. That is a data problem to
     * fix deliberately, not one to discover through support tickets.
     */
    $trial = enforcementTrial();
    CompanySubscription::where('organization_id', $trial->customer_organization_id)->delete();

    expect(askTheDoor($trial->fresh())['allowed'])->toBeTrue();
});

it('answers from the resolver, not from its own arithmetic', function () {
    /*
     * The middleware must hold no dates of its own. If the resolver says a
     * window is lapsed, the door is shut, whatever the trial record happens to
     * say — one source of truth, checked here rather than assumed.
     */
    $trial = enforcementTrial();
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    $subscription->update(['trial_ends_at' => now()->subDays(30), 'grace_ends_at' => now()->subDays(23), 'ends_at' => now()->subDays(23)]);

    expect(app(EntitlementResolver::class)->window($subscription->fresh())['state'])->toBe('lapsed')
        ->and(askTheDoor($trial->fresh())['allowed'])->toBeFalse()
        /* The trial row still says Active; the resolver still wins. */
        ->and($trial->fresh()->status)->toBe(TrialStatus::Active);
});

it('shows a browser a real page rather than a blank screen when access has ended', function () {
    /*
     * The sibling error views extend `errors::minimal`, a hint path the
     * framework only registers while it is rendering an exception — so a view
     * returned straight from middleware cannot use it. This is the branch a
     * real locked-out customer hits, and it is the one that would have failed
     * silently while the JSON branch passed.
     */
    $trial = enforcementTrial();

    $result = askTheDoor($trial, $trial->end_date->copy()->addDays(8)->setTime(9, 0), json: false);

    expect($result['allowed'])->toBeFalse()
        ->and($result['status'])->toBe(402)
        ->and($result['body'])->toContain('Your access has ended')
        ->and($result['body'])->toContain('clinic data is retained');
});
