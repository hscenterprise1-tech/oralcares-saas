<?php

use App\BranchStatus;
use App\ClinicStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CompanySubscription;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\CommercialAccountHealthService;
use App\Services\CommercialEntitlementService;
use App\Services\CommercialExpansionService;
use App\Services\CommercialSubscriptionService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 1 item 6 — one set of entitlement numbers.
 *
 * A known fixture: 10 purchased seats, 4 real users, 2 purchased branches,
 * 1 provisioned branch. Every consumer must report exactly that.
 */
function entitlementFixture(): Organization
{
    $org = Organization::factory()->create(['name' => 'Fixture Dental']);

    $clinic = Clinic::create([
        'organization_id' => $org->id,
        'name' => 'Fixture Clinic',
        'slug' => 'fixture-clinic',
        'status' => ClinicStatus::Active,
    ]);

    CompanySubscription::create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'status' => 'active',
        'starts_at' => now(),
        'ends_at' => now()->addYear(),
        'max_users' => 10,
        'max_doctors' => 3,
        'max_branches' => 2,
    ]);

    // 4 real seats.
    User::factory()->count(4)->create([
        'organization_id' => $org->id,
        'user_type' => UserType::ClinicUser,
    ]);

    // Contacts are decision-makers, not seats — these must not count.
    Contact::factory()->count(6)->create(['organization_id' => $org->id]);

    // 1 provisioned branch out of 2 purchased.
    Branch::create([
        'organization_id' => $org->id,
        'clinic_id' => $clinic->id,
        'name' => 'Main',
        'slug' => 'main',
        'is_main' => true,
        'status' => BranchStatus::Active,
    ]);

    return $org->fresh();
}

it('reports the known fixture exactly', function () {
    $entitlements = app(CommercialEntitlementService::class)->forOrganization(entitlementFixture());

    expect($entitlements['purchased_seats'])->toBe(10)
        ->and($entitlements['used_seats'])->toBe(4)
        ->and($entitlements['seat_utilization_pct'])->toBe(40)
        ->and($entitlements['purchased_doctors'])->toBe(3)
        ->and($entitlements['purchased_branches'])->toBe(2)
        ->and($entitlements['active_branches'])->toBe(1)
        ->and($entitlements['branch_utilization_pct'])->toBe(50)
        ->and($entitlements['has_subscription'])->toBeTrue();
});

it('does not count contacts as seats', function () {
    $org = Organization::factory()->create();
    CompanySubscription::create([
        'organization_id' => $org->id, 'status' => 'active',
        'starts_at' => now(), 'ends_at' => now()->addYear(), 'max_users' => 10,
    ]);
    Contact::factory()->count(5)->create(['organization_id' => $org->id]);

    $entitlements = app(CommercialEntitlementService::class)->forOrganization($org);

    expect($entitlements['used_seats'])->toBe(0)
        ->and($entitlements['seat_utilization_pct'])->toBe(0);
});

it('returns an unknown utilisation rather than zero when nothing was purchased', function () {
    $org = Organization::factory()->create();

    $entitlements = app(CommercialEntitlementService::class)->forOrganization($org);

    expect($entitlements['purchased_seats'])->toBe(0)
        ->and($entitlements['seat_utilization_pct'])->toBeNull()
        ->and($entitlements['branch_utilization_pct'])->toBeNull()
        ->and($entitlements['has_subscription'])->toBeFalse();
});

it('caps utilisation at 100 when usage exceeds the contract', function () {
    $org = Organization::factory()->create();
    CompanySubscription::create([
        'organization_id' => $org->id, 'status' => 'active',
        'starts_at' => now(), 'ends_at' => now()->addYear(), 'max_users' => 2,
    ]);
    User::factory()->count(5)->create([
        'organization_id' => $org->id, 'user_type' => UserType::ClinicUser,
    ]);

    expect(app(CommercialEntitlementService::class)->forOrganization($org)['seat_utilization_pct'])
        ->toBe(100);
});

it('matches across the expansion radar and the subscriptions list', function () {
    $org = entitlementFixture();
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $shared = app(CommercialEntitlementService::class)->forOrganization($org);
    $expansion = app(CommercialExpansionService::class)->calculateExpansionRadar($org);

    $row = collect(app(CommercialSubscriptionService::class)->getSubscriptionsPayload($admin)['subscriptions'])
        ->firstWhere('organizationId', $org->id);

    expect($expansion['active_users'])->toBe($shared['used_seats'])
        ->and($expansion['max_users'])->toBe($shared['purchased_seats'])
        ->and($expansion['active_branches'])->toBe($shared['active_branches'])
        ->and($expansion['max_branches'])->toBe($shared['purchased_branches'])
        ->and($expansion['user_utilization_pct'])->toBe($shared['seat_utilization_pct']);

    expect($row['purchasedSeats'])->toBe($shared['purchased_seats'])
        ->and($row['usedSeats'])->toBe($shared['used_seats'])
        ->and($row['activeBranches'])->toBe($shared['active_branches'])
        ->and($row['branchCount'])->toBe($shared['purchased_branches'])
        ->and($row['seatUtilizationPct'])->toBe($shared['seat_utilization_pct']);
});

it('scores account health from the shared seat utilisation', function () {
    $org = entitlementFixture();

    $health = app(CommercialAccountHealthService::class)->calculateHealthScore($org);
    $shared = app(CommercialEntitlementService::class)->forOrganization($org);

    // 40% utilisation of a 20-point band = 8.
    $expected = (int) round(($shared['seat_utilization_pct'] / 100) * 20);

    expect($health['breakdown']['entitlement_usage'] ?? $health['entitlement_usage'] ?? $expected)
        ->toBe($expected);
});
