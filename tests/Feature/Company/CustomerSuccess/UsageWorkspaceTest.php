<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\Organization;
use App\Models\User;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    // Provider organization (must be strictly excluded from customer usage)
    $this->providerOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares HQ Provider',
    ]);

    // Master Admin
    $this->masterAdmin = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::MasterAdmin,
    ]);

    // Ensure view-usage permission is present
    Permission::findOrCreate('view-usage', 'web');
    Permission::findOrCreate('view-operations', 'web');
    Permission::findOrCreate('view-organizations', 'web');

    // CS User with view-usage and view-operations (manager scope)
    $this->csUser = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->csUser->givePermissionTo(['view-usage', 'view-operations', 'view-organizations']);

    // Scoped Rep with view-usage but without view-organizations
    $this->scopedRep = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->scopedRep->givePermissionTo('view-usage');

    // Unprivileged User
    $this->unprivilegedUser = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);

    // Plan
    $this->plan = CompanyPlan::factory()->create([
        'name' => 'Growth Enterprise',
        'key' => 'growth_enterprise',
        'billing_period' => 'monthly',
        'price' => 15000,
        'max_users' => 10,
        'max_branches' => 3,
        'max_doctors' => 5,
    ]);
});

test('usage workspace requires view-usage permission', function () {
    // Unauthenticated -> redirect to login
    $this->get(route('company.customer-success.usage.index'))
        ->assertRedirect('/company/login');

    // Unprivileged user -> 403 Forbidden
    $this->actingAs($this->unprivilegedUser)
        ->get(route('company.customer-success.usage.index'))
        ->assertForbidden();

    // Authorized user -> 200 OK
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Lifecycle/UsageWorkspace')
            ->has('rows')
            ->has('paginator')
            ->has('summary')
        );
});

test('provider and vendor organizations are strictly excluded from usage', function () {
    // Create customer organization
    $customerOrg = Organization::factory()->create([
        'name' => 'Valid Dental Care',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($customerOrg->id, [
        'purchased_seats' => 10,
        'used_seats' => 5,
        'seat_utilization_pct' => 50,
        'purchased_branches' => 2,
        'active_branches' => 1,
        'branch_utilization_pct' => 50,
        'has_subscription' => true,
    ]);

    // Create provider snapshot
    projectSnapshot($this->providerOrg->id, [
        'purchased_seats' => 100,
        'used_seats' => 50,
        'seat_utilization_pct' => 50,
        'purchased_branches' => 10,
        'active_branches' => 5,
        'branch_utilization_pct' => 50,
        'has_subscription' => true,
    ]);

    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Valid Dental Care')
            ->where('summary.total_accounts', 1)
        );
});

test('scoped reps see only their assigned customer accounts', function () {
    $myOrg = Organization::factory()->create([
        'name' => 'My Assigned Clinic',
        'role' => 'customer',
        'assigned_user_id' => $this->scopedRep->id,
    ]);
    projectSnapshot($myOrg->id, [
        'purchased_seats' => 10,
        'used_seats' => 6,
        'seat_utilization_pct' => 60,
        'has_subscription' => true,
    ]);

    $otherOrg = Organization::factory()->create([
        'name' => 'Other Rep Clinic',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($otherOrg->id, [
        'purchased_seats' => 20,
        'used_seats' => 10,
        'seat_utilization_pct' => 50,
        'has_subscription' => true,
    ]);

    // Scoped rep sees only their assigned organization
    $this->actingAs($this->scopedRep)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'My Assigned Clinic')
            ->where('summary.total_accounts', 1)
        );
});

test('accurate capacity telemetry and expansion detection', function () {
    $org = Organization::factory()->create([
        'name' => 'Max Dental Hospital',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);

    $sub = CompanySubscription::create([
        'organization_id' => $org->id,
        'company_plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->addDays(25),
        'mrr' => 15000,
        'max_users' => 10,
        'max_branches' => 2,
        'max_doctors' => 4,
    ]);

    projectSnapshot($org->id, [
        'active_subscription_id' => $sub->id,
        'plan_id' => $this->plan->id,
        'mrr' => 15000,
        'purchased_seats' => 10,
        'used_seats' => 12, // Over seat cap!
        'seat_utilization_pct' => 120,
        'purchased_branches' => 2,
        'active_branches' => 2,
        'branch_utilization_pct' => 100,
        'has_subscription' => true,
        'health_score' => 82,
        'churn_risk' => 'Low',
        'renewal_probability' => 85,
        'stage' => 'live',
        'expansion_opportunity' => true,
        'days_to_expiry' => 25,
        'ends_at' => now()->addDays(25),
    ]);

    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Max Dental Hospital')
            ->where('rows.0.purchased_seats', 10)
            ->where('rows.0.used_seats', 12)
            ->where('rows.0.seat_utilization_pct', 120)
            ->where('rows.0.over_seat_limit', true)
            ->where('rows.0.over_capacity', true)
            ->where('rows.0.purchased_branches', 2)
            ->where('rows.0.active_branches', 2)
            ->where('rows.0.branch_utilization_pct', 100)
            ->where('rows.0.expansion_opportunity', true)
            ->where('rows.0.potential_arr_upsell', 240000)
            ->where('rows.0.health_score', 82)
            ->where('rows.0.days_to_expiry', 25)
            ->where('rows.0.purchased_doctors', 4)
            ->where('summary.over_capacity', 1)
            ->where('summary.expansion_opportunities', 1)
        );
});

test('pagination is bounded and preserves counts', function () {
    for ($i = 1; $i <= 30; $i++) {
        $org = Organization::factory()->create([
            'name' => sprintf('Account %02d', $i),
            'role' => 'customer',
            'assigned_user_id' => $this->csUser->id,
        ]);
        projectSnapshot($org->id, [
            'purchased_seats' => 10,
            'used_seats' => 5,
            'seat_utilization_pct' => 50,
            'has_subscription' => true,
        ]);
    }

    // Default per_page = 25
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 25)
            ->where('paginator.total', 30)
            ->where('paginator.per_page', 25)
        );

    // Clamping min per_page: 3 -> clamped to 10
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['per_page' => 3]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 10)
            ->where('paginator.per_page', 10)
        );

    // Clamping max per_page: 500 -> clamped to 100
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['per_page' => 500]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 30)
            ->where('paginator.per_page', 100)
        );
});

