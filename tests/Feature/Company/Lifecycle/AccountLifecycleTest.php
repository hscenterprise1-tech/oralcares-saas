<?php

use App\Models\Clinic;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\Services\Finance\MrrService;
use App\Services\Lifecycle\AccountLifecycleService;
use App\SubscriptionStatus;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * Account lifecycle and commercial operations.
 *
 * What these mostly pin is that nothing here computes a figure another module
 * already owns: MRR comes from the value snapshotted on the subscription,
 * entitlements from `CommercialEntitlementService`, the access window from
 * `EntitlementResolver`, and provisioning history from the audit the engine
 * writes. Every surface is read-only.
 */
function lifecycleUser(array $permissions = []): User
{
    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $user = User::factory()->create(['user_type' => UserType::CompanyUser]);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function accountWithSubscription(array $overrides = [], ?User $assignedUser = null): CompanySubscription
{
    $assignedId = $overrides['assigned_user_id'] ?? ($assignedUser?->id ?? (test()->reader?->id ?? null));
    unset($overrides['assigned_user_id']);

    $organization = isset($overrides['organization_id'])
        ? Organization::find($overrides['organization_id'])
        : Organization::factory()->create(['assigned_user_id' => $assignedId]);
    $clinic = Clinic::factory()->create(['organization_id' => $organization->id]);

    $subscription = CompanySubscription::create(array_merge([
        'organization_id' => $organization->id,
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->addMonths(10),
        'max_users' => 10,
        'max_branches' => 2,
    ], $overrides));

    /*
     * Project it, as every write path in the application does after changing a
     * contract. These pages read `customer_success_snapshots` rather than the
     * subscription — that is what keeps them one query at a hundred and twenty
     * thousand accounts — so a contract conjured straight into the database is
     * a state the running system never produces.
     */
    app(AccountSnapshotService::class)->refreshIfCustomer($organization->fresh());

    return $subscription;
}

beforeEach(function () {
    setPermissionsTeamId(0);

    foreach (['view-subscriptions', 'view-usage', 'view-provisioning', 'view-renewals', 'view-organizations'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $this->reader = lifecycleUser(['view-subscriptions', 'view-usage', 'view-provisioning']);
});

/* ═══════════════════════════ super admin bypass ═════════════════════════ */

it('lets the super admin open every lifecycle surface holding nothing', function () {
    expect($this->admin->getAllPermissions())->toBeEmpty();

    $subscription = accountWithSubscription();

    foreach ([
        route('company.customer-success.subscriptions.index'),
        route('company.customer-success.subscriptions.show', $subscription),
        route('company.customer-success.usage.index'),
        route('company.customer-success.lifecycle.index'),
        route('company.customer-success.accounts.show', ['organization' => $subscription->organization->id, 'tab' => 'lifecycle']),
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertSuccessful();
    }
});

/* ═════════════════════════════ authorization ════════════════════════════ */

it('refuses every lifecycle surface to a user holding nothing', function () {
    $nobody = lifecycleUser();
    $subscription = accountWithSubscription();

    foreach ([
        route('company.customer-success.subscriptions.index'),
        route('company.customer-success.subscriptions.show', $subscription),
        route('company.customer-success.usage.index'),
        route('company.customer-success.lifecycle.index'),
    ] as $url) {
        $this->actingAs($nobody)->get($url)->assertForbidden();
    }
});

it('gates usage and provisioning on their own permissions', function () {
    $narrow = lifecycleUser(['view-subscriptions']);

    $this->actingAs($narrow)->get(route('company.customer-success.subscriptions.index'))->assertOk();
    $this->actingAs($narrow)->get(route('company.customer-success.usage.index'))->assertForbidden();
    $this->actingAs($narrow)->get(route('company.customer-success.lifecycle.index'))->assertForbidden();
});

it('offers no way to change a subscription from these surfaces', function () {
    /*
     * Renewals raise a deal through Customer Success; provisioning and retries
     * run through the engine. A second path here would be the duplication this
     * batch exists to avoid.
     */
    $verbs = collect(app('router')->getRoutes())
        ->filter(fn ($route) => preg_match('#^company/(subscriptions|usage|lifecycle)#', (string) $route->uri()))
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->sort()
        ->values();

    expect($verbs->all())->toBe(['GET', 'HEAD']);
});

/* ══════════════════════ MRR comes from one place ════════════════════════ */

it('reads MRR from the value recorded on the subscription, never a seat rate', function () {
    $plan = CompanyPlan::factory()->create(['price' => 12999, 'billing_period' => 'monthly']);
    $subscription = accountWithSubscription([
        'company_plan_id' => $plan->id,
        'mrr' => app(MrrService::class)->snapshotValueFor($plan),
        'max_users' => 10,
    ]);

    $row = app(AccountLifecycleService::class)->subscriptionRow($subscription);

    /* Not 10 × 1500. The recorded value, and only that. */
    expect($row['mrr'])->toBe(12999.0);

    $plan->update(['price' => 38997]);

    expect(app(AccountLifecycleService::class)->subscriptionRow($subscription->fresh())['mrr'])->toBe(12999.0);
});

it('reports an unrecorded MRR as unknown rather than zero', function () {
    $subscription = accountWithSubscription(['mrr' => null]);

    expect(app(AccountLifecycleService::class)->subscriptionRow($subscription)['mrr'])->toBeNull();

    $this->actingAs($this->admin)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertInertia(fn ($page) => $page
            ->where('summary.mrr', null)
            ->where('summary.mrr_unrecorded', 1)
        );
});

it('does not recompute what the existing services already answer', function () {
    /* No seat-rate arithmetic, and no second entitlement projection. */
    $source = File::get(app_path('Services/Lifecycle/AccountLifecycleService.php'));

    expect($source)->not->toContain('* 1500')
        ->and($source)->not->toContain('* 18000')
        ->and($source)->toContain('CommercialEntitlementService')
        ->and($source)->toContain('EntitlementResolver')
        ->and($source)->toContain('MrrService');
});

/* ═══════════════════════ subscription list and detail ═══════════════════ */

it('lists subscriptions with the account, plan and expiry each one carries', function () {
    $plan = CompanyPlan::factory()->create(['name' => 'Growth', 'billing_period' => 'monthly']);
    accountWithSubscription(['company_plan_id' => $plan->id, 'ends_at' => now()->addDays(20)]);

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Lifecycle/SubscriptionsWorkspace')
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.plan', 'Growth')
            ->where('summary.expiring_30', 1)
        );
});

it('filters the book to what is expiring', function () {
    accountWithSubscription(['ends_at' => now()->addDays(15)]);
    accountWithSubscription(['ends_at' => now()->addYears(2)]);

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.subscriptions.index', ['window' => 'expiring_30']))
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 1));
});

it('shows a subscription its entitlements, window, modules and history', function () {
    $subscription = accountWithSubscription();

    SubscriptionEvent::create([
        'company_subscription_id' => $subscription->id,
        'organization_id' => $subscription->organization_id,
        'event' => SubscriptionEvent::CONVERTED_TO_PAID,
        'reason' => 'Deal won.',
    ]);

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.subscriptions.show', $subscription))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Lifecycle/SubscriptionDetail')
            ->has('subscription.entitlements')
            ->has('subscription.resolved.window')
            ->has('subscription.events', 1)
            ->where('subscription.events.0.event', 'converted_to_paid')
        );
});

