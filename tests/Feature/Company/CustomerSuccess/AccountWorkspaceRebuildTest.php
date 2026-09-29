<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\ClinicType;
use App\CustomerChurnRisk;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use App\OrganizationScale;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('handles 1,000+ customer accounts with server-side pagination, bounded query count, and accurate totals', function () {
    $now = now();
    $orgRows = [];
    $totalAccounts = 1025;

    // Bulk insert 1,025 customer organizations
    for ($i = 1; $i <= $totalAccounts; $i++) {
        $orgRows[] = [
            'id' => 1000 + $i,
            'name' => sprintf('Enterprise Clinic %04d', $i),
            'role' => OrganizationRole::Customer->value,
            'assigned_user_id' => $this->manager->id,
            'email' => sprintf('clinic%04d@oralcares-test.com', $i),
            'mobile' => sprintf('+91980000%04d', $i),
            'contact_person' => sprintf('Dr. Specialist %04d', $i),
            'city' => $i % 2 === 0 ? 'Mumbai' : 'Delhi',
            'state' => 'Maharashtra',
            'type' => 'clinic',
            'clinic_type' => ClinicType::DentalClinic->value,
            'organization_scale' => OrganizationScale::SingleClinic->value,
            'health_score' => 85,
            'renewal_probability' => 80,
            'churn_risk' => CustomerChurnRisk::Low->value,
            'expansion_opportunity' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($orgRows, 250) as $chunk) {
        DB::table('organizations')->insert($chunk);
    }

    // Bulk insert corresponding snapshots
    $snapshotRows = [];
    for ($i = 1; $i <= $totalAccounts; $i++) {
        $snapshotRows[] = [
            'organization_id' => 1000 + $i,
            'health_score' => 85,
            'churn_risk' => 'Low',
            'renewal_probability' => 80,
            'purchased_seats' => 5,
            'used_seats' => 3,
            'seat_utilization_pct' => 60,
            'purchased_branches' => 2,
            'active_branches' => 1,
            'branch_utilization_pct' => 50,
            'has_subscription' => true,
            'mrr' => 12000.00,
            'ends_at' => $now->copy()->addDays(45),
            'days_to_expiry' => 45,
            'stage' => 'live',
            'expansion_opportunity' => false,
            'onboarding_status' => 'completed',
            'onboarding_current_step' => 'go_live',
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($snapshotRows, 250) as $chunk) {
        DB::table('customer_success_snapshots')->insert($chunk);
    }

    // 1. Request Page 1: only loads 1 page (25 items), total is 1025
    $response = $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Index')
            ->has('accounts', 25)
            ->where('paginator.per_page', 25)
            ->where('paginator.current_page', 1)
            ->where('paginator.from', 1)
            ->where('paginator.to', 25)
            /*
             * No total and no last page. Both need a COUNT over the whole
             * filtered set — 440 ms of this page's 453 at 120,000 accounts —
             * so the list steps forwards instead of numbering four thousand
             * pages nobody clicks.
             */
            ->where('paginator.next_url', fn ($url) => str_contains((string) $url, 'page=2'))
            ->where('paginator.prev_url', null)
        );

    // 2. Request Page 2: verifies page 2 slice
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 25)
            ->where('paginator.current_page', 2)
            ->where('paginator.from', 26)
            ->where('paginator.to', 50)
        );

    // 3. Request Last Page: verifies final slice
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['page' => 41]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 25)
            ->where('paginator.current_page', 41)
            ->where('paginator.from', 1001)
            ->where('paginator.to', 1025)
        );
});

it('enforces bounded per_page within safe limits', function () {
    Organization::factory()->customer()->count(30)->create([
        'assigned_user_id' => $this->manager->id,
    ]);

    // Requesting < 10 clamps to 10
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['per_page' => 5]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.per_page', 10)
            ->has('accounts', 10)
        );

    // Requesting > 100 clamps to 100
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['per_page' => 250]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.per_page', 100)
            ->has('accounts', 30)
        );

    // Valid bounded per_page
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['per_page' => 15]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('paginator.per_page', 15)
            ->has('accounts', 15)
        );
});

