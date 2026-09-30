<?php

use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * An organization is either the company running OralCares or a clinic it sold
 * to, and nothing recorded which. Three different guesses stood in for the
 * fact: a literal name match on 'OralCares HQ', `min('id')`, and "does this row
 * happen to have clinics or won leads" — the last of which is why the Accounts
 * directory read empty.
 */
it('finds the provider by role, whatever it is called', function () {
    $provider = Organization::factory()->provider()->create(['name' => 'Something Else Entirely']);
    Organization::factory()->create(['name' => 'OralCares HQ']);

    // The decoy is named like the seller and is not one. Renaming the real
    // provider used to break the product; now it changes nothing.
    expect(Organization::provider()?->id)->toBe($provider->id);
});

it('treats a brand new organization as a customer', function () {
    // The default matters: at ten thousand clinics there is exactly one
    // provider, so anything unstated is a customer.
    expect(Organization::factory()->create()->role)->toBe(OrganizationRole::Customer);
});

it('keeps the provider out of the customer list', function () {
    Organization::factory()->provider()->create(['name' => 'Us']);
    Organization::factory()->create(['name' => 'A Clinic']);

    expect(Organization::customerAccounts()->pluck('name')->all())->toBe(['A Clinic']);
});

it('lists a customer that has no clinic and no won lead yet', function () {
    /*
     * The old scope asked whether a row had clinics or won leads, so a customer
     * provisioned seconds ago was invisible — the exact reason Accounts showed
     * nothing while customers existed.
     */
    Organization::factory()->provider()->create();
    $fresh = Organization::factory()->create(['name' => 'Just Provisioned']);

    expect($fresh->clinics()->count())->toBe(0)
        ->and(Organization::customerAccounts()->pluck('name'))->toContain('Just Provisioned');
});

it('sends a company user with no organization to the provider', function () {
    $provider = Organization::factory()->provider()->create();
    Organization::factory()->create(['name' => 'A Customer We Sold To']);

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => null,
    ]);

    expect($user->resolveOrganizationId())->toBe($provider->id);
});

it('does not hand a company user the first customer when it was inserted first', function () {
    /*
     * `min('id')` was the old fallback. On any install where a customer landed
     * before the provider — a restore, a reseed, a migration — it silently gave
     * a member of staff a customer's tenant.
     */
    $customerInsertedFirst = Organization::factory()->create(['name' => 'Early Customer']);
    $provider = Organization::factory()->provider()->create(['name' => 'Us, Second']);

    expect($provider->id)->toBeGreaterThan($customerInsertedFirst->id);

    $user = User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => null,
    ]);

    expect($user->resolveOrganizationId())->toBe($provider->id);
});

it('creates a provider on a completely empty install rather than guessing', function () {
    expect(Organization::count())->toBe(0);

    $user = User::factory()->create([
        'user_type' => UserType::MasterAdmin,
        'organization_id' => null,
    ]);

    $resolved = Organization::find($user->resolveOrganizationId());

    expect($resolved->isProvider())->toBeTrue();
});

it('answers what it is without anyone having to infer it', function () {
    expect(Organization::factory()->provider()->create()->isProvider())->toBeTrue()
        ->and(Organization::factory()->provider()->create()->isCustomer())->toBeFalse()
        ->and(Organization::factory()->create()->isCustomer())->toBeTrue();
});