/* ═════════════════ renewal → invoice → payment chain ════════════════════ */

it('walks the renewal chain from invoice to payment, and says which money is unlinked', function () {
    $subscription = accountWithSubscription();
    $clinicId = $subscription->clinic_id;

    $invoice = CommercialInvoice::create([
        'clinic_id' => $clinicId,
        'invoice_number' => 'INV-R-1',
        'status' => 'paid',
        'amount' => 50000,
        'final_amount' => 50000,
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    CommercialPayment::create([
        'clinic_id' => $clinicId,
        'commercial_invoice_id' => $invoice->id,
        'amount' => 50000,
        'payment_method' => 'neft',
        'paid_at' => now(),
    ]);

    /* Money raised against a commercial document rather than an invoice. */
    CommercialPayment::create([
        'clinic_id' => $clinicId,
        'amount' => 20000,
        'payment_method' => 'neft',
        'paid_at' => now(),
    ]);

    $chain = app(AccountLifecycleService::class)->commercialChain($subscription);

    expect($chain['invoiced'])->toBe(50000.0)
        ->and($chain['collected'])->toBe(70000.0)
        ->and($chain['payments'])->toHaveCount(2)
        /* Shown as unlinked rather than dropped or back-linked. */
        ->and(collect($chain['payments'])->whereNull('invoice_number'))->toHaveCount(1);
});

/* ═══════════════════════ usage and entitlements ═════════════════════════ */

it('reads utilisation from the one entitlement projection', function () {
    accountWithSubscription(['max_users' => 10, 'max_branches' => 2]);

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Lifecycle/UsageWorkspace')
            ->has('rows', 1)
            ->where('rows.0.purchased_seats', 10)
            ->where('rows.0.purchased_branches', 2)
        );
});

it('flags a limit without enforcing one', function () {
    /*
     * 90% is a reading aid on this page. Nothing in this system says what
     * utilisation is too high, and nothing here refuses anything because of it.
     */
    $subscription = accountWithSubscription(['max_users' => 1]);
    $organization = $subscription->organization;

    User::factory()->create(['organization_id' => $organization->id, 'user_type' => UserType::ClinicUser]);
    User::factory()->create(['organization_id' => $organization->id, 'user_type' => UserType::ClinicUser]);

    /* Seats moved, so the projection is recomputed before it is read. */
    app(AccountSnapshotService::class)->refreshIfCustomer($organization->fresh());

    $rows = app(AccountLifecycleService::class)->utilisationFor(collect([$organization->fresh()]));

    expect($rows[0]['over_seat_limit'])->toBeTrue()
        ->and($rows[0]['near_seat_limit'])->toBeTrue();
});

it('pages usage rather than walking every account', function () {
    /* This has to hold at ten thousand accounts, not two. */
    $source = File::get(app_path('Http/Controllers/Company/Lifecycle/SubscriptionController.php'));

    expect($source)->toContain('->paginate(');

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.usage.index'))
        ->assertInertia(fn ($page) => $page->has('paginator.total'));
});

/* ══════════════════════════ lifecycle and provisioning ══════════════════ */

it('derives the stage from records that already exist', function () {
    $subscription = accountWithSubscription();

    $lifecycle = app(AccountLifecycleService::class)->lifecycleFor($subscription->organization);

    expect($lifecycle['stage'])->toBe('live')
        ->and($lifecycle['window']['state'])->toBe('paid');
});

it('calls an account with no subscription not provisioned', function () {
    $organization = Organization::factory()->create();

    expect(app(AccountLifecycleService::class)->lifecycleFor($organization)['stage'])->toBe('not_provisioned');
});

it('shows a trial account as trialling from its own window', function () {
    accountWithSubscription([
        'trial_ends_at' => now()->addDays(10),
        'grace_ends_at' => now()->addDays(17),
        'ends_at' => now()->addDays(17),
    ]);

    $organization = Organization::query()->latest('id')->first();

    expect(app(AccountLifecycleService::class)->lifecycleFor($organization)['stage'])->toBe('trialling');
});

it('sends the provisioning board its failure counts', function () {
    accountWithSubscription();

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.lifecycle.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Lifecycle/ProvisioningWorkspace')
            ->has('summary.provisioning_failed')
            ->has('summary.audits_failed')
            ->has('rows')
        );
});

