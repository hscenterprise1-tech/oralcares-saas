<?php

use App\Models\CompanyEmployee;
use App\Models\CompanySubscription;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->employeeUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
        ]),
        /*
         * `manage-organizations` because this persona changes a customer's
         * health, which is a write. Reading an account and changing one are
         * separate authorities now.
         */
        ['view-crm', 'view-organizations', 'manage-organizations', 'view-subscriptions', 'view-renewals']
    );

    $this->companyEmployee = CompanyEmployee::create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->employeeUser->id,
        'employee_code' => 'EMP-[#08]',
        'employment_status' => 'active',
    ]);
});

it('computes organization account health payload metrics correctly', function () {
    $org = Organization::factory()->create([
        'health_score' => 95,
        'expansion_opportunity' => true,
    ]);

    License::create([
        'organization_id' => $org->id,
        'plan_name' => 'Enterprise Suite',
        'max_users' => 10,
        'max_branches' => 2,
        'status' => 'active',
    ]);

    $service = app(LeadWorkspaceService::class);
    $payload = $service->getOrganizationsPayload($this->employeeUser);

    expect($payload['metrics']['total_organizations'])->toBeGreaterThanOrEqual(1);
    expect($payload['metrics']['healthy_count'])->toBeGreaterThanOrEqual(1);
});

it('computes subscription entitlements payload metrics correctly', function () {
    CompanySubscription::create([
        'organization_id' => $this->organization->id,
        'status' => 'active',
        'max_users' => 10,
        'max_branches' => 2,
        'starts_at' => now()->subMonths(3),
        'ends_at' => now()->addMonths(9),
    ]);

    $service = app(LeadWorkspaceService::class);
    $payload = $service->getSubscriptionsPayload($this->employeeUser);

    expect($payload['metrics']['total_subscriptions'])->toBeGreaterThanOrEqual(1);
    expect($payload['metrics']['active_count'])->toBeGreaterThanOrEqual(1);
});

it('computes renewals radar payload metrics correctly', function () {
    CompanySubscription::create([
        'organization_id' => $this->organization->id,
        'status' => 'active',
        'max_users' => 10,
        'max_branches' => 2,
        'starts_at' => now()->subYear(),
        'ends_at' => now()->addDays(15),
    ]);

    $service = app(LeadWorkspaceService::class);
    $payload = $service->getRenewalsPayload($this->employeeUser);

    expect($payload['metrics']['due_this_month'])->toBeGreaterThanOrEqual(1);
});

it('allows updating customer health score and logs activity note', function () {
    $response = $this->actingAs($this->employeeUser)
        ->put(route('company.crm.organizations.update', $this->organization->id), [
            'health_score' => 88,
            'renewal_probability' => 90,
            'notes' => 'Customer added 2 new branch seats.',
        ]);

    $response->assertRedirect();

    $this->organization->refresh();
    expect($this->organization->health_score)->toBe(88);
});
