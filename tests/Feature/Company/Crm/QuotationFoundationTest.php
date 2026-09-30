<?php

use App\LeadStatus;
use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\QuotationStatus;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->adminUser = User::factory()->masterAdmin()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->employeeUser = User::factory()->companyUser()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->companyEmployee = CompanyEmployee::create([
        'organization_id' => $this->organization->id,
        'user_id' => $this->employeeUser->id,
        'employee_code' => 'EMP-TEST01',
        'employment_status' => 'active',
    ]);
});

it('can create a draft quotation with line items and calculate authoritative server-side totals', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $service = app(LeadWorkspaceService::class);

    $items = [
        [
            'title' => 'Dental HMS License',
            'quantity' => 2,
            'unit_price' => 20000.00,
            'discount' => 2000.00,
        ],
        [
            'title' => 'WhatsApp Integration Module',
            'quantity' => 1,
            'unit_price' => 10000.00,
            'discount' => 1000.00,
        ],
    ];

    $quotation = $service->createQuotation($lead, [
        'valid_until' => now()->addDays(30)->toDateString(),
        'notes' => 'Custom multi-specialty package',
    ], $items, $this->employeeUser);

    expect($quotation)->not->toBeNull();
    expect($quotation->quotation_number)->toStartWith('QT-');
    expect($quotation->version)->toBe(1);
    expect($quotation->status->value)->toBe('draft');

    /*
     * Authoritative server-side calculation, GST included (Phase 2):
     * Subtotal = (2*20000)+(1*10000) = 50000. Discount = 2000+1000 = 3000.
     * Taxable = 47000. No provider organisation and no lead state exist in
     * this fixture, so `GstCalculationService` cannot determine an
     * intra-/inter-state split — `tax_type` stays null — but the 18%
     * default rate still applies to the total: 47000 * 0.18 = 8460.
     * Grand total = 47000 + 8460 = 55460.
     */
    expect((float) $quotation->subtotal)->toBe(50000.00);
    expect((float) $quotation->discount)->toBe(3000.00);
    expect((float) $quotation->taxable_amount)->toBe(47000.00);
    expect((float) $quotation->gst_rate)->toBe(18.00);
    expect($quotation->tax_type)->toBeNull();
    expect((float) $quotation->tax)->toBe(8460.00);
    expect((float) $quotation->grand_total)->toBe(55460.00);

    expect($quotation->items)->toHaveCount(2);

    $lead->refresh();
    $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
    expect($statusVal)->toBe(LeadStatus::Negotiation->value);
});

it('creates a revised version v2 preserving v1 history immutably and linking parent_id', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $service = app(LeadWorkspaceService::class);
    $q1 = $service->createQuotation($lead, [], [
        ['title' => 'Initial Plan', 'quantity' => 1, 'unit_price' => 30000, 'discount' => 0],
    ], $this->employeeUser);

    $service->sendQuotation($q1, [], $this->employeeUser);

    $revisedItems = [
        ['title' => 'Initial Plan Revised', 'quantity' => 1, 'unit_price' => 25000, 'discount' => 1000],
    ];

    $q2 = $service->reviseQuotation($q1, $revisedItems, ['notes' => 'Negotiated pricing'], $this->employeeUser);

    expect($q2->id)->not->toBe($q1->id);
    expect($q2->quotation_number)->toBe($q1->quotation_number);
    expect($q2->version)->toBe(2);
    expect($q2->parent_id)->toBe($q1->id);
    expect($q2->status->value)->toBe('draft');
    // Taxable 25000 - 1000 = 24000; 18% GST = 4320; grand total = 28320.
    expect((float) $q2->grand_total)->toBe(28320.00);

    // Verify v1 proposal remains intact in database — its items and amounts
    // are untouched. Its status moves to `superseded`: a deal has at most one
    // active quotation, and v2 replacing it is exactly what retires v1.
    $q1->refresh();
    expect($q1->version)->toBe(1);
    expect($q1->status->value)->toBe('superseded');
    // Taxable 30000; 18% GST = 5400; grand total = 35400.
    expect((float) $q1->grand_total)->toBe(35400.00);
});

