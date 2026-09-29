<?php

use App\ClinicStatus;
use App\LeadStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationType;
use App\Services\CommercialRenewalService;
use App\Services\CommercialSubscriptionService;
use App\Services\CRM\NextBestActionService;
use App\Services\CustomerProvisioningEngine;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 0 — Backend foundation and data-flow repair.
 *
 * The forensic audit found the CRM lifecycle intact up to Won and broken
 * immediately after it. Three defects compounded:
 *
 *   1. `leads.organization_id` was written at creation with the *acting
 *      user's* organization, while provisioning treated a non-null value as
 *      "already provisioned" — so winning a deal never provisioned anything.
 *   2. Provisioning never created a Clinic, and `Clinic` could not even be
 *      mass-assigned an `organization_id`, so the tenant hierarchy
 *      Organization → Clinic → Branch had no middle.
 *   3. Onboarding, Subscriptions and Renewals were therefore permanently
 *      starved rather than badly built.
 *
 * These tests pin the repaired chain end to end:
 *
 *   Lead → Won → provisioning → Organization → Clinic → Branch
 *        → tenant access → Onboarding → Subscription → Renewal eligibility
 */

/**
 * A lead with every field the scoring and next-best-action services read, so
 * the fully-qualified code path is genuinely exercised rather than
 * short-circuited by missing data.
 */
function fullyQualifiedLead(array $attributes = []): Lead
{
    return Lead::factory()->create(array_merge([
        'company_name' => 'Northgate Dental Group',
        'contact_name' => 'Asha Menon',
        'email' => 'asha@northgate.test',
        'phone' => '+919876500100',
        'status' => LeadStatus::Negotiation,
        'organization_type' => OrganizationType::DentalClinic,
        'healthcare_specialty' => 'general_dentistry',
        'organization_size' => '11-50',
        'expected_mrr' => 45000,
        'expected_users' => 12,
        'probability_to_close' => 70,
        'expected_go_live_date' => now()->addMonth()->toDateString(),
    ], $attributes));
}

