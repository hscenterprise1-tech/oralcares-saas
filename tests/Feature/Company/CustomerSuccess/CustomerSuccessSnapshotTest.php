<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\CustomerSuccessSnapshot;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommercialAccountHealthService;
use App\Services\CommercialEntitlementService;
use App\Services\CommercialExpansionService;
use App\Services\CustomerSuccess\AccountSnapshotService;
use App\Services\Lifecycle\AccountLifecycleService;
use App\SubscriptionStatus;
use App\Support\CrmScope;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    $this->vendorOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares Provider HQ',
    ]);

    $this->superAdmin = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->superAdmin->assignRole(Role::findByName('Super Admin', 'web'));

    $this->rep = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $this->rep->assignRole(Role::findByName('Sales Representative', 'web'));

    $this->service = app(AccountSnapshotService::class);
});

it('creates one snapshot per customer organization via the snapshot service and relationship', function () {
    $org = Organization::factory()->customer()->create([
        'name' => 'Apex Dental Care',
        'assigned_user_id' => $this->rep->id,
    ]);

    $snapshot = $this->service->refresh($org);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->organization_id)->toBe($org->id)
        ->and(CustomerSuccessSnapshot::where('organization_id', $org->id)->count())->toBe(1);

    // Organization hasOne relationship
    expect($org->snapshot)->not->toBeNull()
        ->and($org->snapshot->id)->toBe($snapshot->id);
});

it('strictly excludes provider/vendor organizations from snapshots', function () {
    $snapshot = $this->service->refresh($this->vendorOrg);

    expect($snapshot)->toBeNull()
        ->and(CustomerSuccessSnapshot::where('organization_id', $this->vendorOrg->id)->count())->toBe(0);

    $safeCall = $this->service->refreshIfCustomer($this->vendorOrg);
    expect($safeCall)->toBeNull();
});

it('matches canonical service output across health, entitlements, expansion, and lifecycle stage', function () {
    $plan = CompanyPlan::factory()->create([
        'name' => 'Growth Enterprise',
        'price' => 36000.00,
        'billing_period' => 'yearly',
    ]);

    $org = Organization::factory()->customer()->create([
        'name' => 'Radiant Smile Hospital',
        'contact_person' => 'Dr. Radiant',
        'churn_risk' => CustomerChurnRisk::High,
        /*
         * Pinned by hand, which is what makes the seeded risk survive the
         * refresh. Churn is derived now; an unstamped value is a starting
         * point the nightly pass is free to replace, and this test is about
         * the projection matching the canonical services rather than about
         * the derivation.
         */
        'churn_risk_overridden_at' => now(),
        'expansion_opportunity' => true,
        'renewal_probability' => 88,
        'assigned_user_id' => $this->rep->id,
    ]);

    $clinic = Clinic::factory()->create([
        'organization_id' => $org->id,
        'name' => 'Radiant Main Clinic',
    ]);

    Branch::factory()->count(2)->create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
    ]);

    User::factory()->count(3)->create([
        'organization_id' => $org->id,
        'user_type' => UserType::ClinicUser,
    ]);

    $sub = CompanySubscription::create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'company_plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->addDays(40),
        'mrr' => 3000.00,
        'max_users' => 10,
        'max_branches' => 4,
    ]);

    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'go_live',
        'status' => 'completed',
        'completed_steps' => ['password_setup', 'organization_profile', 'branch_setup', 'admin_access', 'go_live'],
    ]);

    $snapshot = $this->service->refresh($org);

    // Compare with canonical services
    $canonicalHealth = app(CommercialAccountHealthService::class)->calculateHealthScore($org);
    $canonicalEntitlements = app(CommercialEntitlementService::class)->forOrganization($org);
    $canonicalExpansion = app(CommercialExpansionService::class)->calculateExpansionRadar($org);
    $canonicalLifecycle = app(AccountLifecycleService::class)->lifecycleFor($org);

    expect($snapshot->health_score)->toBe($canonicalHealth['total_score'])
        ->and($snapshot->health_dimensions)->toBe($canonicalHealth['dimensions'])
        ->and($snapshot->churn_risk)->toBe(CustomerChurnRisk::High)
        ->and($snapshot->renewal_probability)->toBe(88)
        ->and($snapshot->purchased_seats)->toBe(10)
        ->and($snapshot->used_seats)->toBe(3)
        ->and($snapshot->seat_utilization_pct)->toBe($canonicalEntitlements['seat_utilization_pct'])
        ->and($snapshot->purchased_branches)->toBe(4)
        ->and($snapshot->active_branches)->toBe(2)
        ->and($snapshot->branch_utilization_pct)->toBe($canonicalEntitlements['branch_utilization_pct'])
        ->and($snapshot->has_subscription)->toBeTrue()
        ->and($snapshot->active_subscription_id)->toBe($sub->id)
        ->and($snapshot->plan_id)->toBe($plan->id)
        ->and((float) $snapshot->mrr)->toBe(3000.00)
        ->and($snapshot->stage)->toBe($canonicalLifecycle['stage'])
        ->and($snapshot->expansion_opportunity)->toBeTrue()
        ->and($snapshot->computed_at)->not->toBeNull();
});

