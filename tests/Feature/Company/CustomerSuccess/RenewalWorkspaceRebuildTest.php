<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Branch;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommercialRenewalService;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    $this->vendorOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares HQ Provider',
    ]);

    $this->manager = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->manager->assignRole(Role::findByName('CRM Manager', 'web'));

    $this->rep = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->rep->assignRole(Role::findByName('Sales Representative', 'web'));
});

it('supports server-side pagination with 200+ subscriptions and accurate totals', function () {
    $plan = CompanyPlan::factory()->create(['name' => 'Scale Tier', 'billing_period' => 'yearly']);

    // Create 215 subscriptions across customer accounts assigned to manager
    for ($i = 1; $i <= 215; $i++) {
        $org = Organization::factory()->customer()->create([
            'name' => sprintf('Dental Enterprise %03d', $i),
            'assigned_user_id' => $this->manager->id,
        ]);

        CompanySubscription::create([
            'organization_id' => $org->id,
            'company_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(6),
            'ends_at' => now()->addDays($i),
            'max_users' => 10,
            'max_branches' => 2,
        ]);
    }

    // Page 1
    $response = $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Renewals/Index')
            ->has('renewals', 25)
            ->where('paginator.total', 215)
            ->where('paginator.per_page', 25)
            ->where('paginator.current_page', 1)
            ->where('paginator.last_page', 9)
        );

    // Page 2
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('renewals', 25)
            ->where('paginator.current_page', 2)
        );

    // Page 9 (last page has remaining 15 items)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['page' => 9]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('renewals', 15)
            ->where('paginator.current_page', 9)
        );
});

it('filters renewals by server-side search across account and contact', function () {
    $orgA = Organization::factory()->customer()->create([
        'name' => 'Apex Healthcare Group',
        'contact_person' => 'Dr. Rajesh Apex',
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgA->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(6),
        'ends_at' => now()->addDays(20),
        'max_users' => 5,
    ]);

    $orgB = Organization::factory()->customer()->create([
        'name' => 'Zenith MultiCare',
        'contact_person' => 'Dr. Priya Zenith',
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgB->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(6),
        'ends_at' => now()->addDays(40),
        'max_users' => 5,
    ]);

    // Search by company name
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['search' => 'Apex']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('renewals', 1)
            ->where('renewals.0.companyName', 'Apex Healthcare Group')
            ->where('paginator.total', 1)
        );

    // Search by contact person
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['search' => 'Priya']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('renewals', 1)
            ->where('renewals.0.companyName', 'Zenith MultiCare')
            ->where('paginator.total', 1)
        );
});

it('filters renewals accurately across overdue, 30, 60, and 90 day horizons', function () {
    $createSubWithDays = function (int $days, string $name) {
        $org = Organization::factory()->customer()->create([
            'name' => $name,
            'assigned_user_id' => $this->manager->id,
        ]);

        return CompanySubscription::create([
            'organization_id' => $org->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subYear(),
            'ends_at' => now()->addDays($days),
            'max_users' => 5,
        ]);
    };

    // 2 overdue (-10, -3)
    $createSubWithDays(-10, 'Overdue 1');
    $createSubWithDays(-3, 'Overdue 2');

    // 2 in next 30 days (+10, +25)
    $createSubWithDays(10, 'Due 10');
    $createSubWithDays(25, 'Due 25');

    // 2 in next 60 days (+45, +55)
    $createSubWithDays(45, 'Due 45');
    $createSubWithDays(55, 'Due 55');

    // 2 in next 90 days (+75, +85)
    $createSubWithDays(75, 'Due 75');
    $createSubWithDays(85, 'Due 85');

    // 1 far in future (+150)
    $createSubWithDays(150, 'Due 150');

    // Overdue horizon
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 2)
            ->has('renewals', 2)
        );

    // 30 days horizon
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => '30']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 2)
            ->has('renewals', 2)
        );

    // 60 days horizon (includes 30 days + 60 days = 4)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => '60']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 4)
            ->has('renewals', 4)
        );

    // 90 days horizon (includes 30 + 60 + 90 = 6)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => '90']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 6)
            ->has('renewals', 6)
        );
});

