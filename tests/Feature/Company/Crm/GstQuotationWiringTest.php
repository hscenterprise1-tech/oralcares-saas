<?php

use App\Models\CompanyEmployee;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2 — GST wired end-to-end through the real quotation-creation path.
 *
 * `GstCalculationServiceTest` proves the calculator itself is correct in
 * isolation; these tests prove `LeadWorkspaceService::createQuotation()`
 * actually feeds it the right seller/customer state — the provider
 * organisation's state versus the lead's state — and persists the resulting
 * breakdown onto the quotation.
 *
 * `Lead::organization_id` must reference the provider organisation once one
 * exists (see `Lead::organizationRole()` / the "deal belongs to the seller"
 * guard) — a deal belongs to the org selling it, and there is exactly one
 * seller platform-wide. So every fixture here hangs its leads off that single
 * provider org and varies only the lead's own `state` — the buyer side of
 * the calculation.
 */
beforeEach(function () {
    $this->provider = Organization::factory()->provider()->create(['state' => 'Maharashtra']);

    $this->employeeUser = User::factory()->companyUser()->create([
        'organization_id' => $this->provider->id,
    ]);

    CompanyEmployee::create([
        'organization_id' => $this->provider->id,
        'user_id' => $this->employeeUser->id,
        'employee_code' => 'EMP-GST01',
        'employment_status' => 'active',
    ]);
});

it('splits CGST and SGST when the provider and the lead share a state', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => 'Maharashtra',
    ]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [], [
        ['title' => 'Intra-state Plan', 'quantity' => 1, 'unit_price' => 100000, 'discount' => 0],
    ], $this->employeeUser);

    expect($quotation->hasGstBreakdown())->toBeTrue()
        ->and($quotation->tax_type)->toBe('cgst_sgst')
        ->and((float) $quotation->taxable_amount)->toBe(100000.0)
        ->and((float) $quotation->cgst_amount)->toBe(9000.0)
        ->and((float) $quotation->sgst_amount)->toBe(9000.0)
        ->and((float) $quotation->igst_amount)->toBe(0.0)
        ->and((float) $quotation->tax)->toBe(18000.0)
        ->and((float) $quotation->grand_total)->toBe(118000.0);
});

it('charges IGST when the provider and the lead are in different states', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => 'Karnataka',
    ]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [], [
        ['title' => 'Inter-state Plan', 'quantity' => 1, 'unit_price' => 100000, 'discount' => 0],
    ], $this->employeeUser);

    expect($quotation->hasGstBreakdown())->toBeTrue()
        ->and($quotation->tax_type)->toBe('igst')
        ->and((float) $quotation->cgst_amount)->toBe(0.0)
        ->and((float) $quotation->sgst_amount)->toBe(0.0)
        ->and((float) $quotation->igst_amount)->toBe(18000.0)
        ->and((float) $quotation->tax)->toBe(18000.0)
        ->and((float) $quotation->grand_total)->toBe(118000.0);
});

it('leaves tax_type null and reports no breakdown when the lead has no state on file', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => null,
    ]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, [], [
        ['title' => 'Unknown Buyer State Plan', 'quantity' => 1, 'unit_price' => 50000, 'discount' => 0],
    ], $this->employeeUser);

    expect($quotation->hasGstBreakdown())->toBeFalse()
        ->and($quotation->tax_type)->toBeNull()
        // The rate still applies to the total even without a resolvable split.
        ->and((float) $quotation->tax)->toBe(9000.0)
        ->and((float) $quotation->grand_total)->toBe(59000.0);
});

it('respects an explicit gst_rate override instead of the configured default', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => 'Maharashtra',
    ]);

    $quotation = app(LeadWorkspaceService::class)->createQuotation($lead, ['gst_rate' => 12], [
        ['title' => 'Reduced-rate Plan', 'quantity' => 1, 'unit_price' => 100000, 'discount' => 0],
    ], $this->employeeUser);

    expect((float) $quotation->gst_rate)->toBe(12.00)
        ->and($quotation->tax_type)->toBe('cgst_sgst')
        ->and((float) $quotation->cgst_amount)->toBe(6000.0)
        ->and((float) $quotation->sgst_amount)->toBe(6000.0)
        ->and((float) $quotation->grand_total)->toBe(112000.0);
});

/*
 * Isolation: `Organization::provider()` resolves the single platform-wide
 * seller by design, so every lead's GST math shares that one seller state —
 * that is not a leak, it is the architecture. What must not leak is one
 * lead's own buyer-side state into another lead's calculation. This creates
 * two leads with opposite intra-/inter-state outcomes side by side and checks
 * neither one's quotation math is contaminated by the other's state or by
 * calculation order.
 */
it('keeps one lead state from leaking into another lead quotation calculation', function () {
    $sameStateLead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => 'Maharashtra',
    ]);

    $differentStateLead = Lead::factory()->create([
        'organization_id' => $this->provider->id,
        'state' => 'Karnataka',
    ]);

    $service = app(LeadWorkspaceService::class);

    $intraStateQuotation = $service->createQuotation($sameStateLead, [], [
        ['title' => 'Isolated Intra-state Plan', 'quantity' => 1, 'unit_price' => 100000, 'discount' => 0],
    ], $this->employeeUser);

    $interStateQuotation = $service->createQuotation($differentStateLead, [], [
        ['title' => 'Isolated Inter-state Plan', 'quantity' => 1, 'unit_price' => 100000, 'discount' => 0],
    ], $this->employeeUser);

    expect($intraStateQuotation->tax_type)->toBe('cgst_sgst')
        ->and((float) $intraStateQuotation->igst_amount)->toBe(0.0)
        ->and($interStateQuotation->tax_type)->toBe('igst')
        ->and((float) $interStateQuotation->cgst_amount)->toBe(0.0)
        ->and((float) $interStateQuotation->sgst_amount)->toBe(0.0);
});