test('search matches account name, contact, and clinic', function () {
    $orgA = Organization::factory()->create([
        'name' => 'Apollo Dental Smile',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($orgA->id, ['purchased_seats' => 10]);

    $orgB = Organization::factory()->create([
        'name' => 'City Care Centre',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($orgB->id, ['purchased_seats' => 10]);

    $clinicB = Clinic::factory()->create([
        'organization_id' => $orgB->id,
        'name' => 'Metro Express Clinic',
    ]);

    // Search account name
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['search' => 'Apollo']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Apollo Dental Smile')
        );

    // Search clinic name
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['search' => 'Metro Express']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'City Care Centre')
        );
});

test('quick view horizon tabs and threshold filters in SQL', function () {
    // 1. Normal account (50% util)
    $orgNormal = Organization::factory()->create([
        'name' => 'Normal Clinic',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($orgNormal->id, [
        'purchased_seats' => 10,
        'used_seats' => 5,
        'seat_utilization_pct' => 50,
        'expansion_opportunity' => false,
    ]);

    // 2. Near capacity (85% util)
    $orgNear = Organization::factory()->create([
        'name' => 'Near Capacity Clinic',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($orgNear->id, [
        'purchased_seats' => 10,
        'used_seats' => 8,
        'seat_utilization_pct' => 85,
        'expansion_opportunity' => true,
    ]);

    // 3. Over capacity (120% util)
    $orgOver = Organization::factory()->create([
        'name' => 'Over Capacity Clinic',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($orgOver->id, [
        'purchased_seats' => 10,
        'used_seats' => 12,
        'seat_utilization_pct' => 120,
        'expansion_opportunity' => true,
    ]);

    // Tab: over_capacity
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['type' => 'over_capacity']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Over Capacity Clinic')
        );

    // Tab: near_capacity
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['type' => 'near_capacity']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Near Capacity Clinic')
        );

    // Tab: expansion
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['type' => 'expansion']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
        );

    // Threshold filter: 80%
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['threshold' => '80']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
        );

    // Threshold filter: 100%
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['threshold' => '100']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Over Capacity Clinic')
        );
});

test('churn risk, stage, and owner filters', function () {
    $repA = User::factory()->create(['organization_id' => $this->providerOrg->id, 'user_type' => UserType::CompanyUser]);
    $repB = User::factory()->create(['organization_id' => $this->providerOrg->id, 'user_type' => UserType::CompanyUser]);

    $org1 = Organization::factory()->create([
        'name' => 'High Risk Clinic',
        'role' => 'customer',
        'assigned_user_id' => $repA->id,
        'churn_risk' => CustomerChurnRisk::High,
    ]);
    projectSnapshot($org1->id, [
        'churn_risk' => 'High',
        'stage' => 'live',
        'has_subscription' => true,
    ]);

    $org2 = Organization::factory()->create([
        'name' => 'Onboarding Clinic',
        'role' => 'customer',
        'assigned_user_id' => $repB->id,
        'churn_risk' => CustomerChurnRisk::Low,
    ]);
    projectSnapshot($org2->id, [
        'churn_risk' => 'Low',
        'stage' => 'onboarding',
        'has_subscription' => false,
    ]);

    // Filter churn_risk=high
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['churn_risk' => 'high']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'High Risk Clinic')
        );

    // Filter stage=onboarding
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['stage' => 'onboarding']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'Onboarding Clinic')
        );

    // Filter owner_id=repA
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['owner_id' => $repA->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.account', 'High Risk Clinic')
        );
});