it('filters renewals by classification status', function () {
    // At Risk
    $orgRisk = Organization::factory()->customer()->create([
        'name' => 'Risk Clinic',
        'churn_risk' => CustomerChurnRisk::High,
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgRisk->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(20),
        'max_users' => 5,
    ]);

    // Expansion
    $orgExp = Organization::factory()->customer()->create([
        'name' => 'Expansion Clinic',
        'churn_risk' => CustomerChurnRisk::Low,
        'expansion_opportunity' => true,
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgExp->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(20),
        'max_users' => 5,
    ]);

    // Ready to renew
    $orgReady = Organization::factory()->customer()->create([
        'name' => 'Ready Clinic',
        'churn_risk' => CustomerChurnRisk::Low,
        'expansion_opportunity' => false,
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgReady->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(20),
        'max_users' => 5,
    ]);

    // Filter at_risk
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['status' => 'at_risk']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'Risk Clinic')
            ->where('renewals.0.retentionStatus', 'At Risk')
        );

    // Filter expansion
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['status' => 'expansion']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'Expansion Clinic')
            ->where('renewals.0.retentionStatus', 'Expansion Opportunity')
        );

    // Filter ready
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['status' => 'ready']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'Ready Clinic')
            ->where('renewals.0.retentionStatus', 'Ready to Renew')
        );
});

it('supports server-side sorting by renewal date, arr, and company name', function () {
    $orgA = Organization::factory()->customer()->create([
        'name' => 'AAA Dental',
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgA->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(50),
        'max_users' => 20, // ARR 360,000
    ]);

    $orgZ = Organization::factory()->customer()->create([
        'name' => 'ZZZ Dental',
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgZ->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(10),
        'max_users' => 5, // ARR 90,000
    ]);

    // Sort ends_at asc (ZZZ ends sooner than AAA)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['sort' => 'ends_at', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('renewals.0.companyName', 'ZZZ Dental')
            ->where('renewals.1.companyName', 'AAA Dental')
        );

    // Sort arr desc (AAA has higher arr than ZZZ)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['sort' => 'arr', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('renewals.0.companyName', 'AAA Dental')
            ->where('renewals.1.companyName', 'ZZZ Dental')
        );

    // Sort company_name asc
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['sort' => 'company_name', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('renewals.0.companyName', 'AAA Dental')
            ->where('renewals.1.companyName', 'ZZZ Dental')
        );
});

it('enforces tenant isolation and rep boundaries on renewals and contract changes', function () {
    $repOther = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $repOther->assignRole(Role::findByName('Sales Representative', 'web'));

    $orgMine = Organization::factory()->customer()->create([
        'name' => 'My Rep Account',
        'assigned_user_id' => $this->rep->id,
    ]);
    $subMine = CompanySubscription::create([
        'organization_id' => $orgMine->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 5,
    ]);

    $orgOther = Organization::factory()->customer()->create([
        'name' => 'Other Rep Account',
        'assigned_user_id' => $repOther->id,
    ]);
    $subOther = CompanySubscription::create([
        'organization_id' => $orgOther->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 5,
    ]);

    // Rep sees only their account in renewals queue
    $this->actingAs($this->rep)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'My Rep Account')
        );

    /*
     * Reading the queue and changing a contract are separate rights now.
     *
     * A rep sees their own book and can do nothing to it — renewing, repricing
     * and cancelling need `cs.accounts.manage`. Under the old route the same
     * rep could raise a priced deal against their own account, which is how a
     * fabricated 50,000 ARR figure reached the pipeline unreviewed.
     */
    $this->actingAs($this->rep)
        ->post(route('company.customer-success.subscriptions.renew', $subMine), ['months' => 12])
        ->assertForbidden();

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $subMine), ['months' => 12])
        ->assertRedirect();

    expect($subMine->fresh()->ends_at->toDateString())
        ->toBe(now()->addDays(15)->addMonths(12)->toDateString());
});

it('strictly excludes provider organization from the renewals workspace and from contract changes', function () {
    $providerSub = CompanySubscription::create([
        'organization_id' => $this->vendorOrg->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 50,
    ]);

    // Provider is excluded from renewals index
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 0)
            ->has('renewals', 0)
        );

    /* And the provider's own subscription cannot be renewed from here either. */
    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $providerSub), ['months' => 12])
        ->assertNotFound();
});