function winLead(Lead $lead, ?User $actor = null): Lead
{
    $actor ??= User::factory()->create(['user_type' => UserType::MasterAdmin]);

    app(LeadWorkspaceService::class)
        ->transitionStage($lead, LeadStatus::Won->value, $actor, 'Phase 0 lifecycle test');

    return $lead->fresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// Blocker — next-best-action referenced enum cases that do not exist
// ─────────────────────────────────────────────────────────────────────────────

it('computes a next best action in every lead status without throwing', function () {
    $lead = fullyQualifiedLead();
    $service = app(NextBestActionService::class);

    foreach (LeadStatus::cases() as $status) {
        $lead->status = $status;

        expect(fn () => $service->determineNextAction($lead))
            ->not->toThrow(Error::class, 'Undefined constant');
    }
});

it('saves a fully qualified lead in every status without throwing', function () {
    $lead = fullyQualifiedLead();

    foreach ([LeadStatus::Assigned, LeadStatus::Contacted, LeadStatus::Negotiation, LeadStatus::QuotationApproved] as $status) {
        $lead->status = $status;
        $lead->save();

        expect($lead->fresh()->status->value)->toBe($status->value);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Fix 2 — organization ownership overload
// ─────────────────────────────────────────────────────────────────────────────

it('provisions a lead that already carries a tenant organization id', function () {
    // This is the exact production shape the audit found: createLead() stamps
    // organization_id with the creating user's org, which used to make
    // provisioning return early and do nothing.
    $tenant = Organization::factory()->create(['name' => 'OralCares HQ']);
    $lead = fullyQualifiedLead(['organization_id' => $tenant->id]);

    $lead = winLead($lead);

    expect($lead->customer_organization_id)->not->toBeNull()
        ->and($lead->customer_organization_id)->not->toBe($tenant->id);

    $customer = Organization::find($lead->customer_organization_id);

    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Northgate Dental Group');
});

it('keeps the tenant organization and the won customer in separate columns', function () {
    $tenant = Organization::factory()->create();
    $lead = fullyQualifiedLead(['organization_id' => $tenant->id]);

    $lead = winLead($lead);

    expect($lead->organization_id)->toBe($tenant->id)
        ->and($lead->customer_organization_id)->not->toBe($tenant->id);
});

it('exposes the won customer through a dedicated relation', function () {
    $lead = winLead(fullyQualifiedLead());

    expect($lead->customerOrganization)->toBeInstanceOf(Organization::class)
        ->and($lead->customerOrganization->name)->toBe('Northgate Dental Group');
});

// ─────────────────────────────────────────────────────────────────────────────
// Fix 1 — Won → Customer provisioning creates the full tenant hierarchy
// ─────────────────────────────────────────────────────────────────────────────

it('creates an organization when a deal is won', function () {
    $lead = winLead(fullyQualifiedLead());

    $this->assertDatabaseHas('organizations', ['name' => 'Northgate Dental Group']);
    expect($lead->customerOrganization)->not->toBeNull();
});

it('creates a clinic tenant beneath the organization', function () {
    $lead = winLead(fullyQualifiedLead());
    $org = $lead->customerOrganization;

    $clinic = Clinic::where('organization_id', $org->id)->first();

    expect($clinic)->not->toBeNull()
        ->and($clinic->organization_id)->toBe($org->id)
        ->and($clinic->slug)->not->toBeNull()
        ->and($clinic->status->value)->toBe('active');
});

it('attaches the primary branch to the clinic, not just the organization', function () {
    $lead = winLead(fullyQualifiedLead());
    $org = $lead->customerOrganization;

    $branch = Branch::withoutOrganizationScope()
        ->where('organization_id', $org->id)
        ->where('is_main', true)
        ->first();

    expect($branch)->not->toBeNull()
        ->and($branch->clinic_id)->not->toBeNull()
        ->and($branch->clinic->organization_id)->toBe($org->id);
});

it('leaves no provisioned branch with a null clinic id', function () {
    winLead(fullyQualifiedLead());

    $orphans = Branch::withoutOrganizationScope()
        ->whereNull('clinic_id')
        ->count();

    expect($orphans)->toBe(0);
});

it('grants the provisioned user access to the clinic tenant', function () {
    $lead = winLead(fullyQualifiedLead());
    $org = $lead->customerOrganization;
    $clinic = Clinic::where('organization_id', $org->id)->firstOrFail();

    $user = User::where('email', 'asha@northgate.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->user_type)->toBe(UserType::ClinicUser);

    $membership = ClinicMembership::where('clinic_id', $clinic->id)
        ->where('user_id', $user->id)
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership->is_primary_owner)->toBeTrue()
        ->and($membership->status->value)->toBe('active');
});

it('links the organization to its clinics through a relation', function () {
    $lead = winLead(fullyQualifiedLead());
    $org = $lead->customerOrganization;

    expect($org->clinics)->toHaveCount(1)
        ->and($org->clinics->first()->name)->toContain('Northgate');
});

// ─────────────────────────────────────────────────────────────────────────────
// Fix 1 — idempotency
// ─────────────────────────────────────────────────────────────────────────────

it('does not duplicate tenants when provisioning runs twice', function () {
    $lead = winLead(fullyQualifiedLead());

    app(CustomerProvisioningEngine::class)->provision($lead->fresh());
    app(CustomerProvisioningEngine::class)->provision($lead->fresh());

    $orgId = $lead->fresh()->customer_organization_id;

    expect(Organization::where('name', 'Northgate Dental Group')->count())->toBe(1)
        ->and(Clinic::where('organization_id', $orgId)->count())->toBe(1)
        ->and(Branch::withoutOrganizationScope()->where('organization_id', $orgId)->count())->toBe(1)
        ->and(CompanySubscription::where('organization_id', $orgId)->count())->toBe(1)
        ->and(OnboardingProgress::where('organization_id', $orgId)->count())->toBe(1);
});

it('returns the same organization when provisioning is repeated', function () {
    $lead = fullyQualifiedLead();

    $first = app(CustomerProvisioningEngine::class)->provision($lead);
    $second = app(CustomerProvisioningEngine::class)->provision($lead->fresh());

    expect($second->id)->toBe($first->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fix 3 — post-sale surfaces actually receive data
// ─────────────────────────────────────────────────────────────────────────────

it('creates an onboarding record related to the provisioned organization', function () {
    $lead = winLead(fullyQualifiedLead());
    $org = $lead->customerOrganization;

    $onboarding = OnboardingProgress::where('organization_id', $org->id)->first();

    expect($onboarding)->not->toBeNull()
        ->and($onboarding->status)->toBe('pending')
        ->and($onboarding->current_step)->toBe('password_setup');
});

it('creates a subscription attached to both the organization and the clinic', function () {
    $lead = winLead(fullyQualifiedLead());
    $org = $lead->customerOrganization;
    $clinic = Clinic::where('organization_id', $org->id)->firstOrFail();

    $subscription = CompanySubscription::where('organization_id', $org->id)->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->clinic_id)->toBe($clinic->id)
        ->and($subscription->status->value)->toBe('active')
        ->and($subscription->ends_at)->not->toBeNull();
});

it('surfaces a newly provisioned customer on the subscriptions workspace', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    winLead(fullyQualifiedLead(), $admin);

    $payload = app(CommercialSubscriptionService::class)->getSubscriptionsPayload($admin);

    expect($payload['metrics']['total_subscriptions'])->toBeGreaterThan(0)
        ->and(collect($payload['subscriptions'])->pluck('companyName'))
        ->toContain('Northgate Dental Group');
});

it('makes the new subscription discoverable by the renewals radar', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    winLead(fullyQualifiedLead(), $admin);

    $payload = app(CommercialRenewalService::class)->getRenewalsPayload($admin);

    expect(collect($payload['renewals'])->pluck('companyName'))
        ->toContain('Northgate Dental Group');

    $row = collect($payload['renewals'])->firstWhere('companyName', 'Northgate Dental Group');

    expect($row['renewalDate'])->not->toBe('—')
        ->and($row['daysToRenewal'])->toBeGreaterThan(0);
});

it('shows the provisioned customer on the customer success accounts workspace', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    winLead(fullyQualifiedLead(), $admin);

    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Index')
            /* The headline counts arrive after the first paint now. */
            ->loadDeferredProps(['metrics'], fn ($page) => $page
                ->where('metrics.total_accounts', 1)
            )
            ->where('accounts.0.name', 'Northgate Dental Group')
        );

    $this->actingAs($admin)
        ->get(route('company.crm.onboarding.index'))
        ->assertRedirect(route('company.customer-success.accounts.index', absolute: false));
});

it('loads the provisioned branch on the account 360 workspace', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $lead = winLead(fullyQualifiedLead(), $admin);

    /*
     * `branches` is deferred — Account 360 sends its identity header first and
     * fetches each zone on its own, so the prop is not in the initial payload.
     * Resolving it is what this asserts against; reading `viewData` directly
     * would only ever prove the prop was absent.
     */
    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.show', $lead->customerOrganization))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($page) => $page
                ->has('branches', 1)
                ->whereNot('branches.0.name', null)
            )
        );
});