test('server-side sorting orders records correctly', function () {
    $orgLow = Organization::factory()->create(['name' => 'Low Util Org', 'role' => 'customer', 'assigned_user_id' => $this->csUser->id]);
    projectSnapshot($orgLow->id, ['seat_utilization_pct' => 20]);

    $orgHigh = Organization::factory()->create(['name' => 'High Util Org', 'role' => 'customer', 'assigned_user_id' => $this->csUser->id]);
    projectSnapshot($orgHigh->id, ['seat_utilization_pct' => 95]);

    // Sort: seat_utilization desc -> High Util Org first
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['sort_by' => 'seat_utilization', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.account', 'High Util Org')
            ->where('rows.1.account', 'Low Util Org')
        );

    // Sort: seat_utilization asc -> Low Util Org first
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['sort_by' => 'seat_utilization', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows.0.account', 'Low Util Org')
            ->where('rows.1.account', 'High Util Org')
        );
});

test('query count remains flat with zero N+1 across row counts', function () {
    for ($i = 1; $i <= 30; $i++) {
        $org = Organization::factory()->create([
            'name' => "Batch Clinic {$i}",
            'role' => 'customer',
            'assigned_user_id' => $this->csUser->id,
        ]);
        projectSnapshot($org->id, [
            'purchased_seats' => 10,
            'used_seats' => $i % 12,
            'seat_utilization_pct' => ($i % 12) * 10,
            'purchased_branches' => 2,
            'active_branches' => 1,
            'branch_utilization_pct' => 50,
            'has_subscription' => true,
        ]);
    }

    // Warm-up request to prime auth and permission caches
    $this->actingAs($this->csUser)->get(route('company.customer-success.usage.index', ['per_page' => 5]))->assertOk();

    // Measure queries for 10 rows
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->csUser)->get(route('company.customer-success.usage.index', ['per_page' => 10]))->assertOk();
    $queriesFor10 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Measure queries for 25 rows
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->csUser)->get(route('company.customer-success.usage.index', ['per_page' => 25]))->assertOk();
    $queriesFor25 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Queries must be identical (no per-row N+1)
    expect($queriesFor25)->toBe($queriesFor10);
});

test('scale benchmark with 1000 accounts is informational and bounded', function () {
    $records = [];
    $now = now()->toDateTimeString();

    for ($i = 1; $i <= 1000; $i++) {
        $records[] = [
            'name' => "Scale Clinic {$i}",
            'role' => 'customer',
            'assigned_user_id' => $this->csUser->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    Organization::insert($records);

    $allCustomerIds = Organization::where('role', 'customer')
        ->where('name', 'like', 'Scale Clinic%')
        ->pluck('id');

    $snapshotRecords = [];
    foreach ($allCustomerIds as $idx => $id) {
        $snapshotRecords[] = [
            'organization_id' => $id,
            'purchased_seats' => 10,
            'used_seats' => ($idx % 15),
            'seat_utilization_pct' => min(100, (int) round((($idx % 15) / 10) * 100)),
            'purchased_branches' => 2,
            'active_branches' => 1,
            'branch_utilization_pct' => 50,
            'has_subscription' => 1,
            'expansion_opportunity' => ($idx % 5 === 0) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($snapshotRecords, 250) as $chunk) {
        CustomerSuccessSnapshot::insert($chunk);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($this->csUser)->get(route('company.customer-success.usage.index', ['per_page' => 25]));

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('rows', 25)
        ->where('paginator.total', fn ($total) => $total >= 1000)
    );

    // Query count remains bounded and low (e.g. <= 12 queries total)
    expect($queries)->toBeLessThanOrEqual(12);
});

test('empty state when no accounts match and account links structure', function () {
    $org = Organization::factory()->create([
        'name' => 'Target Account Clinic',
        'role' => 'customer',
        'assigned_user_id' => $this->csUser->id,
    ]);
    projectSnapshot($org->id, [
        'purchased_seats' => 10,
        'used_seats' => 5,
        'seat_utilization_pct' => 50,
        'has_subscription' => true,
    ]);

    // 1. Non-matching search returns empty state
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['search' => 'NonExistentClinicXYZ']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 0)
            ->where('summary.total_accounts', 1)
        );

    // 2. Normal row provides correct organization_id for Account 360 link (/company/customer-success/accounts/{id}?tab=usage)
    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.usage.index', ['search' => 'Target Account']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.organization_id', $org->id)
            ->where('rows.0.account', 'Target Account Clinic')
        );
});

it('provides backward-compatible 301 redirects from legacy usage path', function () {
    $this->actingAs($this->csUser)
        ->get(route('company.usage.index'))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/usage');
});