it('offers no retry of its own', function () {
    /* `TrialProvisioningService::retry()` already exists behind the trials surface. */
    $source = File::get(app_path('Http/Controllers/Company/Lifecycle/ProvisioningController.php'));

    expect($source)->not->toContain('retry(')
        ->and($source)->not->toContain('TrialProvisioningService');
});

/* ═══════════════════════════ historical data ═══════════════════════════ */

it('writes nothing at all', function () {
    $subscription = accountWithSubscription();
    $before = [
        'subs' => CompanySubscription::count(),
        'events' => SubscriptionEvent::count(),
        'invoices' => CommercialInvoice::count(),
        'payments' => CommercialPayment::count(),
    ];

    foreach ([
        route('company.customer-success.subscriptions.index'),
        route('company.customer-success.subscriptions.show', $subscription),
        route('company.customer-success.usage.index'),
        route('company.customer-success.lifecycle.index'),
        route('company.customer-success.accounts.show', ['organization' => $subscription->organization->id, 'tab' => 'lifecycle']),
    ] as $url) {
        $this->actingAs($this->admin)->get($url)->assertSuccessful();
    }

    expect(CompanySubscription::count())->toBe($before['subs'])
        ->and(SubscriptionEvent::count())->toBe($before['events'])
        ->and(CommercialInvoice::count())->toBe($before['invoices'])
        ->and(CommercialPayment::count())->toBe($before['payments']);
});