it('calculates measured retention rate in bounded queries with zero N+1', function () {
    $window = now()->subMonths(6);

    // Create 20 eligible subscriptions in the past
    // 12 are retained (have another active subscription or future ends_at)
    // 8 are not retained
    for ($i = 1; $i <= 20; $i++) {
        $org = Organization::factory()->customer()->create([
            'name' => "Cohort Org {$i}",
            'assigned_user_id' => $this->manager->id,
        ]);

        $pastSub = CompanySubscription::create([
            'organization_id' => $org->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(18),
            'ends_at' => now()->subMonths(3), // ended 3 months ago (within lookback)
            'max_users' => 5,
        ]);

        if ($i <= 12) {
            // Retained by having another active subscription extending into the future
            CompanySubscription::create([
                'organization_id' => $org->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subMonths(3),
                'ends_at' => now()->addMonths(9),
                'max_users' => 5,
            ]);
        }
    }

    DB::enableQueryLog();

    $service = app(CommercialRenewalService::class);
    $payload = $service->getRenewalsPayload($this->manager);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // 12 retained out of 20 eligible = 60.0%
    expect($payload['metrics']['retention_available'])->toBeTrue()
        ->and($payload['metrics']['retention_rate'])->toBe(60.0)
        ->and($payload['metrics']['retention_sample_size'])->toBe(20)
        ->and($payload['metrics']['retention_unavailable_reason'])->toBeNull();

    // The total queries executed must be bounded and strictly independent of the 20 cohort accounts
    // In the old implementation, this would have run 20+ N+1 queries.
    // In the new implementation, it executes <= 20 queries total for the entire page & metrics.
    expect(count($queries))->toBeLessThanOrEqual(20);
});

it('proves query count remains constant and bounded when dataset scales', function () {
    $plan = CompanyPlan::factory()->create(['billing_period' => 'monthly']);

    // Seed 50 active subscriptions
    for ($i = 1; $i <= 50; $i++) {
        $org = Organization::factory()->customer()->create([
            'name' => "Scale Account {$i}",
            'assigned_user_id' => $this->manager->id,
        ]);
        CompanySubscription::create([
            'organization_id' => $org->id,
            'company_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->addDays(20),
            'max_users' => 5,
        ]);
    }

    DB::enableQueryLog();

    $response = $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk();

    // Even with 50 subscriptions and 25 rendered on page, total queries executed is bounded:
    // (session/auth + metrics aggregates + paginated query + count + eager loads + batch user count + batch branch count)
    // Old implementation without batching and with per-row entitlement checks would execute > 80 queries!
    expect(count($queries))->toBeLessThanOrEqual(25);
});

it('passes server-provided can permissions to the frontend', function () {
    $org = Organization::factory()->customer()->create([
        'name' => 'Auth Clinic',
        'assigned_user_id' => $this->rep->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 5,
    ]);

    /*
     * The page now says whether this reader may *change a contract*, not
     * whether they may raise a CRM deal. Renewing an existing customer is
     * something Customer Success does itself, and a sales rep holding
     * `create leads` has no business rewriting a customer's subscription.
     */
    $this->actingAs($this->rep)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.manageContract', false)
        );

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.manageContract', true)
            ->has('plans')
        );

    // User without create leads permission
    Permission::findOrCreate('view-renewals', 'web');

    $viewer = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    // Assign only view-renewals permission
    $viewer->givePermissionTo('view-renewals');

    $this->actingAs($viewer)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.manageContract', false)
        );
});

it('records a renewal against the contract rather than in the pipeline', function () {
    $customerOrg = Organization::factory()->customer()->create([
        'name' => 'Metro Smiles',
        'contact_person' => 'Dr. Metro',
        'email' => 'metro@example.com',
        'mobile' => '+919876543299',
        'assigned_user_id' => $this->manager->id,
    ]);

    $sub = CompanySubscription::create([
        'organization_id' => $customerOrg->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 8,
        'mrr' => 12000,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $sub), ['months' => 12])
        ->assertRedirect();

    /*
     * The audit trail lives on the contract, where the next person looking at
     * this customer will actually find it. It used to be a note saying a deal
     * had been opened in another module.
     */
    $event = $sub->events()->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->event)->toBe('renewed')
        ->and(Lead::where('customer_organization_id', $customerOrg->id)->exists())->toBeFalse();
});