it('preserves null MRR when subscription MRR is genuinely unrecorded', function () {
    $org = Organization::factory()->customer()->create(['assigned_user_id' => $this->rep->id]);

    $sub = CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(1),
        'ends_at' => now()->addMonths(11),
        'mrr' => null, // Genuinely unrecorded MRR
        'max_users' => 5,
    ]);

    $snapshot = $this->service->refresh($org);
    expect($snapshot->mrr)->toBeNull();

    // Now record MRR and refresh
    $sub->update(['mrr' => 4500.00]);
    $snapshotUpdated = $this->service->refresh($org);
    expect((float) $snapshotUpdated->mrr)->toBe(4500.00);
});

it('is completely idempotent and retry-safe', function () {
    $org = Organization::factory()->customer()->create(['assigned_user_id' => $this->rep->id]);

    $snap1 = $this->service->refresh($org);
    $snap2 = $this->service->refresh($org);
    $snap3 = $this->service->refresh($org);

    expect(CustomerSuccessSnapshot::where('organization_id', $org->id)->count())->toBe(1)
        ->and($snap1->id)->toBe($snap2->id)
        ->and($snap2->id)->toBe($snap3->id);
});

it('processes accounts in memory-bounded chunks without loading all organizations', function () {
    // Create 25 customer organizations
    $orgs = Organization::factory()->customer()->count(25)->create([
        'assigned_user_id' => $this->rep->id,
    ]);

    $progressUpdates = [];
    $processedCount = $this->service->refreshAllChunks(10, function (int $count) use (&$progressUpdates) {
        $progressUpdates[] = $count;
    });

    expect($processedCount)->toBe(25)
        ->and(CustomerSuccessSnapshot::count())->toBe(25)
        ->and($progressUpdates)->toContain(10, 20, 25);
});

it('creates snapshots for failed or stuck provisioning accounts', function () {
    // Organization with stuck/failed provisioning (zero clinics, zero subscriptions)
    $stuckOrg = Organization::factory()->customer()->create([
        'name' => 'Stuck Intake Clinic',
        'assigned_user_id' => $this->rep->id,
    ]);

    OnboardingProgress::create([
        'organization_id' => $stuckOrg->id,
        'status' => 'failed',
        'current_step' => 'provisioning_failed',
    ]);

    $snapshot = $this->service->refresh($stuckOrg);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->has_subscription)->toBeFalse()
        ->and($snapshot->active_subscription_id)->toBeNull()
        ->and($snapshot->plan_id)->toBeNull()
        ->and($snapshot->mrr)->toBeNull()
        ->and($snapshot->stage)->toBe('not_provisioned')
        ->and($snapshot->onboarding_status)->toBe('failed');
});

it('enforces database indexes and unique constraint on organization_id', function () {
    $org = Organization::factory()->customer()->create(['assigned_user_id' => $this->rep->id]);

    projectSnapshot($org->id, [
        'health_score' => 70,
        'computed_at' => now(),
    ]);

    // Inserting a duplicate organization_id directly should throw QueryException
    expect(fn () => DB::table('customer_success_snapshots')->insert([
        'organization_id' => $org->id,
        'health_score' => 80,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('supports tenant isolation and Super Admin visibility on snapshot queries', function () {
    $otherRep = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    $otherRep->assignRole(Role::findByName('Sales Representative', 'web'));

    $myOrg = Organization::factory()->customer()->create([
        'name' => 'My Rep Account',
        'assigned_user_id' => $this->rep->id,
    ]);
    $this->service->refresh($myOrg);

    $otherOrg = Organization::factory()->customer()->create([
        'name' => 'Other Rep Account',
        'assigned_user_id' => $otherRep->id,
    ]);
    $this->service->refresh($otherOrg);

    // Sales Rep boundary: only sees their own account's snapshot
    $repVisibleSnapshots = CustomerSuccessSnapshot::query()
        ->whereIn('organization_id', CrmScope::customerAccounts($this->rep)->select('organizations.id'))
        ->get();

    expect($repVisibleSnapshots)->toHaveCount(1)
        ->and($repVisibleSnapshots->first()->organization_id)->toBe($myOrg->id);

    // Super Admin boundary: sees all customer snapshots
    $superAdminSnapshots = CustomerSuccessSnapshot::query()
        ->whereIn('organization_id', CrmScope::customerAccounts($this->superAdmin)->select('organizations.id'))
        ->get();

    expect($superAdminSnapshots)->toHaveCount(2);
});

it('refreshes snapshots via the artisan command cs:snapshots:refresh', function () {
    $org = Organization::factory()->customer()->create([
        'name' => 'Artisan Test Clinic',
        'assigned_user_id' => $this->rep->id,
    ]);

    $this->artisan('cs:snapshots:refresh', ['--organization' => $org->id])
        ->assertSuccessful();

    expect(CustomerSuccessSnapshot::where('organization_id', $org->id)->count())->toBe(1);

    // Warning when executed on provider org
    $this->artisan('cs:snapshots:refresh', ['--organization' => $this->vendorOrg->id])
        ->assertSuccessful();

    expect(CustomerSuccessSnapshot::where('organization_id', $this->vendorOrg->id)->count())->toBe(0);

    // Full chunked execution
    $this->artisan('cs:snapshots:refresh', ['--chunk' => 5])
        ->assertSuccessful();
});
