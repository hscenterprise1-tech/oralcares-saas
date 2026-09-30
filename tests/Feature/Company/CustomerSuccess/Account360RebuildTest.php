<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\CustomerChurnRisk;
use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    $this->vendorOrg = Organization::factory()->provider()->create([
        'name' => 'OralCares Provider HQ',
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

    $this->customerOrg = Organization::factory()->create([
        'name' => 'Apex Dental Care Rebuild',
        'assigned_user_id' => $this->csRep->id,
        'health_score' => 85,
        'churn_risk' => CustomerChurnRisk::Low,
        'renewal_probability' => 90,
        'expansion_opportunity' => true,
    ]);

    $this->adminUser = User::factory()->create([
        'organization_id' => $this->customerOrg->id,
        'user_type' => UserType::ClinicUser,
        'name' => 'Dr. Apex Admin',
        'email' => 'admin@apexdentalrebuild.com',
    ]);

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'customer_organization_id' => $this->customerOrg->id,
        'status' => LeadStatus::Won,
        'company_name' => 'Apex Dental Care Rebuild',
        'assigned_user_id' => $this->csRep->id,
    ]);

    $this->clinic = Clinic::factory()->create([
        'organization_id' => $this->customerOrg->id,
        'name' => 'Apex Main Rebuild',
        'slug' => 'apex-main-rebuild-'.uniqid(),
    ]);

    $this->subscription = CompanySubscription::create([
        'organization_id' => $this->customerOrg->id,
        'clinic_id' => $this->clinic->id,
        'plan_name' => 'Enterprise Cloud Rebuild',
        'status' => 'active',
        'mrr' => 14000,
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->addMonths(9),
    ]);

    $this->onboarding = OnboardingProgress::create([
        'organization_id' => $this->customerOrg->id,
        'current_step' => 'organization_profile',
        'status' => 'in_progress',
        'completed_steps' => ['welcome_call'],
    ]);
});

it('persists and validates ?tab= URL parameter across the canonical tabs', function () {
    /*
     * Five, not twelve. The page had a tab per table it could reach, which is
     * twelve places to look before knowing anything.
     */
    $canonicalTabs = ['overview', 'contract', 'setup', 'people', 'records'];

    foreach ($canonicalTabs as $tab) {
        $response = $this->actingAs($this->masterAdmin)
            ->get(route('company.customer-success.accounts.show', ['organization' => $this->customerOrg, 'tab' => $tab]));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Company/CustomerSuccess/Accounts/Show')
                ->where('activeTab', $tab)
                ->has('validTabs')
                ->where('validTabs.'.$tab, fn ($label) => ! empty($label))
            );
    }
});

it('safely falls back to overview when an invalid tab is passed in the URL', function () {
    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', ['organization' => $this->customerOrg, 'tab' => 'invalid_unknown_tab']));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Show')
            ->where('activeTab', 'overview')
        );
});

/**
 * Every tab this page used to have still resolves.
 *
 * Twelve became five, and a bookmark, a deep link from another module or the
 * redirect from the retired CRM onboarding route must land on the tab that
 * absorbed the old one rather than silently on Overview.
 */
it('lands a retired tab on the one that absorbed it', function (string $legacy, string $lands) {
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', ['organization' => $this->customerOrg, 'tab' => $legacy]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Show')
            ->where('activeTab', $lands)
        );
})->with([
    ['health', 'overview'],
    ['timeline', 'overview'],
    ['subscription', 'contract'],
    ['usage', 'contract'],
    ['renewal', 'contract'],
    ['lifecycle', 'contract'],
    ['onboarding', 'setup'],
    ['clinics', 'setup'],
    ['contacts', 'people'],
    ['commercial', 'records'],
    ['documents', 'records'],
    ['support', 'records'],
]);

it('provides a lightweight eager initial payload without eagerly evaluating heavy relations', function () {
    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $this->customerOrg));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/CustomerSuccess/Accounts/Show')
            ->has('account')
            ->where('account.id', $this->customerOrg->id)
            ->where('account.name', 'Apex Dental Care Rebuild')
            ->has('activeSubscription')
            ->has('can')
            ->has('activeTab')
            ->has('validTabs')
            ->missing('healthData')
            ->missing('commercialDocuments')
            ->missing('quotations')
            ->missing('invoices')
            ->missing('activities')
            ->missing('tasks')
        );
});

it('resolves deferred props on demand via loadDeferredProps', function () {
    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $this->customerOrg));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
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

