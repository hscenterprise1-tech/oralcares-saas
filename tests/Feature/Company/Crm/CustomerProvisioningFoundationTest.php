<?php

use App\Events\CustomerProvisioned;
use App\LeadStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\License;
use App\Models\OnboardingProgress;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CustomerProvisioningEngine;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->employeeUser = grantCrmPermissions(
        User::factory()->companyUser()->create([
            'organization_id' => $this->organization->id,
        ]),
        /*
         * Advancing onboarding writes to the account, so this ops persona needs
         * the write authority as well as the read.
         */
        ['view-crm', 'view-leads', 'view-onboarding', 'view-organizations', 'manage-organizations']
    );

    $this->companyEmployee = CompanyEmployee::create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->employeeUser->id,
        'employee_code' => 'EMP-OPS01',
        'employment_status' => 'active',
    ]);
});

it('automatically provisions organization, branch, license, subscription, and admin on lead won', function () {
    Event::fake([CustomerProvisioned::class]);

    $lead = Lead::factory()->create([
        'organization_id' => null,
        'company_name' => 'Apex Dental Care',
        'contact_name' => 'Dr. Apex Patel',
        'email' => 'apex.patel@dentist.test',
        'phone' => '+919876543210',
    ]);

    $service = app(LeadWorkspaceService::class);
    $service->transitionStage($lead, LeadStatus::Won->value, $this->employeeUser);

    $lead->refresh();
    expect($lead->status->value)->toBe('won');
    expect($lead->organization_id)->not->toBeNull();

    $org = Organization::find($lead->organization_id);
    expect($org)->not->toBeNull();
    expect($org->name)->toBe('Apex Dental Care');

    // Primary Branch check
    expect($org->branches)->not->toBeEmpty();
    expect($org->branches->first()->is_main)->toBeTrue();

    // License & Subscription check
    expect($org->license)->not->toBeNull();

    // Primary Admin User check
    $adminUser = User::where('email', 'apex.patel@dentist.test')->first();
    expect($adminUser)->not->toBeNull();
    expect($adminUser->user_type)->toBe(UserType::ClinicUser);

    // OnboardingProgress check
    $onboarding = OnboardingProgress::where('organization_id', $org->id)->first();
    expect($onboarding)->not->toBeNull();
    expect($onboarding->current_step)->toBe('password_setup');

    Event::assertDispatched(CustomerProvisioned::class);
});

it('calculates correct license limits from approved commercial quotation', function () {
    $lead = Lead::factory()->create(['organization_id' => null]);

    CompanyQuotation::create([
        'organization_id' => $this->organization->id,
        'quotation_number' => 'QT-2026-8888',
        'version' => 1,
        'lead_id' => $lead->id,
        'prepared_by_id' => $this->companyEmployee->id,
        'status' => QuotationStatus::Approved,
        'subtotal' => 120000.00,
        'grand_total' => 120000.00,
    ]);

    $engine = app(CustomerProvisioningEngine::class);
    $org = $engine->provision($lead);

    expect($org->license->max_users)->toBe(15);
    expect($org->license->max_branches)->toBe(3);
    expect($org->license->plan_name)->toBe('Multi-Specialty Group License');
});

it('enforces idempotency preventing duplicate provisioning for already provisioned leads', function () {
    $lead = Lead::factory()->create(['organization_id' => null]);

    $engine = app(CustomerProvisioningEngine::class);
    $org1 = $engine->provision($lead);
    $org2 = $engine->provision($lead);

    expect($org1->id)->toBe($org2->id);
    expect(Organization::where('name', $org1->name)->count())->toBe(1);
});

it('allows ops employee to complete onboarding steps and mark go-live', function () {
    /*
     * The user's own organisation.
     *
     * Account 360's endpoint authorises with `can:update,organization`, which
     * asks whether this account is one the user may actually touch — their own
     * tenant, or a customer reachable through CrmScope. The retired CRM route
     * checked only a permission and never looked at which organisation it was
     * being pointed at.
     */
    $org = $this->organization;
    $onboarding = OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'password_setup',
        'status' => 'pending',
        'completed_steps' => [],
    ]);

    /*
     * The Customer Success endpoint. Onboarding lives on Account 360, and the
     * CRM duplicate that used to answer here has been retired.
     */
    $response = $this->actingAs($this->employeeUser)
        ->post(route('company.customer-success.accounts.onboarding.complete-step', $org), [
            'step' => 'password_setup',
            'next_step' => 'organization_profile',
        ]);

    $response->assertRedirect();

    $onboarding->refresh();
    expect($onboarding->completed_steps)->toContain('password_setup');
    expect($onboarding->current_step)->toBe('organization_profile');
});

it('computes customer success accounts payload and redirects legacy onboarding route', function () {
    $org = Organization::factory()->create();
    OnboardingProgress::create([
        'organization_id' => $org->id,
        'current_step' => 'password_setup',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($this->employeeUser)
        ->get(route('company.customer-success.accounts.index'));

    $response->assertOk();

    $this->actingAs($this->employeeUser)
        ->get(route('company.crm.onboarding.index'))
        ->assertRedirect(route('company.customer-success.accounts.index', absolute: false));
});
