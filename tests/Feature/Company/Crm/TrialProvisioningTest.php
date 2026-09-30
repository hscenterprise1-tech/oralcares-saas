<?php

use App\LeadStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanyFeature;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\EnabledModule;
use App\Models\Lead;
use App\Models\License;
use App\Models\Organization;
use App\Models\ProvisioningAudit;
use App\Models\SubscriptionEvent;
use App\Models\Trial;
use App\Models\User;
use App\Services\CustomerProvisioningEngine;
use App\Services\Provisioning\EntitlementResolver;
use App\Services\Provisioning\ProvisioningIntent;
use App\Services\Provisioning\StarterCatalogue;
use App\Services\Provisioning\TrialProvisioningService;
use App\TrialStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Phase 2 — a trial that provisions a real environment.
 *
 * The thing under test throughout is that a trial customer gets the same tenant
 * a paying one would, on the plan being sold, and that winning the deal keeps
 * it rather than building a second.
 */
/**
 * Modules are read from `company_plan_features` now, not a hardcoded map
 * keyed by string — see `EntitlementResolver::modulesFor()`. A plan built by
 * this factory has no pivot rows of its own, so it's attached here to the
 * same canonical set the real starter/growth/enterprise rows carry, keeping
 * every test written against "the plan sells more than the last one" true.
 * An unrecognised key (e.g. `bespoke-2027`) gets nothing attached, which is
 * exactly the "falls back to the smallest set" case those tests cover.
 */
function planFor(string $key, array $overrides = []): CompanyPlan
{
    $plan = CompanyPlan::factory()->create(array_merge([
        'key' => $key,
        'name' => ucfirst($key),
        'max_users' => 20,
        'max_doctors' => 5,
        'max_branches' => 3,
        'storage_limit_gb' => 50,
        'trial_duration_days' => 14,
    ], $overrides));

    $moduleKeys = match ($key) {
        'starter' => ['appointments', 'patients', 'billing'],
        'growth' => ['appointments', 'patients', 'billing', 'emr', 'whatsapp', 'inventory'],
        'enterprise' => ['appointments', 'patients', 'billing', 'emr', 'whatsapp', 'inventory', 'lab', 'xray', 'analytics'],
        default => [],
    };

    if ($moduleKeys !== []) {
        $plan->features()->attach(CompanyFeature::whereIn('key', $moduleKeys)->pluck('id'));
    }

    return $plan;
}

function trialFor(CompanyPlan $plan, array $overrides = []): Trial
{
    $lead = Lead::factory()->create([
        'company_name' => 'Vasant Vihar Dental',
        'contact_name' => 'Asha Menon',
        'email' => 'asha@vasantvihar.test',
        'phone' => '919812345678',
    ]);

    return Trial::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'company_plan_id' => $plan->id,
        'status' => TrialStatus::Preparing,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(14)->toDateString(),
        'duration_days' => 14,
    ], $overrides));
}

/* ---------------------------------------------------------------- resolver */

it('grants the modules the plan sells, not the same five to everyone', function () {
    $resolver = app(EntitlementResolver::class);

    $starter = $resolver->modulesFor(planFor('starter'));
    $enterprise = $resolver->modulesFor(planFor('enterprise'));

    expect($starter)->not->toContain('emr')
        ->and($enterprise)->toContain('emr')
        ->and($enterprise)->toContain('analytics')
        ->and(count($enterprise))->toBeGreaterThan(count($starter));
});

it('falls back to the smallest module set for an unknown plan', function () {
    /* A plan we cannot identify should grant the least, never the most. */
    $resolver = app(EntitlementResolver::class);

    expect($resolver->modulesFor(planFor('bespoke-2027')))
        ->toBe(EntitlementResolver::FALLBACK_MODULES)
        ->and($resolver->modulesFor(null))->toBe(EntitlementResolver::FALLBACK_MODULES);
});

it('reads storage from the plan instead of the hardcoded ten gigabytes', function () {
    $resolver = app(EntitlementResolver::class);

    expect($resolver->planStorageMb(planFor('starter', ['storage_limit_gb' => 10])))->toBe(10240)
        ->and($resolver->planStorageMb(planFor('enterprise', ['storage_limit_gb' => 250])))->toBe(256000);
});

