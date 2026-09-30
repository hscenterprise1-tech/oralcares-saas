<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    // Vendor tenant — stated as the provider, since role is recorded now
    // rather than inferred from whether it happens to have clinics.
    $this->vendorOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares HQ',
    ]);

    $this->masterAdmin = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::MasterAdmin,
    ]);

    $this->csManager = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->csManager->assignRole(Role::findByName('CRM Manager', 'web'));

    $this->csRep = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->csRep->assignRole(Role::findByName('Sales Representative', 'web'));
});

function createTestCustomer(Organization $vendorOrg, ?User $assignedUser = null): array
{
    $customerOrg = Organization::factory()->create([
        'name' => 'Apex Dental Care',
        'assigned_user_id' => $assignedUser?->id,
        'health_score' => 88,
        'churn_risk' => CustomerChurnRisk::Low,
        'renewal_probability' => 90,
        'expansion_opportunity' => true,
    ]);

    $adminUser = User::factory()->create([
        'organization_id' => $customerOrg->id,
        'user_type' => UserType::ClinicUser,
        'email' => 'admin-'.uniqid().'@apexdental.com',
    ]);

    $lead = Lead::factory()->create([
        'organization_id' => $vendorOrg->id,
        'customer_organization_id' => $customerOrg->id,
        'status' => LeadStatus::Won,
        'company_name' => 'Apex Dental Care',
        'assigned_user_id' => $assignedUser?->id,
    ]);

    $clinic = Clinic::factory()->create([
        'organization_id' => $customerOrg->id,
        'name' => 'Apex Dental Main Clinic',
        'slug' => 'apex-dental-main-'.uniqid(),
    ]);

    $onboarding = OnboardingProgress::create([
        'organization_id' => $customerOrg->id,
        'current_step' => 'organization_profile',
        'status' => 'in_progress',
        'completed_steps' => ['password_setup'],
    ]);

    $subscription = CompanySubscription::create([
        'organization_id' => $customerOrg->id,
        'clinic_id' => $clinic->id,
        'plan_name' => 'Enterprise Cloud',
        'status' => 'active',
        'mrr' => 12500,
        'starts_at' => now()->subMonths(6),
        'ends_at' => now()->addMonths(6),
    ]);

    $activity = $customerOrg->activities()->create([
        'user_id' => $assignedUser?->id,
        'type' => 'note',
        'description' => 'Customer onboarded successfully.',
    ]);

    return compact('customerOrg', 'adminUser', 'lead', 'clinic', 'onboarding', 'subscription', 'activity');
}

// ─────────────────────────────────────────────────────────────────────────────
// Target IA & Workspaces
// ─────────────────────────────────────────────────────────────────────────────

it('renders the customer success accounts directory with 5D health metrics', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Apex Dental Care')
            ->where('accounts.0.health_score', 88)
            ->loadDeferredProps(['metrics'], fn ($page) => $page
                ->where('metrics.total_accounts', 1)
                ->where('metrics.healthy_count', 1)
                ->where('metrics.expansion_count', 1)
            )
        );
});

it('renders the complete Account 360 surface with all 7 core domains', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $org));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Show')
            ->has('account')
            ->where('account.id', $org->id)
            ->loadDeferredProps(fn ($page) => $page
                ->has('healthData')
                ->where('healthData.dimensions.relationship_activity.max', 20)
                ->where('healthData.dimensions.entitlement_usage.max', 20)
                ->where('healthData.dimensions.onboarding_status.max', 20)
                ->where('healthData.dimensions.commercial_engagement.max', 20)
                ->where('healthData.dimensions.churn_risk.max', 20)
                ->has('expansionData')
                ->has('entitlements')
                ->has('onboarding')
                ->where('onboarding.current_step', 'organization_profile')
                ->has('subscriptions', 1)
                ->has('clinics', 1)
                ->has('branches')
                ->has('contacts')
                ->has('commercialDocuments')
                ->has('activities')
                ->has('tasks')
            )
        );
});

it('renders the cross-account renewals queue with retention telemetry', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.renewals.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Renewals/Index')
            ->has('renewals', 1)
            ->where('renewals.0.companyName', 'Apex Dental Care')
            ->has('metrics')
            ->has('filters')
        );
});

// ─────────────────────────────────────────────────────────────────────────────
// Customer Filtering
// ─────────────────────────────────────────────────────────────────────────────

it('strictly excludes the vendor company from accounts directory', function () {
    createTestCustomer($this->vendorOrg, $this->csRep);

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.index'));

    $response->assertOk();
    $accounts = $response->viewData('page')['props']['accounts'];
    $names = array_column($accounts, 'name');

    expect($names)->toContain('Apex Dental Care')
        ->not->toContain('OralCares HQ');
});