it('sends proposal advancing lead stage to QuotationSent and creating follow up task', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);

    $service = app(LeadWorkspaceService::class);
    $quotation = $service->createQuotation($lead, [], [
        ['title' => 'Core Suite', 'quantity' => 1, 'unit_price' => 15000, 'discount' => 0],
    ], $this->employeeUser);

    $result = $service->sendQuotation($quotation, [], $this->employeeUser);

    expect($result['success'])->toBeTrue();
    expect($result['whatsapp_status'])->toBe('blocked_template_not_approved');

    $quotation->refresh();
    expect($quotation->status->value)->toBe('sent');

    $lead->refresh();
    $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
    expect($statusVal)->toBe(LeadStatus::QuotationSent->value);

    // Follow-up task created
    $this->assertDatabaseHas('tasks', [
        'taskable_id' => $lead->id,
        'assigned_user_id' => $this->employeeUser->id,
    ]);
});

it('approves quotation in CRM console advancing lead stage to QuotationApproved', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'status' => LeadStatus::QuotationSent->value,
    ]);

    $service = app(LeadWorkspaceService::class);

    // 2,000 on 60,000 is 3.33%, deliberately below the 5% management-approval
    // threshold. This test is about a *client* approving a sent quotation and the
    // lead stage advancing — not about the discount gate, which has its own tests.
    // The fixture discounted 8.33%, which was fine while the threshold sat at 20%
    // and started failing the moment it moved to 5%.
    $quotation = $service->createQuotation($lead, [], [
        ['title' => 'Enterprise Suite', 'quantity' => 1, 'unit_price' => 60000, 'discount' => 2000],
    ], $this->employeeUser);
    $service->sendQuotation($quotation, [], $this->employeeUser);

    $approved = $service->approveQuotation($quotation, $this->employeeUser, 'Client confirmed via phone');

    expect($approved->status->value)->toBe('approved');

    $lead->refresh();
    $statusVal = is_object($lead->status) && isset($lead->status->value) ? $lead->status->value : (string) $lead->status;
    expect($statusVal)->toBe(LeadStatus::QuotationApproved->value);
});

it('rejects quotation and records rejection reason', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $service = app(LeadWorkspaceService::class);
    $quotation = $service->createQuotation($lead, [], [
        ['title' => 'Standard Package', 'quantity' => 1, 'unit_price' => 20000, 'discount' => 0],
    ], $this->employeeUser);

    $rejected = $service->rejectQuotation($quotation, 'Budget constraints', $this->employeeUser);

    expect($rejected->status->value)->toBe('rejected');
    expect($rejected->rejection_reason)->toBe('Budget constraints');
});

it('enforces organization tenant isolation preventing cross tenant proposal access', function () {
    $otherOrg = Organization::factory()->create();
    $otherLead = Lead::factory()->create(['organization_id' => $otherOrg->id]);

    $otherQuotation = CompanyQuotation::create([
        'organization_id' => $otherOrg->id,
        'quotation_number' => 'QT-2026-9999',
        'version' => 1,
        'lead_id' => $otherLead->id,
        'prepared_by_id' => $this->companyEmployee->id,
        'status' => QuotationStatus::Draft,
        'subtotal' => 10000,
        'grand_total' => 10000,
    ]);

    $response = $this->actingAs($this->employeeUser)
        ->get(route('company.crm.quotations.show', $otherQuotation));

    $response->assertStatus(403);
});

it('computes quotation workspace payload metrics correctly', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
    $service = app(LeadWorkspaceService::class);

    $service->createQuotation($lead, [], [
        ['title' => 'Package A', 'quantity' => 1, 'unit_price' => 50000, 'discount' => 0],
    ], $this->employeeUser);

    $payload = $service->getQuotationWorkspacePayload([], $this->employeeUser);

    expect($payload['metrics']['total_proposals'])->toBeGreaterThanOrEqual(1);
    expect($payload['quotations'])->not->toBeEmpty();
});