it('provides complete renewal telemetry and state for Customer Success users', function () {
    $plan = CompanyPlan::factory()->create([
        'name' => 'Enterprise Multi-Specialty',
        'billing_period' => 'yearly',
    ]);

    $customerOrg = Organization::factory()->customer()->create([
        'name' => 'Dental Care Hub',
        'contact_person' => 'Dr. Anil Mehta',
        'email' => 'anil@dentalcarehub.com',
        'mobile' => '+919876543210',
        'assigned_user_id' => $this->manager->id,
        'expansion_opportunity' => true,
        'renewal_probability' => 85,
    ]);

    // Create 3 clinic branches
    Branch::factory()->count(3)->create([
        'organization_id' => $customerOrg->id,
    ]);

    // Create 4 active users in the org
    User::factory()->count(4)->create([
        'organization_id' => $customerOrg->id,
    ]);

    CompanySubscription::create([
        'organization_id' => $customerOrg->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subYear(),
        'ends_at' => now()->addDays(22),
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    /*
     * Project it, the way every write path in the application does after
     * changing a contract. The queue reads the projection rather than the
     * subscription — that is what makes it one query at a hundred and twenty
     * thousand accounts — so a subscription conjured straight into the database
     * is a state the running system never produces.
     */
    app(AccountSnapshotService::class)->refresh($customerOrg);

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('renewals.0', fn ($row) => $row
                ->where('companyName', 'Dental Care Hub')
                ->where('contactName', 'Dr. Anil Mehta')
                ->where('contactEmail', 'anil@dentalcarehub.com')
                ->where('planName', 'Enterprise Multi-Specialty')
                ->where('daysToRenewal', fn ($days) => in_array($days, [21, 22]))
                ->where('renewalProbability', 85)
                ->where('retentionStatus', 'Expansion Opportunity')
                ->where('expansionTarget', 'Expansion opportunity flagged')
                ->where('purchasedSeats', 10)
                ->where('usedSeats', 4)
                ->where('seatUtilizationPct', 40)
                ->where('activeBranches', 3)
                ->where('branchCount', 5)
                ->where('branchUtilizationPct', 60)
                ->has('currentArr')
                ->has('renewalDate')
                ->etc()
            )
        );
});

it('handles exact boundary dates at 0, 30, 60, and 90 days with single consistent timestamp', function () {
    $now = now();
    $this->travelTo($now);

    $createSubAt = function ($endsAt, string $name) {
        $org = Organization::factory()->customer()->create([
            'name' => $name,
            'assigned_user_id' => $this->manager->id,
        ]);

        return CompanySubscription::create([
            'organization_id' => $org->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subYear(),
            'ends_at' => $endsAt,
            'mrr' => 1000.00,
        ]);
    };

    // Subscriptions at exact boundary dates
    $createSubAt($now->copy()->subSecond(), 'Overdue By 1s');
    $createSubAt($now, 'Exactly Now (0 Days)');
    $createSubAt($now->copy()->addDays(30), 'Exactly 30 Days');
    $createSubAt($now->copy()->addDays(30)->addSecond(), '30 Days + 1s');
    $createSubAt($now->copy()->addDays(60), 'Exactly 60 Days');
    $createSubAt($now->copy()->addDays(60)->addSecond(), '60 Days + 1s');
    $createSubAt($now->copy()->addDays(90), 'Exactly 90 Days');
    $createSubAt($now->copy()->addDays(90)->addSecond(), '90 Days + 1s');

    // 1. Overdue: ends_at < now
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'Overdue By 1s')
        );

    // 2. 0-30 days: ends_at >= now AND ends_at <= now + 30 days
    // Matches: 'Exactly Now (0 Days)' and 'Exactly 30 Days' = 2 items
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => '0-30']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 2)
            ->where('renewals.0.companyName', 'Exactly Now (0 Days)')
            ->where('renewals.1.companyName', 'Exactly 30 Days')
        );

    // 3. 31-60 days: ends_at > now + 30 days AND ends_at <= now + 60 days
    // Matches: '30 Days + 1s' and 'Exactly 60 Days' = 2 items
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => '31-60']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 2)
            ->where('renewals.0.companyName', '30 Days + 1s')
            ->where('renewals.1.companyName', 'Exactly 60 Days')
        );

    // 4. 61-90 days: ends_at > now + 60 days AND ends_at <= now + 90 days
    // Matches: '60 Days + 1s' and 'Exactly 90 Days' = 2 items
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['horizon' => '61-90']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 2)
            ->where('renewals.0.companyName', '60 Days + 1s')
            ->where('renewals.1.companyName', 'Exactly 90 Days')
        );
});