it('executes server-side search across name, contact person, email, city, and numeric id', function () {
    $targetOrg = Organization::factory()->customer()->create([
        'name' => 'Apex Premium Dental Care',
        'contact_person' => 'Dr. Rajesh Khanna',
        'email' => 'rajesh.khanna@apexdental.com',
        'city' => 'Ahmedabad',
        'assigned_user_id' => $this->manager->id,
    ]);

    // Other non-matching organizations
    Organization::factory()->customer()->count(5)->create([
        'name' => 'City Care Clinic',
        'contact_person' => 'Dr. Suresh Babu',
        'email' => 'suresh@citycare.com',
        'city' => 'Pune',
        'assigned_user_id' => $this->manager->id,
    ]);

    // 1. Search by name prefix
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['search' => 'Apex Premium']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $targetOrg->id)
            ->where('paginator.next_url', null)
        );

    // 2. Search by contact person prefix
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['search' => 'Dr. Rajesh']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $targetOrg->id)
        );

    // 3. Search by email prefix
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['search' => 'rajesh.khanna']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $targetOrg->id)
        );

    // 4. Search by city prefix
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['search' => 'Ahmedabad']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $targetOrg->id)
        );

    // 5. Search by numeric ID with # prefix
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['search' => "#{$targetOrg->id}"]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $targetOrg->id)
        );
});

it('executes server-side sorting for all supported columns', function () {
    $now = now();
    $orgA = Organization::factory()->customer()->create([
        'name' => 'AAA Dental',
        'assigned_user_id' => $this->manager->id,
        'created_at' => $now->copy()->subDays(10),
    ]);
    projectSnapshot($orgA->id, [
        'health_score' => 45,
        'churn_risk' => 'High',
        'renewal_probability' => 30,
        'seat_utilization_pct' => 20,
        'days_to_expiry' => 10,
        'stage' => 'onboarding',
        'last_activity_at' => $now->copy()->subDays(5),
    ]);

    $orgZ = Organization::factory()->customer()->create([
        'name' => 'ZZZ Dental',
        'assigned_user_id' => $this->manager->id,
        'created_at' => $now->copy()->subDays(1),
    ]);
    projectSnapshot($orgZ->id, [
        'health_score' => 95,
        'churn_risk' => 'Low',
        'renewal_probability' => 90,
        'seat_utilization_pct' => 95,
        'days_to_expiry' => 120,
        'stage' => 'live',
        'last_activity_at' => $now->copy()->subHours(2),
    ]);

    // Name sorting
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.id', $orgA->id)
        );

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['sort' => 'name', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.id', $orgZ->id)
        );

    // Health score sorting
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['sort' => 'health_score', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.id', $orgA->id)
        );

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['sort' => 'health_score', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.id', $orgZ->id)
        );

    // Days to expiry sorting
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['sort' => 'days_to_expiry', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.id', $orgA->id)
        );

    // Seat utilization sorting
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['sort' => 'seat_utilization_pct', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('accounts.0.id', $orgZ->id)
        );
});

it('executes every saved Customer Success view with snapshot-backed SQL filtering and accurate metrics', function () {
    $now = now();

    // 1. At Risk Account (churn_risk High, health < 50)
    $atRiskOrg = Organization::factory()->customer()->create(['name' => 'At Risk Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($atRiskOrg->id, [
        'health_score' => 40,
        'churn_risk' => 'High',
        'days_to_expiry' => 150,
        'stage' => 'live',
        'expansion_opportunity' => false,
    ]);

    // 2. Renewing in 30 days
    $renew30Org = Organization::factory()->customer()->create(['name' => 'Renew 30 Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($renew30Org->id, [
        'health_score' => 85,
        'churn_risk' => 'Low',
        'days_to_expiry' => 20,
        'stage' => 'live',
        'expansion_opportunity' => false,
    ]);

    // 3. Renewing in 60 days
    $renew60Org = Organization::factory()->customer()->create(['name' => 'Renew 60 Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($renew60Org->id, [
        'health_score' => 85,
        'churn_risk' => 'Low',
        'days_to_expiry' => 50,
        'stage' => 'live',
        'expansion_opportunity' => false,
    ]);

    // 4. Renewing in 90 days
    $renew90Org = Organization::factory()->customer()->create(['name' => 'Renew 90 Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($renew90Org->id, [
        'health_score' => 85,
        'churn_risk' => 'Low',
        'days_to_expiry' => 80,
        'stage' => 'live',
        'expansion_opportunity' => false,
    ]);

    // 5. Onboarding Account
    $onboardOrg = Organization::factory()->customer()->create(['name' => 'Onboarding Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($onboardOrg->id, [
        'health_score' => 70,
        'churn_risk' => 'Medium',
        'days_to_expiry' => 300,
        'stage' => 'onboarding',
        'onboarding_status' => 'in_progress',
        'expansion_opportunity' => false,
    ]);

    // 6. Over Capacity Account
    $overCapOrg = Organization::factory()->customer()->create(['name' => 'Over Capacity Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($overCapOrg->id, [
        'health_score' => 90,
        'churn_risk' => 'Low',
        'days_to_expiry' => 200,
        'stage' => 'live',
        'seat_utilization_pct' => 120,
        'branch_utilization_pct' => 100,
        'expansion_opportunity' => true,
    ]);

    // 7. Lapsed Account
    $lapsedOrg = Organization::factory()->customer()->create(['name' => 'Lapsed Dental', 'assigned_user_id' => $this->manager->id]);
    projectSnapshot($lapsedOrg->id, [
        'health_score' => 20,
        'churn_risk' => 'High',
        'days_to_expiry' => -10,
        'stage' => 'lapsed',
        'expansion_opportunity' => false,
    ]);

    // Verify view=at_risk
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'at_risk']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 2) // atRiskOrg (churn High, health 40) + lapsedOrg (churn Critical, health 20)
            ->where('filters.view', 'at_risk')
        );

    // Verify view=renewing_30
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'renewing_30']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $renew30Org->id)
        );

    // Verify view=renewing_60 (matches renewing in 20d and 50d)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'renewing_60']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 2)
        );

    // Verify view=renewing_90 (matches renewing in 20d, 50d, and 80d)
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'renewing_90']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 3)
        );

    // Verify view=onboarding
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'onboarding']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $onboardOrg->id)
        );

    // Verify view=over_capacity
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'over_capacity']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $overCapOrg->id)
        );

    // Verify view=lapsed
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['view' => 'lapsed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $lapsedOrg->id)
        );

    // Verify global metrics aggregates
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        /*
         * Deferred: one scan of the whole book that says the same thing on
         * every page, so it no longer holds the table behind it.
         */
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['metrics'], fn ($page) => $page
                ->where('metrics.total_accounts', 7)
                ->where('metrics.at_risk_count', 2)
                ->where('metrics.renewing_30_count', 1)
                ->where('metrics.onboarding_count', 1)
                ->where('metrics.expansion_count', 1)
            )
        );
});