/* ══════════════════════ Phase 2 data isolation regressions ═══════════════ */

it('enforces tenant isolation across all lifecycle surfaces', function () {
    $repOther = lifecycleUser(['view-subscriptions', 'view-usage', 'view-provisioning']);

    $subMine = accountWithSubscription(['assigned_user_id' => $this->reader->id]);
    $subOther = accountWithSubscription(['assigned_user_id' => $repOther->id]);

    // subscriptions.index: reader sees only their subscription
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $subMine->id)
        );

    // subscriptions.show: reader cannot see other rep's subscription
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.subscriptions.show', $subOther))
        ->assertNotFound();

    // usage.index: reader sees only their account
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.organization_id', $subMine->organization_id)
        );

    // lifecycle.index: reader sees only their account
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.lifecycle.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.organization_id', $subMine->organization_id)
        );

    // lifecycle.show: reader cannot see other rep's account lifecycle (redirected to Account 360 which rejects unauthorized access)
    $this->actingAs($this->reader)
        ->followingRedirects()
        ->get(route('company.lifecycle.show', $subOther->organization))
        ->assertForbidden();
});

it('strictly excludes provider and vendor organizations from all lifecycle queries', function () {
    $providerOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares Provider HQ',
    ]);
    $clinic = Clinic::factory()->create(['organization_id' => $providerOrg->id]);
    $providerSub = CompanySubscription::create([
        'organization_id' => $providerOrg->id,
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(1),
        'ends_at' => now()->addMonths(11),
        'max_users' => 50,
        'max_branches' => 10,
    ]);

    // Excluded from subscriptions.index for both reader and super admin
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 0));

    $this->actingAs($this->reader)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertInertia(fn ($page) => $page->has('subscriptions.data', 0));

    // Excluded from usage.index
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.usage.index'))
        ->assertInertia(fn ($page) => $page->has('rows', 0));

    // Excluded from lifecycle.index
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.lifecycle.index'))
        ->assertInertia(fn ($page) => $page->has('rows', 0));

    // subscriptions.show and lifecycle.show return 404
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.subscriptions.show', $providerSub))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->followingRedirects()
        ->get(route('company.lifecycle.show', $providerOrg))
        ->assertNotFound();
});

it('retains accounts without clinics in lifecycle and usage workspaces', function () {
    // Organization created without clinics (e.g. provisioning stuck or failed before clinic creation)
    $stuckOrg = Organization::factory()->create([
        'name' => 'Stuck Provisioning Org',
        'assigned_user_id' => $this->reader->id,
    ]);

    expect($stuckOrg->clinics()->count())->toBe(0);

    // Appears in lifecycle.index
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.lifecycle.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Stuck Provisioning Org')
            ->where('rows.0.stage', 'not_provisioned')
            ->where('summary.accounts', 1)
        );

    // Appears in usage.index
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Stuck Provisioning Org')
            ->where('rows.0.has_subscription', false)
        );
});

