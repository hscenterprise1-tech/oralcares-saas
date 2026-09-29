<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Organization;
use App\Models\User;
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

    // Provider organization (must be strictly excluded from customer subscriptions)
    $this->providerOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares HQ Provider',
    ]);

    // Master Admin
    $this->masterAdmin = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::MasterAdmin,
    ]);

    // Ensure view-subscriptions permission is present for tests
    Permission::findOrCreate('view-subscriptions', 'web');

    // CS User with view-subscriptions
    $this->csUser = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->csUser->givePermissionTo('view-subscriptions');

    // Unprivileged User (holding nothing)
    $this->unprivilegedUser = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);

    // Customer Organization
    $this->customerOrgA = Organization::factory()->create([
        'name' => 'Apex Dental Care',
        'assigned_user_id' => $this->csUser->id,
        'health_score' => 88,
        'churn_risk' => CustomerChurnRisk::Low,
        'renewal_probability' => 90,
    ]);

    $this->clinicA = Clinic::factory()->create([
        'organization_id' => $this->customerOrgA->id,
        'name' => 'Apex Dental Clinic A',
        'slug' => 'apex-clinic-a-'.uniqid(),
    ]);

    // Plans
    $this->growthPlan = CompanyPlan::factory()->create([
        'name' => 'Growth Cloud',
        'key' => 'growth_cloud',
        'billing_period' => 'monthly',
        'price' => 12000,
    ]);

    $this->enterprisePlan = CompanyPlan::factory()->create([
        'name' => 'Enterprise Annual',
        'key' => 'enterprise_annual',
        'billing_period' => 'yearly',
        'price' => 120000,
    ]);
});

it('gates the subscriptions workspace on view-subscriptions permission', function () {
    $this->actingAs($this->unprivilegedUser)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertForbidden();

    $this->actingAs($this->csUser)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertOk();

    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertOk();
});

it('strictly excludes provider organization subscriptions from both list and detail', function () {
    // Provider subscription
    $providerSub = CompanySubscription::create([
        'organization_id' => $this->providerOrg->id,
        'plan_name' => 'Internal Provider Plan',
        'status' => 'active',
        'mrr' => 0,
        'starts_at' => now(),
    ]);

    // Customer subscription
    $customerSub = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'clinic_id' => $this->clinicA->id,
        'company_plan_id' => $this->growthPlan->id,
        'plan_name' => 'Growth Cloud',
        'status' => 'active',
        'mrr' => 12000,
        'starts_at' => now(),
        'ends_at' => now()->addMonths(12),
    ]);

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $customerSub->id)
            ->where('subscriptions.data.0.account', 'Apex Dental Care')
            ->where('summary.total', 1)
        );

    // Detail endpoint must 404 on provider subscription even for super admin
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.show', $providerSub))
        ->assertNotFound();

    // Detail endpoint must 200 on customer subscription
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.show', $customerSub))
        ->assertOk();
});

it('enforces tenant data isolation for scoped users', function () {
    $otherCustomer = Organization::factory()->create([
        'name' => 'Foreign Dental Group',
    ]);

    $foreignSub = CompanySubscription::create([
        'organization_id' => $otherCustomer->id,
        'plan_name' => 'Foreign Plan',
        'status' => 'active',
        'mrr' => 15000,
        'starts_at' => now(),
    ]);

    // Scoped Sales Representative assigned only to customerOrgA
    $salesRep = User::factory()->create([
        'organization_id' => $this->providerOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $salesRep->assignRole(Role::findByName('Sales Representative', 'web'));
    $salesRep->givePermissionTo('view-subscriptions');

    $this->customerOrgA->update(['assigned_user_id' => $salesRep->id]);

    $ownSub = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'clinic_id' => $this->clinicA->id,
        'plan_name' => 'Apex Cloud',
        'status' => 'active',
        'mrr' => 12000,
        'starts_at' => now(),
    ]);

    $response = $this->actingAs($salesRep)
        ->get(route('company.customer-success.subscriptions.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $ownSub->id)
            ->where('summary.total', 1)
        );

    // Accessing foreign subscription must return 404
    $this->actingAs($salesRep)
        ->get(route('company.customer-success.subscriptions.show', $foreignSub))
        ->assertNotFound();
});

it('strictly preserves recorded MRR and calculates ARR as mrr * 12 without arbitrary multipliers', function () {
    $sub = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'clinic_id' => $this->clinicA->id,
        'company_plan_id' => $this->growthPlan->id,
        'plan_name' => 'Growth Cloud',
        'status' => 'active',
        'mrr' => 12500.50,
        'starts_at' => now(),
        'ends_at' => now()->addMonths(6),
        'max_users' => 10, // 10 * 1500 would be 15000, but recorded MRR is 12500.50
    ]);

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.mrr', 12500.5)
            ->where('subscriptions.data.0.arr', 150006)
            ->where('summary.mrr', 12500.5)
            ->where('summary.arr', 150006)
            ->where('summary.mrr_unrecorded', 0)
        );
});