// ─────────────────────────────────────────────────────────────────────────────
// Account 360 Actions: Health, Onboarding, Go-Live
// ─────────────────────────────────────────────────────────────────────────────

it('updates account health metrics and records audit activity', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $response = $this->actingAs($this->csManager)
        ->put(route('company.customer-success.accounts.update', $org), [
            'health_score' => 62,
            'churn_risk' => 'medium',
            'renewal_probability' => 70,
            'expansion_opportunity' => false,
            'notes' => 'Client requested reduced doctor seats during quarterly review.',
        ]);

    $response->assertRedirect();

    $org->refresh();
    expect($org->health_score)->toBe(62)
        ->and($org->churn_risk->value)->toBe('Medium')
        ->and($org->renewal_probability)->toBe(70)
        ->and($org->expansion_opportunity)->toBeFalse();

    $lastActivity = $org->activities()->latest('id')->first();
    expect($lastActivity)->not->toBeNull()
        ->and($lastActivity->description)->toContain('Account health metrics updated');
});

it('advances onboarding steps in Account 360', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];
    $onboarding = $data['onboarding'];

    $response = $this->actingAs($this->csManager)
        ->post(route('company.customer-success.accounts.onboarding.complete-step', $org), [
            'step' => 'organization_profile',
            'next_step' => 'branch_setup',
        ]);

    $response->assertRedirect();

    $onboarding->refresh();
    expect($onboarding->completed_steps)->toContain('organization_profile')
        ->and($onboarding->current_step)->toBe('branch_setup');
});

it('marks customer officially go-live in Account 360', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];
    $onboarding = $data['onboarding'];

    $response = $this->actingAs($this->csManager)
        ->post(route('company.customer-success.accounts.onboarding.mark-go-live', $org));

    $response->assertRedirect();

    $onboarding->refresh();
    expect($onboarding->status)->toBe('completed')
        ->and($onboarding->completed_steps)->toContain('go_live');
});

it('resends primary admin welcome password invite from Account 360', function () {
    Mail::fake();
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $response = $this->actingAs($this->csManager)
        ->post(route('company.customer-success.accounts.onboarding.resend-invite', $org));

    $response->assertRedirect();

    $lastActivity = $org->activities()->latest('id')->first();
    expect($lastActivity)->not->toBeNull()
        ->and($lastActivity->description)->toContain('Welcome Invite resent');
});

// ─────────────────────────────────────────────────────────────────────────────
// Bidirectional Deal ↔ Account Bridges
// ─────────────────────────────────────────────────────────────────────────────

/*
 * These three used to assert that renewing and expanding a customer opened a
 * CRM *deal*. That was the behaviour, and it was wrong: the pipeline exists to
 * win a stranger, and this customer has already bought. Worse, the action
 * behind it invented the figure it recorded — a flat 240,000 for an expansion,
 * 50,000 for a renewal — and wrote that into the deal's expected ARR.
 *
 * What replaces them asserts the corrected route: the contract itself changes,
 * in Customer Success, against the money actually agreed, and no deal is
 * raised at all.
 */
it('renews a customer against its own contract and raises no CRM deal', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $subscription = $data['subscription'];
    $endsAt = $subscription->ends_at;

    $this->actingAs($this->csManager)
        ->post(route('company.customer-success.subscriptions.renew', $subscription), ['months' => 12])
        ->assertSessionHasNoErrors();

    expect($subscription->fresh()->ends_at->toDateString())
        ->toBe($endsAt->copy()->addMonths(12)->toDateString())
        ->and(Lead::where('customer_organization_id', $subscription->organization_id)
            ->where('status', '!=', LeadStatus::Won)
            ->exists())
        ->toBeFalse();
});

it('grows a customer by changing its plan rather than by opening a deal', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $subscription = $data['subscription'];

    $bigger = CompanyPlan::factory()->create(['name' => 'Enterprise']);

    $this->actingAs($this->csManager)
        ->post(route('company.customer-success.subscriptions.change-plan', $subscription), [
            'company_plan_id' => $bigger->id,
            'mrr' => 41000,
        ])
        ->assertSessionHasNoErrors();

    $fresh = $subscription->fresh();

    expect($fresh->company_plan_id)->toBe($bigger->id)
        /* The agreed figure, never a plan's list price and never a constant. */
        ->and((float) $fresh->mrr)->toBe(41000.0)
        ->and(Lead::where('customer_organization_id', $subscription->organization_id)
            ->where('company_name', 'like', 'Expansion:%')
            ->exists())
        ->toBeFalse();
});