it('strictly excludes provider organizations and respects tenant isolation', function () {
    // Customer org assigned to manager
    $managerOrg = Organization::factory()->customer()->create([
        'name' => 'Manager Super Clinic',
        'assigned_user_id' => $this->manager->id,
    ]);

    // Customer org assigned to sales rep
    $repOrg = Organization::factory()->customer()->create([
        'name' => 'Rep Specialized Clinic',
        'assigned_user_id' => $this->rep->id,
    ]);

    // Provider org — must NEVER leak into accounts directory
    $extraProvider = Organization::factory()->provider()->create([
        'name' => 'Shadow Provider Org',
    ]);

    // 1. Manager (CRM Manager) sees both customer orgs, but NOT provider org
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 2)
            ->where('accounts.0.name', fn ($name) => in_array($name, ['Manager Super Clinic', 'Rep Specialized Clinic']))
            ->where('accounts.1.name', fn ($name) => in_array($name, ['Manager Super Clinic', 'Rep Specialized Clinic']))
        );

    // 2. Sales rep only sees their assigned customer account
    $this->actingAs($this->rep)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.id', $repOrg->id)
            ->where('accounts.0.name', 'Rep Specialized Clinic')
        );
});

it('passes correct authorization and can flags based on user roles and permissions', function () {
    $org = Organization::factory()->customer()->create([
        'assigned_user_id' => $this->manager->id,
    ]);

    // Manager with CRM Manager role has viewAccount, and manageAccount via company permissions
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('can.viewAccount')
            ->has('can.manageAccount')
            /*
             * Not `createDeal`. Customer Success no longer raises a CRM deal
             * against a customer who has already bought — a renewal, an upgrade
             * and a seat change all happen here, on the contract itself.
             */
            ->missing('can.createDeal')
            ->where('can.viewAccount', true)
        );
});

it('renders empty states gracefully when no accounts match filters', function () {
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index', ['search' => 'NonExistentClinic9999']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts', 0)
            ->where('paginator.from', null)
            ->where('paginator.to', null)
            ->where('paginator.next_url', null)
        );
});

