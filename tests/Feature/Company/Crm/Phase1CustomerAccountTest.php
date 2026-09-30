<?php

use App\ClinicStatus;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use App\Services\CommercialRenewalService;
use App\Services\CommercialSubscriptionService;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 5 — a customer is a customer everywhere.
 *
 * `organizations` holds the vendor's own organization alongside the customers
 * sold to, and nothing distinguished them. Accounts, Onboarding, Subscriptions
 * and Renewals therefore all listed OralCares as its own client.
 *
 * `Organization::scopeCustomerAccounts()` is the single definition; all four
 * read paths use it rather than inventing a filter each.
 */
function platformAdmin(): User
{
    return User::factory()->create(['user_type' => UserType::MasterAdmin]);
}

function provisionedCustomer(User $admin, string $name = 'Won Customer Dental'): Lead
{
    /*
     * The vendor is created as a provider, explicitly.
     *
     * These fixtures used to create it as an ordinary organization and rely on
     * the customer scope inferring the difference from whether it had clinics
     * or won leads. It is a recorded role now, so a test that wants the seller
     * has to say so — which is the point of the change.
     */
    $vendor = Organization::firstOrCreate(
        ['name' => 'OralCares HQ'],
        [
            'legal_name' => 'OralCares India Pvt Ltd',
            'email' => 'hq@oralcares.test',
            'role' => OrganizationRole::Provider,
        ]
    );

    $lead = Lead::factory()->create([
        'company_name' => $name,
        'contact_name' => 'Buyer Person',
        'email' => 'buyer@'.Str::slug($name).'.test',
        'organization_id' => $vendor->id,
        'status' => LeadStatus::Negotiation,
    ]);

    app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $admin, 'test');

    return $lead->fresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// The definition
// ─────────────────────────────────────────────────────────────────────────────

it('does not treat the vendor organization as a customer', function () {
    Organization::factory()->provider()->create(['name' => 'OralCares HQ']);

    expect(Organization::customerAccounts()->pluck('name'))->not->toContain('OralCares HQ');
});

it('treats an organization won from a lead as a customer', function () {
    $lead = provisionedCustomer(platformAdmin());

    expect(Organization::customerAccounts()->pluck('name'))
        ->toContain('Won Customer Dental')
        ->not->toContain('OralCares HQ');
});

/*
 * Deliberately inverted.
 *
 * This asserted that an organization with no clinic and no won lead was not a
 * customer — which was the old definition, and the reason a customer
 * provisioned a moment ago was invisible until it acquired one. An
 * organization's role is now stated rather than inferred from its history, so a
 * bare organization is a customer that has not been set up yet.
 *
 * What replaces the old protection is the pair below: only the provider is
 * excluded, and it is excluded because it says it is the provider.
 */
it('treats an organization with no history yet as a customer', function () {
    Organization::factory()->create(['name' => 'Newly Provisioned Org']);

    expect(Organization::customerAccounts()->pluck('name'))->toContain('Newly Provisioned Org');
});

it('excludes only the provider, and does so by role rather than by name', function () {
    Organization::factory()->provider()->create(['name' => 'Some Other Name Entirely']);

    expect(Organization::customerAccounts()->pluck('name'))
        ->not->toContain('Some Other Name Entirely');
});

it('includes an organization that has a clinic but no lead', function () {
    // Covers customers provisioned outside the CRM lead flow.
    $org = Organization::factory()->create(['name' => 'Direct Tenant']);
    Clinic::create([
        'organization_id' => $org->id,
        'name' => 'Direct Tenant Clinic',
        'slug' => 'direct-tenant-clinic',
        'status' => ClinicStatus::Active,
    ]);

    expect(Organization::customerAccounts()->pluck('name'))->toContain('Direct Tenant');
});

// ─────────────────────────────────────────────────────────────────────────────
// Every post-sale surface agrees
// ─────────────────────────────────────────────────────────────────────────────

it('hides the vendor organization from the accounts list', function () {
    $admin = platformAdmin();
    provisionedCustomer($admin);

    $this->actingAs($admin)
        ->get(route('company.customer-success.accounts.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $names = array_column($page->toArray()['props']['accounts'], 'name');

            expect($names)->toContain('Won Customer Dental')
                ->not->toContain('OralCares HQ');
        });

    $this->actingAs($admin)
        ->get(route('company.crm.organizations.index'))
        ->assertRedirect(route('company.customer-success.accounts.index', absolute: false));
});

it('hides the vendor organization and redirects legacy onboarding queue', function () {
    $admin = platformAdmin();
    provisionedCustomer($admin);

    // A stray onboarding row against the vendor must not surface.
    $vendor = Organization::where('name', 'OralCares HQ')->firstOrFail();
    OnboardingProgress::create([
        'organization_id' => $vendor->id,
        'current_step' => 'password_setup',
        'status' => 'pending',
        'completed_steps' => [],
    ]);

    $this->actingAs($admin)
        ->get(route('company.crm.onboarding.index'))
        ->assertRedirect(route('company.customer-success.accounts.index', absolute: false));
});

it('hides the vendor organization from subscriptions', function () {
    $admin = platformAdmin();
    provisionedCustomer($admin);

    $vendor = Organization::where('name', 'OralCares HQ')->firstOrFail();
    CompanySubscription::create([
        'organization_id' => $vendor->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
        'max_users' => 5,
    ]);

    $payload = app(CommercialSubscriptionService::class)->getSubscriptionsPayload($admin);
    $names = collect($payload['subscriptions'])->pluck('companyName');

    expect($names)->toContain('Won Customer Dental')->not->toContain('OralCares HQ');
});

it('hides the vendor organization from renewals', function () {
    $admin = platformAdmin();
    provisionedCustomer($admin);

    $vendor = Organization::where('name', 'OralCares HQ')->firstOrFail();
    CompanySubscription::create([
        'organization_id' => $vendor->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
        'max_users' => 5,
    ]);

    $payload = app(CommercialRenewalService::class)->getRenewalsPayload($admin);
    $names = collect($payload['renewals'])->pluck('companyName');

    expect($names)->toContain('Won Customer Dental')->not->toContain('OralCares HQ');
});

it('keeps the provisioned customer reachable on every post-sale surface', function () {
    $admin = platformAdmin();
    $lead = provisionedCustomer($admin);
    $org = $lead->customerOrganization;

    expect(Organization::customerAccounts()->whereKey($org->id)->exists())->toBeTrue()
        ->and(OnboardingProgress::where('organization_id', $org->id)->exists())->toBeTrue()
        ->and(CompanySubscription::where('organization_id', $org->id)->exists())->toBeTrue();

    $subscriptions = app(CommercialSubscriptionService::class)->getSubscriptionsPayload($admin);
    $renewals = app(CommercialRenewalService::class)->getRenewalsPayload($admin);

    expect(collect($subscriptions['subscriptions'])->pluck('organizationId'))->toContain($org->id)
        ->and(collect($renewals['renewals'])->pluck('organizationId'))->toContain($org->id);
});