it('reports the window a subscription is in, and that grace still grants access', function () {
    $resolver = app(EntitlementResolver::class);
    $org = Organization::factory()->create();

    $inTrial = CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => 'active',
        'starts_at' => now()->subDays(3),
        'trial_ends_at' => now()->addDays(11),
        'grace_ends_at' => now()->addDays(18),
        'ends_at' => now()->addDays(18),
    ]);

    $inGrace = CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => 'active',
        'starts_at' => now()->subDays(20),
        'trial_ends_at' => now()->subDays(2),
        'grace_ends_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5),
    ]);

    $lapsed = CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => 'active',
        'starts_at' => now()->subDays(40),
        'trial_ends_at' => now()->subDays(20),
        'grace_ends_at' => now()->subDays(13),
        'ends_at' => now()->subDays(13),
    ]);

    expect($resolver->window($inTrial)['state'])->toBe('trial')
        ->and($resolver->window($inGrace)['state'])->toBe('grace')
        ->and($resolver->window($lapsed)['state'])->toBe('lapsed')
        ->and($resolver->fromSubscription($inGrace, $org)['grants_access'])->toBeTrue()
        ->and($resolver->fromSubscription($lapsed, $org)['grants_access'])->toBeFalse();
});

it('reports no whatsapp quota rather than inventing one', function () {
    $org = Organization::factory()->create();

    $subscription = CompanySubscription::create([
        'organization_id' => $org->id,
        'company_plan_id' => planFor('growth')->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
    ]);

    /*
     * No plan, account or licence column defines an allowance, so the honest
     * answer is that it is unavailable.
     */
    expect(app(EntitlementResolver::class)->fromSubscription($subscription, $org)['whatsapp_quota'])->toBeNull();
});

/* ------------------------------------------------------------ provisioning */