it('enforces canonical recorded MRR and ARR without seat-based fallbacks', function () {
    // Org A with recorded MRR 25,000
    $orgA = Organization::factory()->customer()->create([
        'name' => 'Recorded MRR Clinic',
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgA->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 10,
        'mrr' => 25000.00,
    ]);

    // Org B with unrecorded MRR (null)
    $orgB = Organization::factory()->customer()->create([
        'name' => 'Unrecorded MRR Clinic',
        'assigned_user_id' => $this->manager->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $orgB->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(20),
        'max_users' => 10,
        'mrr' => null,
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Metric strictly aggregates recorded MRR (25,000, not 25,000 + 15,000)
            ->where('metrics.expected_renewal_mrr', fn ($val) => (float) $val === 25000.0)
            ->where('metrics.recorded_subscriptions_count', 1)
            ->where('metrics.unrecorded_subscriptions_count', 1)
            // Org A has canonical recorded MRR and ARR
            ->has('renewals', 2)
            ->where('renewals.0.companyName', 'Recorded MRR Clinic')
            ->where('renewals.0.mrr', '₹25,000 / mo')
            ->where('renewals.0.mrrRaw', 25000)
            ->where('renewals.0.arr', '₹300,000')
            ->where('renewals.0.arrRaw', 300000)
            ->where('renewals.0.isMrrRecorded', true)
            // Org B keeps null/unrecorded without inventing seat-based values
            ->where('renewals.1.companyName', 'Unrecorded MRR Clinic')
            ->where('renewals.1.mrr', '—')
            ->where('renewals.1.mrrRaw', null)
            ->where('renewals.1.arr', '—')
            ->where('renewals.1.arrRaw', null)
            ->where('renewals.1.isMrrRecorded', false)
        );
});

it('renews on the recorded figure and leaves an unrecorded one unrecorded', function () {
    // 1. Subscription with recorded MRR
    $orgA = Organization::factory()->customer()->create([
        'name' => 'Alpha Clinic',
        'assigned_user_id' => $this->manager->id,
    ]);
    $subA = CompanySubscription::create([
        'organization_id' => $orgA->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 10,
        'mrr' => 15000.00,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $subA), ['months' => 12])
        ->assertRedirect();

    /* Another year on what was agreed. A renewal is not a repricing. */
    expect((float) $subA->fresh()->mrr)->toBe(15000.00);

    // 2. Subscription with unrecorded MRR
    $orgB = Organization::factory()->customer()->create([
        'name' => 'Beta Clinic',
        'assigned_user_id' => $this->manager->id,
    ]);
    $subB = CompanySubscription::create([
        'organization_id' => $orgB->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(15),
        'max_users' => 5,
        'mrr' => null,
    ]);

    $this->actingAs($this->manager)
        ->post(route('company.customer-success.subscriptions.renew', $subB), ['months' => 12])
        ->assertRedirect();

    /*
     * Still nothing. An unrecorded figure is unknown, not zero, and renewing a
     * contract is not the moment to guess one — the old path filled the gap
     * with a flat 50,000 and wrote it into the pipeline as fact.
     */
    expect($subB->fresh()->mrr)->toBeNull()
        ->and($subB->fresh()->ends_at->toDateString())
        ->toBe(now()->addDays(15)->addMonths(12)->toDateString());
});

it('prioritizes CustomerSuccessSnapshot as the canonical read model over Organization columns', function () {
    $org = Organization::factory()->customer()->create([
        'name' => 'Snapshot Priority Hospital',
        'assigned_user_id' => $this->manager->id,
        // Legacy organization columns (stale)
        'health_score' => 40,
        'churn_risk' => CustomerChurnRisk::High,
        'renewal_probability' => 30,
        'expansion_opportunity' => false,
    ]);

    CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(25),
        'max_users' => 20,
        'mrr' => 35000.00,
    ]);

    // CustomerSuccessSnapshot has fresh computed telemetry
    projectSnapshot($org->id, [
        'health_score' => 95,
        'churn_risk' => CustomerChurnRisk::Low,
        'renewal_probability' => 92,
        'expansion_opportunity' => true,
        'purchased_seats' => 20,
        'used_seats' => 18,
        'seat_utilization_pct' => 90,
        'purchased_branches' => 5,
        'active_branches' => 4,
        'branch_utilization_pct' => 80,
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('renewals.0', fn ($row) => $row
                ->where('companyName', 'Snapshot Priority Hospital')
                // Values come from CustomerSuccessSnapshot, NOT the stale Organization columns
                ->where('healthScore', 95)
                ->where('churnRisk', 'Low')
                ->where('renewalProbability', 92)
                ->where('retentionStatus', 'Expansion Opportunity')
                ->where('usedSeats', 18)
                ->where('purchasedSeats', 20)
                ->where('seatUtilizationPct', 90)
                ->where('activeBranches', 4)
                ->where('branchCount', 5)
                ->where('branchUtilizationPct', 80)
                ->etc()
            )
        );
});