it('links from CRM won deal to customer success account 360', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $lead = $data['lead'];
    $org = $data['customerOrg'];

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.crm.leads.show', $lead));

    $response->assertOk();

    /*
     * The account link is deferred with the Now zone rather than sent with the
     * document — it is a link a rep follows occasionally, not something the
     * command bar needs before the page paints.
     */
    $props = $response->viewData('page')['props'];

    expect($props['deal']['customer_organization_id'])->toBe($org->id);

    $deferred = $response->viewData('page')['deferredProps'] ?? [];
    expect($deferred['now'] ?? [])->toContain('customer_account');
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenant Isolation & Security
// ─────────────────────────────────────────────────────────────────────────────

it('enforces tenant isolation across separate vendor organizations', function () {
    /*
     * A second *vendor*, which is what the name says and what the test means:
     * it owns leads and works its own accounts. The fixture predates the `role`
     * column, so it was inheriting the `customer` default and describing a
     * seller as one of the things it sells to — the same conflation that
     * produced finding T-2.
     */
    $otherVendorOrg = Organization::factory()->provider()->create(['name' => 'Other Health Tech Vendor']);
    $otherRep = User::factory()->create([
        'organization_id' => $otherVendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $otherRep->assignRole(Role::findByName('Sales Representative', 'web'));

    $dataOrgA = createTestCustomer($this->vendorOrg, $this->csRep);
    $dataOrgB = createTestCustomer($otherVendorOrg, $otherRep);

    // CS Rep A should only see Org A
    $responseA = $this->actingAs($this->csRep)
        ->get(route('company.customer-success.accounts.index'));
    $responseA->assertOk();
    $namesA = array_column($responseA->viewData('page')['props']['accounts'], 'name');
    expect($namesA)->toContain($dataOrgA['customerOrg']->name);

    // CS Rep B querying Org A directly should be denied
    $this->actingAs($otherRep)
        ->get(route('company.customer-success.accounts.show', $dataOrgA['customerOrg']))
        ->assertForbidden();
});

it('denies clinic tenant users access to Customer Success', function () {
    $clinicUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => null,
    ]);

    $this->actingAs($clinicUser)
        ->get(route('company.customer-success.accounts.index'))
        ->assertForbidden();

    $this->actingAs($clinicUser)
        ->get(route('company.customer-success.renewals.index'))
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Permission Separation: manage-organizations vs view-crm
// ─────────────────────────────────────────────────────────────────────────────

it('denies a sales rep holding only view permissions from updating account health metrics', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $this->actingAs($this->csRep)
        ->put(route('company.customer-success.accounts.update', $org), [
            'health_score' => 45,
            'churn_risk' => 'High',
        ])
        ->assertForbidden();

    expect($org->fresh()->health_score)->toBe(88);
});

it('denies a sales rep holding only view permissions from mutating onboarding progress', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.onboarding.complete-step', $org), [
            'step' => 'organization_profile',
            'next_step' => 'branch_setup',
        ])
        ->assertForbidden();

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.onboarding.resend-invite', $org))
        ->assertForbidden();

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.onboarding.mark-go-live', $org))
        ->assertForbidden();
});

it('denies a sales rep holding only view permissions from creating or modifying clinics', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.clinics.store', $org), [
            'name' => 'Unauthorized Clinic',
        ])
        ->assertForbidden();

    expect(Clinic::where('name', 'Unauthorized Clinic')->exists())->toBeFalse();
});

it('exposes can.update accurately on Account 360 based on manage-organizations', function () {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    // Sales Rep has view access, but NOT manage-organizations
    $this->actingAs($this->csRep)
        ->get(route('company.customer-success.accounts.show', $org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', false));

    // CS Manager has manage-organizations
    $this->actingAs($this->csManager)
        ->get(route('company.customer-success.accounts.show', $org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', true));
});

// ─────────────────────────────────────────────────────────────────────────────
// Legacy Redirect Compatibility
// ─────────────────────────────────────────────────────────────────────────────

it('redirects all legacy CRM customer routes to customer success', function (string $legacyRoute, string $targetRoute) {
    $data = createTestCustomer($this->vendorOrg, $this->csRep);
    $org = $data['customerOrg'];

    $url = str_contains($legacyRoute, '{id}') || str_contains($legacyRoute, '{organization}')
        ? route(str_replace(['{id}', '{organization}'], (string) $org->id, $legacyRoute), $org)
        : route($legacyRoute);

    $this->actingAs($this->masterAdmin)
        ->get($url)
        ->assertRedirect();
})->with([
    ['company.crm.organizations.index', 'company.customer-success.accounts.index'],
    ['company.crm.onboarding.index', 'company.customer-success.accounts.index'],
    ['company.crm.subscriptions.index', 'company.customer-success.accounts.index'],
    ['company.crm.renewals.index', 'company.customer-success.renewals.index'],
]);