it('strictly displays recorded subscription MRR and computes ARR as mrr * 12', function () {
    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $this->customerOrg));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeSubscription.mrr_raw', 14000)
            ->where('activeSubscription.arr_raw', 168000)
            ->where('activeSubscription.mrr', '₹14,000 / mo')
            ->where('activeSubscription.arr', '₹168,000')
            /*
             * The group is passed as an array, not a bare string.
             * `loadDeferredProps()` decides whether its first argument is the
             * callback with `is_callable()`, and this suite declares a global
             * helper named `subscription()` — so the string form handed the
             * group name over as the callback and raised a TypeError, but only
             * once the file declaring that helper had been loaded. That made it
             * pass alone and fail in a full run.
             */
            ->loadDeferredProps(['contract'], fn ($page) => $page
                ->where('subscriptions.0.mrrRaw', 14000)
                ->where('subscriptions.0.arrRaw', 168000)
            )
        );
});

it('enforces tenant isolation and prevents access to provider organizations or foreign tenant accounts', function () {
    // Provider organization cannot be viewed via Customer Success accounts show route
    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $this->vendorOrg))
        ->assertNotFound();

    // Another sales rep cannot access unassigned customer account
    $otherRep = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $otherRep->assignRole(Role::findByName('Sales Representative', 'web'));

    $response = $this->actingAs($otherRep)
        ->get(route('company.customer-success.accounts.show', $this->customerOrg));

    expect(in_array($response->status(), [403, 404]))->toBeTrue();
});

it('isolates eligible owners to clinic users of the specific customer organization', function () {
    $otherOrg = Organization::factory()->create(['name' => 'Other Clinic Org']);

    User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $this->customerOrg->id,
        'name' => 'Valid Clinic Doctor',
    ]);

    User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->customerOrg->id,
        'name' => 'Not Eligible Company User',
    ]);

    User::factory()->create([
        'user_type' => UserType::ClinicUser,
        'organization_id' => $otherOrg->id,
        'name' => 'Foreign Org Doctor',
    ]);

    $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $this->customerOrg))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['setup'], fn ($page) => $page
                ->has('eligibleOwners')
                ->where('eligibleOwners', fn ($owners) => collect($owners)->pluck('name')->contains('Valid Clinic Doctor')
                    && ! collect($owners)->pluck('name')->contains('Not Eligible Company User')
                    && ! collect($owners)->pluck('name')->contains('Foreign Org Doctor')
                )
            )
        );
});

it('enforces manage-organizations permission on mutation endpoints', function () {
    // Sales rep has view access and create-leads, but lacks manage-organizations
    $this->actingAs($this->csRep)
        ->put(route('company.customer-success.accounts.update', $this->customerOrg), [
            'health_score' => 95,
        ])
        ->assertForbidden();

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.clinics.store', $this->customerOrg), [
            'name' => 'New Unauthorized Clinic',
            'email' => 'newclinic@apexdental.com',
            'phone' => '9876543210',
        ])
        ->assertForbidden();

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.clinics.branches.store', ['organization' => $this->customerOrg, 'clinic' => $this->clinic]), [
            'name' => 'Unauthorized Branch',
        ])
        ->assertForbidden();

    $this->actingAs($this->csRep)
        ->post(route('company.customer-success.accounts.onboarding.complete-step', $this->customerOrg), [
            'step' => 'organization_profile',
        ])
        ->assertForbidden();

    // CS Manager has manage-organizations and can perform update
    $this->actingAs($this->csManager)
        ->put(route('company.customer-success.accounts.update', $this->customerOrg), [
            'health_score' => 95,
            'churn_risk' => 'medium',
            'renewal_probability' => 80,
            'expansion_opportunity' => false,
        ])
        ->assertSessionHasNoErrors();

    expect($this->customerOrg->fresh()->health_score)->toBe(95);
});

it('bounds heavy collections (activities, commercial documents) to 50 records', function () {
    // Create 60 activities
    for ($i = 0; $i < 60; $i++) {
        $this->customerOrg->activities()->create([
            'user_id' => $this->csRep->id,
            'type' => 'note',
            'description' => "Activity note {$i}",
        ]);
    }

    // Create 55 commercial documents
    for ($i = 0; $i < 55; $i++) {
        CommercialDocument::create([
            'lead_id' => $this->lead->id,
            'document_number' => "DOC-{$i}",
            'type' => 'quotation',
            'title' => "Document {$i}",
            'file_path' => "docs/test_{$i}.pdf",
            'file_size_bytes' => 1024,
            'uploaded_by_user_id' => $this->csRep->id,
        ]);
    }

    $response = $this->actingAs($this->masterAdmin)
        ->get(route('company.customer-success.accounts.show', $this->customerOrg));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(['overview'], fn ($page) => $page
                ->has('activities', 50)
            )
            ->loadDeferredProps(['records'], fn ($page) => $page
                ->has('commercialDocuments', 50)
            )
        );
});
