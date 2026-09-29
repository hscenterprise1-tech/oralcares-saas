<?php

declare(strict_types=1);

namespace Tests\Feature\Company\CustomerSuccess;

use App\LeadStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CompanySubscription;
use App\Models\Lead;
use App\Models\License;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\SubscriptionStatus;
use App\UserType;
use Database\Seeders\CrmRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The two commercial facts Won now asks for.
 *
 * These are the same two the conversion gate has always demanded — an accepted
 * agreement and a settled payment. Won had no preconditions at all, so a deal
 * could be won and then refuse to convert. These tests are about renewal and
 * expansion provisioning, not about the gate, so they satisfy it.
 */
function makeClosable(Lead $deal): void
{
    CommercialDocument::create([
        'lead_id' => $deal->id,
        'type' => 'agreement',
        'document_number' => 'AGR-RENEWAL-'.$deal->id,
        'version' => 1,
        'status' => 'accepted',
        'currency' => 'INR',
        'subtotal' => 240000,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'grand_total' => 240000,
    ]);

    Payment::create([
        'lead_id' => $deal->id,
        'amount' => 240000,
        'currency' => 'INR',
        'gateway' => 'manual',
        'status' => 'successful',
    ]);
}

beforeEach(function () {
    setPermissionsTeamId(0);
    $this->seed(CrmRolePermissionSeeder::class);

    $this->vendorOrg = Organization::factory()->create([
        'name' => 'OralCares HQ',
    ]);

    $this->user = User::factory()->create([
        'organization_id' => $this->vendorOrg->id,
        'user_type' => UserType::CompanyUser,
    ]);
    setPermissionsTeamId(0);
    $this->user->assignRole(Role::findByName('CRM Manager', 'web'));
});

/*
 * This used to assert that the renewals queue opened a CRM deal. A customer
 * who has already bought does not go back into the pipeline for winning
 * strangers — the contract is extended where it lives, and the extension is
 * recorded against the subscription rather than against a new deal.
 */
test('the renewals queue extends the contract in place and opens no CRM deal', function () {
    $customerOrg = Organization::factory()->customer()->create([
        'name' => 'Dr. Sharma Smile Clinic',
        'contact_person' => 'Dr. Sharma',
        'email' => 'sharma@example.com',
        'mobile' => '+919876543210',
        /*
         * Owned, because the contract verbs check reachability before they
         * write — the old deal-creation route did not, so a manager could raise
         * a deal against an account outside their own book.
         */
        'assigned_user_id' => $this->user->id,
    ]);

    $subscription = CompanySubscription::create([
        'organization_id' => $customerOrg->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(11),
        'ends_at' => now()->addDays(20),
        'max_users' => 5,
        'max_branches' => 1,
        'mrr' => 15000.00,
    ]);

    $endsAt = $subscription->ends_at->copy();

    $this->actingAs($this->user)
        ->post(route('company.customer-success.subscriptions.renew', $subscription), ['months' => 12])
        ->assertRedirect();

    expect($subscription->fresh()->ends_at->toDateString())
        ->toBe($endsAt->addMonths(12)->toDateString())
        /* The money is untouched: a renewal is not a repricing. */
        ->and((float) $subscription->fresh()->mrr)->toBe(15000.0)
        ->and(Lead::where('customer_organization_id', $customerOrg->id)->exists())->toBeFalse();
});

