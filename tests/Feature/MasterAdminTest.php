<?php

use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * What is left of the master console.
 *
 * Its clinic pages are gone: a clinic is now created, renamed and retired from
 * the Customer Success account that owns it, which is where the rest of that
 * customer already lived. `Company/CustomerSuccess/AccountStructureTest` covers
 * that behaviour, including the primary-owner assignment this console used to
 * perform.
 *
 * `/company/master` is gone too. It rendered `Company/Dashboard` with
 * `totalClinics`, `activeClinics` and friends — props that page does not
 * declare — so it answered 200 with a blank dashboard. Nothing linked to it and
 * both login controllers already send a master admin to `company.dashboard`.
 *
 * What remains worth testing is who may reach the company dashboard, which is
 * what these do.
 */
it('sends a guest to the login page rather than the dashboard', function () {
    $this->get(route('company.dashboard'))->assertRedirect();
});

it('lets a master admin open the company dashboard', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->get(route('company.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Company/Dashboard'));
});

it('keeps a clinic user out of the company dashboard', function () {
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    $response = $this->actingAs($clinicUser)->get(route('company.dashboard'));

    expect($response->status())->not->toBe(200);
});

it('renders the dashboard with the payload the page actually reads', function () {
    /*
     * The old assertions here named `totalOrganizations`, `activeOrganizations`
     * and `totalEmployees`. `ExecutiveDashboardService` sends none of those —
     * the dashboard was rebuilt around a snapshot/workspace shape and the test
     * was never moved with it, so it had been asserting a dead contract.
     */
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);
    Organization::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('company.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Dashboard')
            ->has('snapshot')
            ->has('workspace')
            ->has('workspace.attention')
        );
});

it('renders safely with no data at all', function () {
    $admin = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    $this->actingAs($admin)
        ->get(route('company.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Company/Dashboard'));
});

it('no longer routes the retired master console', function () {
    foreach (['master.index', 'master.clinics.index', 'master.clinics.create', 'master.clinics.store', 'master.clinics.show'] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} still exists");
    }
});
