<?php

use App\Models\Organization;
use App\Models\User;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->companyUser = grantCrmPermissions(
        User::factory()->create([
            'user_type' => UserType::CompanyUser,
        ]),
        ['view-crm', 'view-organizations']
    );

    $this->clinicUser = User::factory()->create([
        'user_type' => UserType::ClinicUser,
    ]);

    $this->organization = Organization::factory()->create([
        'health_score' => 100,
        'renewal_probability' => 100,
        'churn_risk' => 'Low',
        'expansion_opportunity' => false,
    ]);
});

it('allows company user to view organizations list on customer success and redirects legacy route', function () {
    $response = $this->actingAs($this->companyUser)
        ->get(route('company.customer-success.accounts.index'));

    $response->assertStatus(200);

    $this->actingAs($this->companyUser)
        ->get(route('company.crm.organizations.index'))
        ->assertRedirect(route('company.customer-success.accounts.index', absolute: false));
});

it('allows master admin without an organization_id to view organizations list', function () {
    // Master admins are not scoped to a single organization_id; the controller
    // must resolve a fallback org (User::resolveOrganizationId()) and bypass
    // the org filter entirely, instead of leaving organization_id raw/null.
    $masterAdmin = User::factory()->create([
        'organization_id' => null,
        'user_type' => 'master_admin',
    ]);

    $response = $this->actingAs($masterAdmin)
        ->get(route('company.customer-success.accounts.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Company/CustomerSuccess/Accounts/Index')
        ->has('accounts')
    );
});

it('allows company user to update customer health metrics and logs activity', function () {
    /*
     * Writing to an account takes `manage-organizations`, which the read-only
     * grant in `beforeEach` deliberately does not carry. Reading and changing a
     * customer are separate authorities now — see the refusal test below, which
     * pins that a reader alone cannot do this.
     */
    grantCrmPermissions($this->companyUser, ['view-crm', 'view-organizations', 'manage-organizations']);

    $payload = [
        'health_score' => 65,
        'renewal_probability' => 50,
        'churn_risk' => 'High',
        'expansion_opportunity' => true,
        'notes' => 'Customer is unhappy with onboarding speed.',
    ];

    $response = $this->actingAs($this->companyUser)
        ->put(route('company.crm.organizations.update', $this->organization), $payload);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();

    $this->assertDatabaseHas('organizations', [
        'id' => $this->organization->id,
        'health_score' => 65,
        'renewal_probability' => 50,
        'churn_risk' => 'High',
        'expansion_opportunity' => 1, // true is stored as 1 in sqlite/mysql tinyint
    ]);

    $this->assertDatabaseHas('activities', [
        'subject_type' => 'organization',
        'subject_id' => $this->organization->id,
        'type' => 'note',
    ]);
});

it('refuses a read-only company user the account write', function () {
    /*
     * `view-organizations` used to authorise every write on this policy —
     * changing health, creating clinics, marking an account live and re-sending
     * a customer's password-setup invite were all reachable by anybody who
     * could open the page. This pins the split.
     */
    $this->actingAs($this->companyUser)
        ->put(route('company.crm.organizations.update', $this->organization), ['health_score' => 10])
        ->assertForbidden();

    expect($this->organization->fresh()->health_score)->toBe(100);
});

it('prevents clinic user from updating customer health metrics', function () {
    $payload = [
        'health_score' => 65,
    ];

    $response = $this->actingAs($this->clinicUser)
        ->put(route('company.crm.organizations.update', $this->organization), $payload);

    $response->assertForbidden();
});