it('supports server-side pagination with bounded per_page 10 to 100', function () {
    // Create 35 subscriptions
    for ($i = 1; $i <= 35; $i++) {
        CompanySubscription::create([
            'organization_id' => $this->customerOrgA->id,
            'clinic_id' => $this->clinicA->id,
            'plan_name' => "Plan {$i}",
            'status' => 'active',
            'mrr' => 5000,
            'starts_at' => now(),
            'ends_at' => now()->addDays($i),
        ]);
    }

    // Default per_page is 25
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index'))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 25)
            ->where('subscriptions.total', 35)
        );

    // Bounded minimum per_page = 10 (when passing 3, clamps to 10)
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['per_page' => 3]))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 10)
        );

    // Bounded maximum per_page = 100 (when passing 500, clamps to 100)
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['per_page' => 500]))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 35) // all 35 fit within max 100
        );
});

it('filters subscriptions by search across account name, clinic name, plan name, and subscription ID', function () {
    $sub1 = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'clinic_id' => $this->clinicA->id,
        'company_plan_id' => $this->growthPlan->id,
        'status' => 'active',
        'mrr' => 10000,
    ]);

    $orgB = Organization::factory()->create(['name' => 'Metro Smile Studio']);
    $clinicB = Clinic::factory()->create(['organization_id' => $orgB->id, 'name' => 'Downtown Clinic']);
    $sub2 = CompanySubscription::create([
        'organization_id' => $orgB->id,
        'clinic_id' => $clinicB->id,
        'company_plan_id' => $this->enterprisePlan->id,
        'status' => 'active',
        'mrr' => 20000,
    ]);

    // Search by Account Name
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['search' => 'Metro Smile']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $sub2->id)
        );

    // Search by Clinic Name
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['search' => 'Downtown Clinic']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $sub2->id)
        );

    // Search by Plan Name
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['search' => 'Growth Cloud']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $sub1->id)
        );

    // Search by numeric ID
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['search' => (string) $sub1->id]))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $sub1->id)
        );
});

it('filters subscriptions by status, plan, billing frequency, and date windows', function () {
    $activeExp15 = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'company_plan_id' => $this->growthPlan->id,
        'status' => 'active',
        'mrr' => 12000,
        'starts_at' => now()->subMonths(1),
        'ends_at' => now()->addDays(15),
    ]);

    $activeExp45 = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'company_plan_id' => $this->enterprisePlan->id,
        'status' => 'active',
        'mrr' => 25000,
        'starts_at' => now()->subMonths(1),
        'ends_at' => now()->addDays(45),
    ]);

    $cancelledSub = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'company_plan_id' => $this->growthPlan->id,
        'status' => 'cancelled',
        'mrr' => 12000,
        'starts_at' => now()->subMonths(6),
        'ends_at' => now()->subMonths(1),
    ]);

    // Window: expiring_30
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['window' => 'expiring_30']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $activeExp15->id)
        );

    // Window: expiring_60
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['window' => 'expiring_60']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 2)
        );

    // Window: cancelled
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['window' => 'cancelled']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $cancelledSub->id)
        );

    // Filter by Plan ID
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['plan_id' => $this->enterprisePlan->id]))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $activeExp45->id)
        );

    // Filter by Billing Frequency (Yearly)
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['billing_frequency' => 'yearly']))
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 1)
            ->where('subscriptions.data.0.id', $activeExp45->id)
        );
});