it('filters renewals by tenant-scoped owner and provides scoped eligibleOwners', function () {
    $repOther = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'name' => 'Specialist Rep Vikram',
        'user_type' => UserType::CompanyUser,
    ]);
    $repOther->assignRole(Role::findByName('Sales Representative', 'web'));

    $org1 = Organization::factory()->customer()->create([
        'name' => 'Rep 1 Account',
        'assigned_user_id' => $this->rep->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $org1->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(20),
        'mrr' => 10000.00,
    ]);

    $org2 = Organization::factory()->customer()->create([
        'name' => 'Rep 2 Account',
        'assigned_user_id' => $repOther->id,
    ]);
    CompanySubscription::create([
        'organization_id' => $org2->id,
        'status' => SubscriptionStatus::Active,
        'ends_at' => now()->addDays(20),
        'mrr' => 15000.00,
    ]);

    // Manager sees both and receives eligibleOwners in props
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 2)
            ->has('eligibleOwners', 2)
        );

    // Filter by rep 1
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['owner_id' => $this->rep->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'Rep 1 Account')
        );

    // Filter by account_id (deep linking)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['account_id' => $org2->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1)
            ->where('renewals.0.companyName', 'Rep 2 Account')
        );
});

it('scales to 1,000+ subscriptions with constant bounded queries and zero N+1', function () {
    $plan = CompanyPlan::factory()->create(['billing_period' => 'yearly']);

    // Seed 1,000 customer subscriptions in bulk for speed
    $orgRows = [];
    $subRows = [];
    $snapshotRows = [];
    $now = now();

    for ($i = 1; $i <= 1000; $i++) {
        $orgRows[] = [
            'id' => 10000 + $i,
            'name' => sprintf('Enterprise Scale Group %04d', $i),
            'role' => 'customer',
            'assigned_user_id' => $this->manager->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $subRows[] = [
            'id' => 10000 + $i,
            'organization_id' => 10000 + $i,
            'company_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => $now->copy()->subMonths(6),
            'ends_at' => $now->copy()->addDays($i % 120),
            'max_users' => 10,
            'max_branches' => 2,
            'mrr' => 15000.00,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $snapshotRows[] = [
            'organization_id' => 10000 + $i,
            'health_score' => 85,
            'churn_risk' => 'Low',
            'renewal_probability' => 80,
            'purchased_seats' => 10,
            'used_seats' => 6,
            'seat_utilization_pct' => 60,
            'purchased_branches' => 2,
            'active_branches' => 1,
            'branch_utilization_pct' => 50,
            'has_subscription' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    DB::table('organizations')->insert($orgRows);
    DB::table('company_subscriptions')->insert($subRows);
    DB::table('customer_success_snapshots')->insert($snapshotRows);

    DB::enableQueryLog();

    $response = $this->actingAs($this->manager)
        ->get(route('company.customer-success.renewals.index', ['per_page' => 25]));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.total', 1000)
            ->where('paginator.per_page', 25)
            ->has('renewals', 25)
        );

    // Query count remains strictly bounded (<= 25 queries) despite 1,000 subscriptions
    expect(count($queries))->toBeLessThanOrEqual(25);
});
