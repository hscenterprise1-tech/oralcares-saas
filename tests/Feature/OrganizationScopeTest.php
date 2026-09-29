<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Every test in this file is a test *of* the boundary, so every one of them
 * arms it.
 *
 * `OrganizationScope` lifts itself in console — artisan, queue workers, the
 * scheduler and seeders act as the system across every tenant, and the suite
 * runs there too. Before the scope became fail-closed it carried no console
 * rule at all, so these assertions happened to hold without asking for
 * anything. They are explicit now: a test that asserts the filter applies has
 * to say that it wants the filter applied.
 */
beforeEach(fn () => entersOrganizationScope());

it('scopes queries to the authenticated users organization', function () {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    $user1 = User::factory()->create(['organization_id' => $org1->id]);

    // Branches belong to org 1
    Branch::factory()->count(2)->create([
        'organization_id' => $org1->id,
        'clinic_id' => null, // Just to bypass if necessary, but branch factory should handle it
    ]);

    // Branches belong to org 2
    Branch::factory()->count(3)->create([
        'organization_id' => $org2->id,
    ]);

    // Authenticate as user 1
    $this->actingAs($user1);

    // Should only see branches from org 1
    $branches = Branch::all();

    expect($branches)->toHaveCount(2);
});

it('automatically assigns organization_id on creation', function () {
    $org1 = Organization::factory()->create();
    $user1 = User::factory()->create(['organization_id' => $org1->id]);

    $this->actingAs($user1);

    $branch = Branch::create([
        'name' => 'New Branch',
        'slug' => 'new-branch',
    ]);

    expect($branch->organization_id)->toBe($org1->id);
});

it('can bypass organization scope using withoutOrganizationScope', function () {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    $user1 = User::factory()->create(['organization_id' => $org1->id]);

    Branch::factory()->create(['organization_id' => $org1->id]);
    Branch::factory()->create(['organization_id' => $org2->id]);

    $this->actingAs($user1);

    // Scoped query
    expect(Branch::all())->toHaveCount(1);

    // Unscoped query
    expect(Branch::withoutOrganizationScope()->get())->toHaveCount(2);
});
