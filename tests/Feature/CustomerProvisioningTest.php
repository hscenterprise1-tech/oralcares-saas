<?php

use App\Events\CustomerProvisioned;
use App\LeadStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\Lead;
use App\Models\License;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerProvisioningEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Resolved from the container: the engine now depends on
    // BranchProvisioningService and AssignPrimaryOwnerService, because
    // provisioning has to build the Organization → Clinic → Branch hierarchy
    // rather than an organization and a floating branch.
    $this->engine = app(CustomerProvisioningEngine::class);
});

it('provisions a customer successfully and dispatches event', function () {
    Event::fake();

    $lead = Lead::factory()->create([
        'company_name' => 'Stark Industries',
        'contact_name' => 'Tony Stark',
        'email' => 'tony@stark.com',
    ]);

    // Setup accepted agreement
    CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'accepted',
        'commercial_snapshot' => [
            'plan_name' => 'Enterprise',
            'max_users' => 100,
            'max_branches' => 5,
        ],
    ]);

    // Setup successful payment
    $invoice = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'invoice',
        'status' => 'paid',
    ]);
    Payment::factory()->create([
        'lead_id' => $lead->id,
        'commercial_document_id' => $invoice->id,
        'status' => 'successful',
    ]);

    $org = $this->engine->provision($lead, 'Global HQ');

    // Assert Organization Created
    expect($org)->toBeInstanceOf(Organization::class)
        ->name->toBe('Stark Industries')
        ->email->toBe('tony@stark.com');

    // Assert Subscription.
    //
    // Entitlements come from the purchased CompanyPlan, falling back to the
    // quotation's grand total. They were never read from an agreement's
    // `commercial_snapshot`, which is what this test used to assert — see
    // ProvisioningPlanLinkTest for the plan-linked path.
    $this->assertDatabaseHas('company_subscriptions', [
        'organization_id' => $org->id,
        'status' => 'active',
    ]);

    // Assert License
    $this->assertDatabaseHas('licenses', [
        'organization_id' => $org->id,
        'status' => 'active',
    ]);

    // Assert Branch — owned by the clinic, scoped to the organization.
    $this->assertDatabaseHas('branches', [
        'organization_id' => $org->id,
        'name' => 'Global HQ',
        'is_main' => true,
    ]);

    $branch = Branch::withoutOrganizationScope()
        ->where('organization_id', $org->id)
        ->firstOrFail();

    expect($branch->clinic_id)->not->toBeNull()
        ->and($branch->clinic->organization_id)->toBe($org->id);

    // Assert User
    $this->assertDatabaseHas('users', [
        'organization_id' => $org->id,
        'email' => 'tony@stark.com',
    ]);

    // Assert Settings. The engine seeds Asia/Kolkata; `value` is cast to array
    // on the model, so it lands JSON-encoded.
    $this->assertDatabaseHas('organization_settings', [
        'organization_id' => $org->id,
        'key' => 'timezone',
        'value' => json_encode('Asia/Kolkata'),
    ]);

    // Assert Event Dispatched
    Event::assertDispatched(CustomerProvisioned::class, function ($event) use ($org) {
        return $event->organization->id === $org->id && $event->adminUser->email === 'tony@stark.com';
    });
});

it('prevents double provisioning', function () {
    // Provisioning is idempotent rather than throwing: the Won transition is
    // the single canonical entry point and may legitimately be re-entered
    // (retry, replayed job, repaired legacy record). Re-running returns the
    // customer already provisioned and creates nothing new.
    $lead = Lead::factory()->create(['status' => LeadStatus::Won]);

    $first = $this->engine->provision($lead);
    $second = $this->engine->provision($lead->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Organization::where('name', $lead->company_name)->count())->toBe(1)
        ->and(Clinic::where('organization_id', $first->id)->count())->toBe(1);
});

it('does not enforce the commercial gate inside the engine', function () {
    // The agreement/payment gate lives on LeadController@convert, which is the
    // guarded entry point. The engine itself is the mechanism and must stay
    // callable by the pipeline path, which has no documents attached.
    $lead = Lead::factory()->create();

    $org = $this->engine->provision($lead);

    expect($org)->toBeInstanceOf(Organization::class)
        ->and($lead->fresh()->customer_organization_id)->toBe($org->id);
});