it('shows the provisioned customer on the subscriptions and customer success accounts route', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    winLead(fullyQualifiedLead(), $admin);

    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Index')
            /* The headline counts arrive after the first paint now. */
            ->loadDeferredProps(['metrics'], fn ($page) => $page
                ->where('metrics.total_accounts', 1)
            )
        );

    $this->actingAs($admin)
        ->get(route('company.crm.subscriptions.index'))
        ->assertRedirect(route('company.customer-success.accounts.index', absolute: false));
});

it('shows the provisioned customer on the renewals workspace route', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    winLead(fullyQualifiedLead(), $admin);

    $this->actingAs($admin)
        ->get(route('company.customer-success.renewals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Renewals/Index')
            ->where('metrics.due_this_month', 0) // one year out, so not due yet
        );

    $this->actingAs($admin)
        ->get(route('company.crm.renewals.index'))
        ->assertRedirect(route('company.customer-success.renewals.index', absolute: false));

    // …but it is discoverable in the radar itself.
    $payload = app(CommercialRenewalService::class)->getRenewalsPayload($admin);
    expect($payload['renewals'])->toHaveCount(1);
});

it('counts only genuinely imminent renewals as due this month', function () {
    // Carbon 3 returns a signed difference, so `$endsAt->diffInDays(now())` is
    // negative for any future date and `<= 30` was true for every live
    // subscription. Both the Subscriptions and Renewals headline figures
    // therefore counted the entire book as expiring.
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    // Phase 1 item 5 filters these surfaces to real customer accounts, so the
    // fixture needs a tenant beneath the organization to qualify as one.
    $org = Organization::factory()->create();
    Clinic::create([
        'organization_id' => $org->id,
        'name' => 'Imminent Renewal Clinic',
        'slug' => 'imminent-renewal-clinic',
        'status' => ClinicStatus::Active,
    ]);

    CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => 'active',
        'starts_at' => now()->subMonths(11),
        'ends_at' => now()->addDays(10),
        'max_users' => 5,
    ]);
    CompanySubscription::create([
        'organization_id' => $org->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
        'max_users' => 5,
    ]);

    $renewals = app(CommercialRenewalService::class)->getRenewalsPayload($admin);
    $subscriptions = app(CommercialSubscriptionService::class)->getSubscriptionsPayload($admin);

    expect($renewals['metrics']['due_this_month'])->toBe(1)
        ->and($subscriptions['metrics']['expiring_soon_count'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// Fix 5 — CRM module registration must match the real route
// ─────────────────────────────────────────────────────────────────────────────

it('registers the CRM module at the route the application actually serves', function () {
    $registry = file_get_contents(resource_path('js/registry/ModuleRegistry.ts'));

    expect($registry)->toContain('route: "/company/crm"');

    // And that route is really served, so the two can never drift apart
    // without this test noticing.
    $crmRoot = route('company.crm.dashboard', absolute: false);

    expect($crmRoot)->toStartWith('/company/crm');
});

it('resolves module paths by prefix rather than exact match', function () {
    $registry = file_get_contents(resource_path('js/registry/ModuleRegistry.ts'));

    // A module owns its subtree; exact equality would match only index pages.
    expect($registry)->toContain('resolveModuleForPath')
        ->and($registry)->toContain('clean.startsWith(`${m.route}/`)');
});

it('resolves breadcrumbs from the live url', function () {
    $engine = file_get_contents(resource_path('js/components/app-shell/BreadcrumbEngine.tsx'));

    expect($engine)->toContain('usePage')
        ->and($engine)->toContain('resolveModuleForPath')
        ->and($engine)->not->toContain('const currentPath = "/dashboard"');
});

it('walks the whole lifecycle from won to renewal eligibility', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    $tenant = Organization::factory()->create(['name' => 'OralCares HQ']);

    // 1. A lead exists, stamped with the vendor tenant exactly as createLead() does.
    $lead = fullyQualifiedLead(['organization_id' => $tenant->id]);

    // 2. It is won.
    $lead = winLead($lead, $admin);
    expect($lead->status->value)->toBe(LeadStatus::Won->value);

    // 3. Provisioning produced a customer organization.
    $org = $lead->customerOrganization;
    expect($org)->not->toBeNull();

    // 4. A clinic tenant exists beneath it.
    $clinic = Clinic::where('organization_id', $org->id)->first();
    expect($clinic)->not->toBeNull();

    // 5. A branch belongs to that clinic.
    $branch = Branch::withoutOrganizationScope()->where('clinic_id', $clinic->id)->first();
    expect($branch)->not->toBeNull()->and($branch->organization_id)->toBe($org->id);

    // 6. The admin user can reach the tenant.
    $membership = ClinicMembership::where('clinic_id', $clinic->id)->first();
    expect($membership)->not->toBeNull()->and($membership->is_primary_owner)->toBeTrue();

    // 7. Onboarding is waiting.
    expect(OnboardingProgress::where('organization_id', $org->id)->exists())->toBeTrue();

    // 8. A subscription is active.
    $subscription = CompanySubscription::where('organization_id', $org->id)->first();
    expect($subscription)->not->toBeNull();

    // 9. Renewals can discover it.
    $renewals = app(CommercialRenewalService::class)->getRenewalsPayload($admin);
    expect(collect($renewals['renewals'])->pluck('organizationId'))->toContain($org->id);
});