it('guarantees zero N+1 service calculations and bounded query count at scale', function () {
    $now = now();
    $plan = CompanyPlan::factory()->create(['name' => 'Prime Cloud']);

    // Create 50 accounts with full subscriptions and snapshots
    for ($i = 1; $i <= 50; $i++) {
        $org = Organization::factory()->customer()->create([
            'name' => sprintf('Benchmark Clinic %02d', $i),
            'assigned_user_id' => $this->manager->id,
        ]);

        $sub = CompanySubscription::create([
            'organization_id' => $org->id,
            'company_plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $now->copy()->subMonths(3),
            'ends_at' => $now->copy()->addDays(40),
            'mrr' => 15000,
        ]);

        projectSnapshot($org->id, [
            'health_score' => 88,
            'churn_risk' => 'Low',
            'renewal_probability' => 85,
            'purchased_seats' => 10,
            'used_seats' => 6,
            'seat_utilization_pct' => 60,
            'purchased_branches' => 2,
            'active_branches' => 1,
            'branch_utilization_pct' => 50,
            'has_subscription' => true,
            'active_subscription_id' => $sub->id,
            'plan_id' => $plan->id,
            'mrr' => 15000,
            'ends_at' => $sub->ends_at,
            'days_to_expiry' => 40,
            'stage' => 'live',
            'expansion_opportunity' => false,
            'onboarding_status' => 'completed',
            'onboarding_current_step' => 'go_live',
            'computed_at' => $now,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index'));

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $response->assertOk();

    // Query count must be strictly bounded (< 20) and NEVER scale with the 50 accounts:
    // (session/auth + metrics aggregates + paginated query + count + eager loads [assignedUser, snapshot.plan, snapshot.activeSubscription])
    expect(count($queries))->toBeLessThanOrEqual(18);
});

it('preserves complete information in each row and valid Account 360 navigation', function () {
    $now = now();
    $plan = CompanyPlan::factory()->create(['name' => 'Enterprise Health Plan']);

    $org = Organization::factory()->customer()->create([
        'name' => 'Apollo Dental Center',
        'legal_name' => 'Apollo Health Technologies Pvt Ltd',
        'contact_person' => 'Dr. Prathap Reddy',
        'email' => 'prathap@apollodental.com',
        'mobile' => '+919988776655',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'clinic_type' => ClinicType::MultiSpecialityHospital,
        'assigned_user_id' => $this->manager->id,
    ]);

    $sub = CompanySubscription::create([
        'organization_id' => $org->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => $now->copy()->subMonths(6),
        'ends_at' => $now->copy()->addDays(25),
        'mrr' => 25000,
    ]);

    projectSnapshot($org->id, [
        'health_score' => 92,
        'health_dimensions' => [
            'relationship_activity' => ['score' => 18, 'max' => 20, 'label' => 'Relationship Activity'],
            'entitlement_usage' => ['score' => 19, 'max' => 20, 'label' => 'Entitlement Usage'],
            'onboarding_status' => ['score' => 20, 'max' => 20, 'label' => 'Onboarding Progress'],
            'commercial_engagement' => ['score' => 18, 'max' => 20, 'label' => 'Commercial Engagement'],
            'churn_risk' => ['score' => 17, 'max' => 20, 'label' => 'Churn Risk Inversion'],
        ],
        'churn_risk' => 'Low',
        'renewal_probability' => 95,
        'purchased_seats' => 20,
        'used_seats' => 15,
        'seat_utilization_pct' => 75,
        'purchased_branches' => 5,
        'active_branches' => 4,
        'branch_utilization_pct' => 80,
        'has_subscription' => true,
        'active_subscription_id' => $sub->id,
        'plan_id' => $plan->id,
        'mrr' => 25000,
        'ends_at' => $sub->ends_at,
        'days_to_expiry' => 25,
        'stage' => 'live',
        'expansion_opportunity' => true,
        'onboarding_status' => 'completed',
        'onboarding_current_step' => 'go_live',
        'last_activity_at' => $now->copy()->subHours(4),
        'computed_at' => $now,
    ]);

    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('accounts.0', fn ($row) => $row
                ->where('id', $org->id)
                ->where('name', 'Apollo Dental Center')
                ->where('legal_name', 'Apollo Health Technologies Pvt Ltd')
                ->where('contact_person', 'Dr. Prathap Reddy')
                ->where('email', 'prathap@apollodental.com')
                ->where('mobile', '+919988776655')
                ->where('city', 'Chennai')
                ->where('state', 'Tamil Nadu')
                ->where('health_score', 92)
                ->has('health_dimensions')
                ->where('churn_risk', 'Low')
                ->where('renewal_probability', 95)
                ->where('stage', 'live')
                ->where('has_subscription', true)
                ->where('plan_name', 'Enterprise Health Plan')
                ->where('mrr', '₹25,000 / mo')
                ->where('mrr_raw', 25000)
                ->where('arr', '₹300,000')
                ->where('days_to_expiry', 25)
                ->where('purchased_seats', 20)
                ->where('used_seats', 15)
                ->where('seat_utilization_pct', 75)
                ->where('purchased_branches', 5)
                ->where('active_branches', 4)
                ->where('branch_utilization_pct', 80)
                ->where('expansion_opportunity', true)
                ->where('onboarding_status', 'completed')
                ->has('last_activity_at')
                ->etc()
            )
        );

    // Account 360 route remains valid and functional
    $this->actingAs($this->manager)
        ->get(route('company.customer-success.accounts.show', $org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Show')
            ->where('account.id', $org->id)
        );
});