it('filters lifecycle stages in SQL before pagination and preserves paginator counts', function () {
    // Create 27 accounts in 'not_provisioned' stage
    for ($i = 1; $i <= 27; $i++) {
        Organization::factory()->create([
            'name' => "Unprovisioned Client {$i}",
            'assigned_user_id' => $this->reader->id,
        ]);
    }

    // Create 3 accounts in 'trialling' stage
    for ($j = 1; $j <= 3; $j++) {
        accountWithSubscription([
            'trial_ends_at' => now()->addDays(10),
            'grace_ends_at' => now()->addDays(17),
            'ends_at' => now()->addDays(17),
        ], $this->reader);
    }

    // Total accounts = 30
    // Querying with stage=not_provisioned:
    // In SQL before pagination, count is 27. Paginator total should be 27, and page 1 has exactly 25 items.
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.lifecycle.index', ['stage' => 'not_provisioned']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 27)
            ->has('rows', 25)
            ->where('rows.0.stage', 'not_provisioned')
            ->where('rows.24.stage', 'not_provisioned')
        );

    // Querying page 2 with stage=not_provisioned:
    // Page 2 has the remaining 2 items.
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.lifecycle.index', ['stage' => 'not_provisioned', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 27)
            ->has('rows', 2)
        );

    // Querying with stage=trialling:
    // Paginator total is 3, page 1 has exactly 3 items.
    $this->actingAs($this->reader)
        ->get(route('company.customer-success.lifecycle.index', ['stage' => 'trialling']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 3)
            ->has('rows', 3)
            ->where('rows.0.stage', 'trialling')
            ->where('rows.1.stage', 'trialling')
            ->where('rows.2.stage', 'trialling')
        );
});

it('lets super admin access all customer accounts while excluding provider', function () {
    $repA = lifecycleUser(['view-subscriptions', 'view-usage', 'view-provisioning']);
    $repB = lifecycleUser(['view-subscriptions', 'view-usage', 'view-provisioning']);

    $subA = accountWithSubscription(['assigned_user_id' => $repA->id]);
    $subB = accountWithSubscription(['assigned_user_id' => $repB->id]);

    $providerOrg = Organization::factory()->provider()->create(['name' => 'HQ']);
    $providerClinic = Clinic::factory()->create(['organization_id' => $providerOrg->id]);
    CompanySubscription::create([
        'organization_id' => $providerOrg->id,
        'clinic_id' => $providerClinic->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(1),
        'ends_at' => now()->addMonths(11),
    ]);

    // Super Admin sees both customer subscriptions (2), but not provider (0)
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 2)
        );

    // Super Admin can access both Rep A and Rep B details
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.subscriptions.show', $subA))
        ->assertOk();

    $this->actingAs($this->admin)
        ->get(route('company.customer-success.subscriptions.show', $subB))
        ->assertOk();

    // Super Admin sees both customer accounts in usage and lifecycle
    $this->actingAs($this->admin)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 2));

    $this->actingAs($this->admin)
        ->get(route('company.customer-success.lifecycle.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows', 2));
});

it('provides backward-compatible 301 redirects from all legacy lifecycle and subscription paths', function () {
    $subscription = accountWithSubscription();
    $org = $subscription->organization;

    // 1. /company/subscriptions -> 301
    $this->actingAs($this->reader)
        ->get(route('company.subscriptions.index'))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/subscriptions');

    // 2. /company/subscriptions/{id} -> 301
    $this->actingAs($this->reader)
        ->get(route('company.subscriptions.show', $subscription))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/subscriptions/'.$subscription->id);

    // 3. /company/usage -> 301
    $this->actingAs($this->reader)
        ->get(route('company.usage.index'))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/usage');

    // 4. /company/lifecycle -> 301
    $this->actingAs($this->reader)
        ->get(route('company.lifecycle.index'))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/lifecycle');

    // 5. /company/lifecycle/{id} -> 301
    $this->actingAs($this->reader)
        ->get(route('company.lifecycle.show', $org))
        ->assertStatus(301)
        ->assertRedirect("/company/customer-success/accounts/{$org->id}?tab=lifecycle");
});