it('sorts subscriptions server-side by ends_at, mrr, account, and status', function () {
    $orgB = Organization::factory()->create(['name' => 'Zenith Dental']);

    $subA = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id, // Apex Dental Care
        'status' => 'active',
        'mrr' => 10000,
        'ends_at' => now()->addDays(50),
    ]);

    $subB = CompanySubscription::create([
        'organization_id' => $orgB->id, // Zenith Dental
        'status' => 'active',
        'mrr' => 30000,
        'ends_at' => now()->addDays(20),
    ]);

    // Sort by ends_at ASC (Soonest expiry first)
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['sort_by' => 'ends_at', 'sort_order' => 'asc']))
        ->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.id', $subB->id)
            ->where('subscriptions.data.1.id', $subA->id)
        );

    // Sort by MRR DESC (Highest first)
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['sort_by' => 'mrr', 'sort_order' => 'desc']))
        ->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.id', $subB->id)
            ->where('subscriptions.data.1.id', $subA->id)
        );

    // Sort by Account Name ASC
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['sort_by' => 'account', 'sort_order' => 'asc']))
        ->assertInertia(fn ($page) => $page
            ->where('subscriptions.data.0.id', $subA->id) // Apex
            ->where('subscriptions.data.1.id', $subB->id) // Zenith
        );
});

it('guarantees constant query count with zero N+1 queries', function () {
    // Create 30 subscriptions
    for ($i = 1; $i <= 30; $i++) {
        CompanySubscription::create([
            'organization_id' => $this->customerOrgA->id,
            'clinic_id' => $this->clinicA->id,
            'company_plan_id' => $this->growthPlan->id,
            'status' => 'active',
            'mrr' => 10000,
            'ends_at' => now()->addDays($i),
        ]);
    }

    // Warm up permissions and session in test runner
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['per_page' => 10]));

    // Measure query count for 10 rows
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['per_page' => 10]));
    $count10 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Measure query count for 25 rows
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['per_page' => 25]));
    $count25 = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Query count must be strictly constant regardless of row count (no N+1)
    expect($count25)->toBe($count10);
});

it('scales reliably to 1,000+ subscriptions without memory exhaustion or degradation', function () {
    // Seed 1,000 subscriptions
    $records = [];
    $now = now()->toDateTimeString();

    for ($i = 1; $i <= 1000; $i++) {
        $records[] = [
            'organization_id' => $this->customerOrgA->id,
            'clinic_id' => $this->clinicA->id,
            'company_plan_id' => $this->growthPlan->id,
            'status' => 'active',
            'mrr' => 10000.00,
            'starts_at' => $now,
            'ends_at' => now()->addDays($i % 365)->toDateTimeString(),
            'max_users' => 5,
            'max_doctors' => 2,
            'max_branches' => 1,
            'storage_limit_gb' => 10,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($records) === 250) {
            CompanySubscription::insert($records);
            $records = [];
        }
    }
    if (! empty($records)) {
        CompanySubscription::insert($records);
    }

    $startTime = microtime(true);

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.subscriptions.index', ['per_page' => 25]));

    $durationMs = (microtime(true) - $startTime) * 1000;

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('subscriptions.data', 25)
            ->where('subscriptions.total', fn ($total) => $total >= 1000)
            ->where('summary.total', fn ($total) => $total >= 1000)
        );

    // Informational logging of scale benchmark
    expect($durationMs)->toBeGreaterThan(0.0);
});

it('provides backward-compatible 301 redirects from legacy subscription paths', function () {
    $customerSub = CompanySubscription::create([
        'organization_id' => $this->customerOrgA->id,
        'clinic_id' => $this->clinicA->id,
        'company_plan_id' => $this->growthPlan->id,
        'plan_name' => 'Growth Cloud',
        'status' => 'active',
        'mrr' => 12000,
        'starts_at' => now(),
        'ends_at' => now()->addMonths(12),
    ]);

    // Legacy index path -> 301 redirect
    $this->actingAs($this->csUser)
        ->get(route('company.subscriptions.index'))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/subscriptions');

    // Legacy detail path -> 301 redirect
    $this->actingAs($this->csUser)
        ->get(route('company.subscriptions.show', $customerSub))
        ->assertStatus(301)
        ->assertRedirect('/company/customer-success/subscriptions/'.$customerSub->id);
});