test('winning a renewal deal extends the existing subscription without duplicate org, clinic, or branch', function () {
    $customerOrg = Organization::factory()->create([
        'name' => 'Dr. Mehta Multispecialty Clinic',
        'contact_person' => 'Dr. Mehta',
        'email' => 'mehta@example.com',
        'mobile' => '+919876543211',
    ]);

    $clinic = Clinic::create([
        'organization_id' => $customerOrg->id,
        'name' => 'Mehta Main Clinic',
        'slug' => 'mehta-main-clinic',
        'status' => 'active',
    ]);

    $branch = Branch::create([
        'organization_id' => $customerOrg->id,
        'clinic_id' => $clinic->id,
        'name' => 'Main Branch',
        'slug' => 'main-branch',
        'is_main' => true,
    ]);

    $originalExpiry = now()->addDays(15);
    $license = License::create([
        'organization_id' => $customerOrg->id,
        'plan_name' => 'Enterprise HMS',
        'max_branches' => 1,
        'max_users' => 5,
        'status' => 'active',
        'expires_at' => $originalExpiry,
    ]);

    $subscription = CompanySubscription::create([
        'organization_id' => $customerOrg->id,
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(11),
        'ends_at' => $originalExpiry,
        'max_users' => 5,
        'max_branches' => 1,
        'mrr' => 20000.00,
    ]);

    $orgCountBefore = Organization::count();
    $clinicCountBefore = Clinic::count();
    $branchCountBefore = Branch::withoutOrganizationScope()->count();

    // Create renewal deal
    $deal = Lead::create([
        'company_name' => 'Renewal: Dr. Mehta Multispecialty Clinic',
        'contact_name' => 'Dr. Mehta',
        'email' => 'mehta@example.com',
        'phone' => '+919876543211',
        'organization_id' => $this->vendorOrg->id,
        'customer_organization_id' => $customerOrg->id,
        'status' => LeadStatus::Contacted,
        'source' => 'website',
        'expected_mrr' => 20000.00,
        'assigned_user_id' => $this->user->id,
    ]);

    // Move deal to Won
    makeClosable($deal);

    $response = $this->actingAs($this->user)
        ->post(route('company.crm.leads.transition', $deal), [
            'stage' => LeadStatus::Won->value,
        ]);

    $response->assertRedirect();

    // Verify subscription was extended by 1 year
    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->ends_at->isAfter(now()->addMonths(11)))->toBeTrue();

    // Verify license expires_at was updated
    $license->refresh();
    expect($license->status)->toBe('active')
        ->and($license->expires_at->isAfter(now()->addMonths(11)))->toBeTrue();

    // Verify no duplicates created
    expect(Organization::count())->toBe($orgCountBefore)
        ->and(Clinic::count())->toBe($clinicCountBefore)
        ->and(Branch::withoutOrganizationScope()->count())->toBe($branchCountBefore);
});

test('winning an expansion deal updates existing entitlements without duplicate org, clinic, or branch', function () {
    $customerOrg = Organization::factory()->create([
        'name' => 'Sterling Dental Group',
        'contact_person' => 'Dr. Sterling',
        'email' => 'sterling@example.com',
        'mobile' => '+919876543212',
    ]);

    $clinic = Clinic::create([
        'organization_id' => $customerOrg->id,
        'name' => 'Sterling Main Clinic',
        'slug' => 'sterling-main-clinic',
        'status' => 'active',
    ]);

    $branch = Branch::create([
        'organization_id' => $customerOrg->id,
        'clinic_id' => $clinic->id,
        'name' => 'Main Branch',
        'slug' => 'main-branch-2',
        'is_main' => true,
    ]);

    $license = License::create([
        'organization_id' => $customerOrg->id,
        'plan_name' => 'Standard Dental Suite',
        'max_branches' => 1,
        'max_users' => 5,
        'status' => 'active',
        'expires_at' => now()->addYear(),
    ]);

    $subscription = CompanySubscription::create([
        'organization_id' => $customerOrg->id,
        'clinic_id' => $clinic->id,
        'status' => SubscriptionStatus::Active,
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->addMonths(9),
        'max_users' => 5,
        'max_branches' => 1,
        'mrr' => 10000.00,
    ]);

    $orgCountBefore = Organization::count();
    $clinicCountBefore = Clinic::count();
    $branchCountBefore = Branch::withoutOrganizationScope()->count();

    // Create expansion deal
    $deal = Lead::create([
        'company_name' => 'Expansion: Sterling Dental Group',
        'contact_name' => 'Dr. Sterling',
        'email' => 'sterling@example.com',
        'phone' => '+919876543212',
        'organization_id' => $this->vendorOrg->id,
        'customer_organization_id' => $customerOrg->id,
        'status' => LeadStatus::QuotationSent,
        'source' => 'website',
        'expected_mrr' => 25000.00,
        'assigned_user_id' => $this->user->id,
    ]);

    // Move expansion deal to Won
    makeClosable($deal);

    $response = $this->actingAs($this->user)
        ->post(route('company.crm.leads.transition', $deal), [
            'stage' => LeadStatus::Won->value,
        ]);

    $response->assertRedirect();

    // Verify entitlements were bumped on subscription and license
    $subscription->refresh();
    expect($subscription->max_users)->toBeGreaterThan(5)
        ->and($subscription->max_branches)->toBeGreaterThanOrEqual(1);

    $license->refresh();
    expect($license->max_users)->toBeGreaterThan(5);

    // Verify no duplicate records created
    expect(Organization::count())->toBe($orgCountBefore)
        ->and(Clinic::count())->toBe($clinicCountBefore)
        ->and(Branch::withoutOrganizationScope()->count())->toBe($branchCountBefore);
});