it('provisions a complete operational environment for a trial', function () {
    $plan = planFor('growth');
    $trial = trialFor($plan);

    $result = app(TrialProvisioningService::class)->provision($trial);

    expect($result['provisioned'])->toBeTrue()
        ->and($result['error'])->toBeNull();

    $trial = $result['trial'];
    $clinic = Clinic::find($trial->clinic_id);
    $branch = Branch::withoutOrganizationScope()->where('clinic_id', $clinic->id)->first();

    expect($trial->isProvisioned())->toBeTrue()
        ->and($trial->status)->toBe(TrialStatus::Active)
        ->and($clinic)->not->toBeNull()
        ->and($branch)->not->toBeNull();

    /*
     * The gap this phase exists to close: every one of these tables was empty
     * for all four existing customers, so nothing in the product would run.
     */
    expect(DB::table('clinic_operating_hours')->where('clinic_id', $clinic->id)->count())->toBe(7)
        ->and(DB::table('clinic_departments')->where('clinic_id', $clinic->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('clinic_rooms')->where('branch_id', $branch->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('clinic_queues')->where('clinic_id', $clinic->id)->count())->toBe(1)
        ->and(DB::table('branch_settings')->where('branch_id', $branch->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('clinical_specialties')->where('organization_id', $trial->customer_organization_id)->count())->toBeGreaterThan(0)
        ->and(DB::table('treatment_catalogs')->where('organization_id', $trial->customer_organization_id)->count())->toBeGreaterThan(0);
});

it('marks starter data as replaceable rather than passing it off as the clinic own', function () {
    $trial = trialFor(planFor('growth'));
    app(TrialProvisioningService::class)->provision($trial);

    $treatment = DB::table('treatment_catalogs')
        ->where('organization_id', $trial->fresh()->customer_organization_id)
        ->first();

    expect($treatment->description)->toBe(StarterCatalogue::MARKER);
});

it('honours the plan limits on the subscription and the licence', function () {
    $plan = planFor('enterprise', ['max_users' => 100, 'max_doctors' => 25, 'max_branches' => 10, 'storage_limit_gb' => 250]);
    $trial = trialFor($plan);

    app(TrialProvisioningService::class)->provision($trial);

    $org = Organization::find($trial->fresh()->customer_organization_id);
    $subscription = CompanySubscription::where('organization_id', $org->id)->first();
    $license = License::where('organization_id', $org->id)->first();

    expect($subscription->max_users)->toBe(100)
        ->and($subscription->max_doctors)->toBe(25)
        ->and($subscription->max_branches)->toBe(10)
        ->and($subscription->storage_limit_gb)->toBe(250)
        /* Was hardcoded to 10240 for every customer regardless of plan. */
        ->and($license->storage_limit_mb)->toBe(256000);

    $modules = EnabledModule::where('license_id', $license->id)->pluck('module_name')->all();

    expect($modules)->toContain('analytics')->toContain('emr');
});

it('dates the subscription to the trial window with grace on the end', function () {
    $trial = trialFor(planFor('growth'), ['start_date' => now()->toDateString(), 'duration_days' => 14]);

    app(TrialProvisioningService::class)->provision($trial);

    $trial = $trial->fresh();
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    expect($subscription->trial_ends_at->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($subscription->grace_ends_at->toDateString())->toBe(now()->addDays(21)->toDateString())
        /*
         * Access ends when grace ends, not on the trial end date — grace is
         * full access, so expiring the subscription earlier would contradict it.
         */
        ->and($subscription->ends_at->toDateString())->toBe($subscription->grace_ends_at->toDateString())
        ->and($trial->grace_ends_at->toDateString())->toBe(now()->addDays(21)->toDateString());
});

it('honours an end date that was adjusted by hand', function () {
    /*
     * `trials.end_date` is NOT NULL, so a trial always arrives with one and it
     * is the salesperson's decision, not the plan's default. Grace is measured
     * from whatever they set.
     */
    $trial = trialFor(planFor('growth', ['trial_duration_days' => 14]), [
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

    app(TrialProvisioningService::class)->provision($trial);

    $trial = $trial->fresh();
    $subscription = CompanySubscription::where('organization_id', $trial->customer_organization_id)->first();

    expect($trial->end_date->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($subscription->trial_ends_at->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($subscription->grace_ends_at->toDateString())->toBe(now()->addDays(37)->toDateString());
});

it('does not win the deal when a trial is provisioned', function () {
    /*
     * The engine used to set Won unconditionally, so starting a trial would
     * have closed the very deal the trial exists to help close.
     */
    $trial = trialFor(planFor('growth'));

    app(TrialProvisioningService::class)->provision($trial);

    /*
     * The deal advances to TrialActive, which is exactly where a running trial
     * belongs. What it must never do is skip to Won — that would close the deal
     * the trial exists to help close.
     */
    expect($trial->lead->fresh()->status)->toBe(LeadStatus::TrialActive)
        ->and($trial->lead->fresh()->status)->not->toBe(LeadStatus::Won)
        ->and($trial->fresh()->customer_organization_id)->not->toBeNull();
});

it('creates exactly one user, the initial admin, and no clinic staff', function () {
    /*
     * The boundary: CRM creates the handover identity and nothing else. Every
     * later account belongs to the customer's own administration.
     */
    $trial = trialFor(planFor('growth'));
    $before = User::count();

    app(TrialProvisioningService::class)->provision($trial);

    $clinic = Clinic::find($trial->fresh()->clinic_id);
    $memberships = ClinicMembership::where('clinic_id', $clinic->id)->get();

    expect(User::count())->toBe($before + 1)
        ->and($memberships)->toHaveCount(1)
        ->and($memberships->first()->is_primary_owner)->toBeTrue()
        ->and(User::find($memberships->first()->user_id)->user_type)->toBe(UserType::ClinicUser)
        /* And no clinic employee directory is created on the customer's behalf. */
        ->and(DB::table('clinic_employees')->where('clinic_id', $clinic->id)->count())->toBe(0);
});

it('records the handover attempt on the trial without storing a credential', function () {
    $trial = trialFor(planFor('growth'));

    $result = app(TrialProvisioningService::class)->provision($trial);
    $checklist = $trial->fresh()->onboarding_checklist;

    expect($checklist)->toHaveKey('handover')
        ->and($checklist['handover']['channel'])->toBe('whatsapp')
        ->and($checklist['handover'])->not->toHaveKey('password')
        ->and($checklist['handover'])->not->toHaveKey('token')
        ->and($checklist['handover'])->not->toHaveKey('link')
        /* No WhatsApp account is connected in tests, so delivery honestly fails. */
        ->and($result['handover']['delivered'])->toBeFalse();
});

it('provisions the environment even when the handover cannot be delivered', function () {
    /*
     * A wrong number must not cost the customer their environment — the link
     * can be re-sent, a rolled-back tenant cannot be un-rolled-back.
     */
    $trial = trialFor(planFor('growth'));
    $trial->lead->update(['phone' => null]);

    $result = app(TrialProvisioningService::class)->provision($trial);

    expect($result['provisioned'])->toBeTrue()
        ->and($result['handover']['delivered'])->toBeFalse()
        ->and($result['handover']['reason'])->toContain('No WhatsApp number');
});

it('writes an audit row for a successful run', function () {
    $trial = trialFor(planFor('growth'));

    app(TrialProvisioningService::class)->provision($trial);

    $audit = ProvisioningAudit::where('trial_id', $trial->id)->first();

    expect($audit->status)->toBe('succeeded')
        ->and($audit->profile)->toBe('trial')
        ->and($audit->clinic_id)->toBe($trial->fresh()->clinic_id)
        ->and($audit->version)->toBe(TrialProvisioningService::VERSION)
        ->and($audit->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($audit->created_entities)->toHaveKey('modules');
});

/* --------------------------------------------------------------- failure */

it('records why provisioning failed and leaves the trial in preparing', function () {
    /* No plan anywhere: the one thing a trial cannot be provisioned without. */
    $trial = trialFor(planFor('growth'), ['company_plan_id' => null, 'plan' => 'Legacy Bundle']);

    $result = app(TrialProvisioningService::class)->provision($trial);

    expect($result['provisioned'])->toBeFalse()
        ->and($result['error'])->toContain('No plan selected')
        ->and($trial->fresh()->status)->toBe(TrialStatus::Preparing)
        ->and($trial->fresh()->provisioning_error)->toContain('No plan selected')
        ->and($trial->fresh()->clinic_id)->toBeNull();
});

it('links a failed audit to the trial even though there is no clinic', function () {
    $trial = trialFor(planFor('growth'), ['company_plan_id' => null, 'plan' => 'Legacy Bundle']);

    app(TrialProvisioningService::class)->provision($trial);

    $audit = ProvisioningAudit::where('trial_id', $trial->id)->first();

    expect($audit->status)->toBe('failed')
        ->and($audit->clinic_id)->toBeNull()
        ->and($audit->lead_id)->toBe($trial->lead_id)
        ->and($audit->error_message)->toContain('No plan selected');
});

it('leaves no half-built tenant behind when provisioning fails', function () {
    $trial = trialFor(planFor('growth'), ['company_plan_id' => null, 'plan' => 'Legacy Bundle']);
    $orgs = Organization::count();

    app(TrialProvisioningService::class)->provision($trial);

    expect(Organization::count())->toBe($orgs)
        ->and(Clinic::count())->toBe(0);
});

it('clears the old error and succeeds on retry', function () {
    $plan = planFor('growth');
    $trial = trialFor($plan, ['company_plan_id' => null, 'plan' => 'Legacy Bundle']);
    $service = app(TrialProvisioningService::class);

    $service->provision($trial);
    expect($trial->fresh()->provisioning_error)->not->toBeNull();

    $trial->forceFill(['company_plan_id' => $plan->id])->save();
    $result = $service->retry($trial->fresh());

    expect($result['provisioned'])->toBeTrue()
        ->and($trial->fresh()->provisioning_error)->toBeNull()
        ->and($trial->fresh()->status)->toBe(TrialStatus::Active);
});

/* ------------------------------------------------------------ idempotency */

it('does not build a second environment when provisioning runs twice', function () {
    $trial = trialFor(planFor('growth'));
    $service = app(TrialProvisioningService::class);

    $service->provision($trial);
    $firstClinic = $trial->fresh()->clinic_id;
    $orgs = Organization::count();

    $service->provision($trial->fresh());

    expect(Clinic::count())->toBe(1)
        ->and(Organization::count())->toBe($orgs)
        ->and($trial->fresh()->clinic_id)->toBe($firstClinic)
        ->and(CompanySubscription::count())->toBe(1);
});

it('adds nothing on a second operational pass', function () {
    $trial = trialFor(planFor('growth'));
    $service = app(TrialProvisioningService::class);

    $service->provision($trial);
    $clinic = $trial->fresh()->clinic_id;
    $hours = DB::table('clinic_operating_hours')->where('clinic_id', $clinic)->count();

    $service->provision($trial->fresh());

    expect(DB::table('clinic_operating_hours')->where('clinic_id', $clinic)->count())->toBe($hours);
});

/* ------------------------------------------------------------- conversion */

it('keeps the trial tenant and its data when the deal is won', function () {
    $plan = planFor('growth');
    $trial = trialFor($plan);

    app(TrialProvisioningService::class)->provision($trial);

    $trial = $trial->fresh();
    $orgId = $trial->customer_organization_id;
    $clinicId = $trial->clinic_id;
    $userId = ClinicMembership::where('clinic_id', $clinicId)->value('user_id');

    /* Something the customer entered during the trial. */
    DB::table('treatment_catalogs')->insert([
        'organization_id' => $orgId,
        'name' => 'Full Mouth Rehabilitation',
        'category' => 'Prosthodontics',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orgs = Organization::count();

    app(CustomerProvisioningEngine::class)->provision($trial->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    /* The lead's own vendor organisation is in this count too; what matters is
     * that winning the deal adds no new one. */
    expect(Organization::count())->toBe($orgs)
        ->and(Clinic::count())->toBe(1)
        ->and(Clinic::first()->id)->toBe($clinicId)
        ->and(ClinicMembership::where('clinic_id', $clinicId)->value('user_id'))->toBe($userId)
        ->and(DB::table('treatment_catalogs')->where('name', 'Full Mouth Rehabilitation')->count())->toBe(1);
});

it('replaces the trial window with a paid term on conversion', function () {
    $trial = trialFor(planFor('growth'));

    app(TrialProvisioningService::class)->provision($trial);
    app(CustomerProvisioningEngine::class)->provision($trial->fresh()->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    $subscription = CompanySubscription::first();

    expect($subscription->trial_ends_at)->toBeNull()
        ->and($subscription->grace_ends_at)->toBeNull()
        ->and($subscription->ends_at->toDateString())->toBe(now()->addYear()->toDateString())
        ->and(app(EntitlementResolver::class)->window($subscription)['state'])->toBe('paid');
});

it('logs the trial start and the conversion against the deal', function () {
    $trial = trialFor(planFor('growth'));

    app(TrialProvisioningService::class)->provision($trial);
    app(CustomerProvisioningEngine::class)->provision($trial->fresh()->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    $events = SubscriptionEvent::orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events->first()->event)->toBe(SubscriptionEvent::TRIAL_STARTED)
        ->and($events->last()->event)->toBe(SubscriptionEvent::CONVERTED_TO_PAID)
        ->and($events->last()->lead_id)->toBe($trial->lead_id);
});

it('does not run a second onboarding on conversion', function () {
    $trial = trialFor(planFor('growth'));

    app(TrialProvisioningService::class)->provision($trial);
    $clinicId = $trial->fresh()->clinic_id;
    $hours = DB::table('clinic_operating_hours')->where('clinic_id', $clinicId)->count();
    $rooms = DB::table('clinic_rooms')->count();

    app(CustomerProvisioningEngine::class)->provision($trial->fresh()->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    expect(DB::table('clinic_operating_hours')->where('clinic_id', $clinicId)->count())->toBe($hours)
        ->and(DB::table('clinic_rooms')->count())->toBe($rooms)
        ->and(Branch::withoutOrganizationScope()->count())->toBe(1);
});

it('moves the deal to won only on the sale, and applies the sold plan', function () {
    $trial = trialFor(planFor('starter', ['max_users' => 5, 'storage_limit_gb' => 10]));

    app(TrialProvisioningService::class)->provision($trial);

    expect(CompanySubscription::first()->max_users)->toBe(5);

    app(CustomerProvisioningEngine::class)->provision($trial->fresh()->lead->fresh(), 'Main Branch', ProvisioningIntent::sale());

    expect($trial->lead->fresh()->status->value)->toBe('won');
});
