<?php

use App\Models\ClinicalDepartment;
use App\Models\Organization;
use App\Models\User;

it('automatically assigns organization_id based on authenticated user', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user);

    $department = ClinicalDepartment::create([
        'name' => 'Cardiology',
        'code' => 'CARD',
        'description' => 'Heart stuff',
        'is_active' => true,
    ]);

    expect($department->organization_id)->toBe($organization->id);
});

it('scopes clinical master data to the users organization', function () {
    /*
     * This test asserts the boundary, so it arms it. `OrganizationScope` lifts
     * itself in console for artisan, queue workers and seeders, and the suite
     * runs there — see `entersOrganizationScope()` in `tests/Pest.php`.
     */
    entersOrganizationScope();

    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    $user1 = User::factory()->create(['organization_id' => $org1->id]);
    $user2 = User::factory()->create(['organization_id' => $org2->id]);

    $this->actingAs($user1);
    ClinicalDepartment::create(['name' => 'Org1 Dept']);

    $this->actingAs($user2);
    ClinicalDepartment::create(['name' => 'Org2 Dept']);

    // Check visibility for User 1
    $this->actingAs($user1);
    $visibleDepts = ClinicalDepartment::all();
    expect($visibleDepts)->toHaveCount(1)
        ->and($visibleDepts->first()->name)->toBe('Org1 Dept');

    // Without global scope
    $allDepts = ClinicalDepartment::withoutOrganizationScope()->get();
    expect($allDepts)->toHaveCount(2);
});

it('can fetch clinical departments via API', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($user);
    ClinicalDepartment::create(['name' => 'Ortho']);

    $response = $this->get(route('clinical.master.departments.index'));
    $response->assertStatus(200);
});
