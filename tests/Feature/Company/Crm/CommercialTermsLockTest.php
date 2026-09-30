<?php

use App\Models\CommercialInvoice;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\CommercialPageService;
use App\Services\LeadWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Commercial terms lock once a quotation is settled and invoiced.
 *
 * The invoice is a document the customer holds. Repricing behind it would mean
 * their paper and our record no longer agree — so at that point the money on a
 * quotation stops being editable, by any route.
 *
 * The way to charge something different is a revision: a new record with its
 * own number, items, totals and approval, leaving the settled one and its
 * invoice untouched. That escape hatch has to keep working, and these tests
 * check that it does.
 *
 * Found by the CC2 browser round-trip: discounting an already-settled quotation
 * sent it back to `sent` and left its invoice detached behind it.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->rep = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'view-leads', 'create leads', 'edit own leads', 'manage-quotations'],
    );

    $this->finance = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'record-payment'],
    );
});

/** An approved quotation, settled in full, with its invoice raised. */
function settledAndInvoiced(array $attributes = [], ?Lead $lead = null): CompanyQuotation
{
    $lead ??= Lead::factory()->create();

    $quotation = CompanyQuotation::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000,
        'tax' => 1800,
        'discount' => 0,
        'grand_total' => 11800,
    ], $attributes));

    Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => 11800,
        'status' => 'successful',
    ]);

    return $quotation->fresh();
}

it('locks a quotation once it is settled and invoiced', function () {
    $quotation = settledAndInvoiced();

    expect($quotation->isFinanciallyLocked())->toBeTrue()
        ->and(CommercialInvoice::where('company_quotation_id', $quotation->id)->exists())->toBeTrue();
});

it('leaves an unpaid quotation editable', function () {
    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000,
        'tax' => 1800,
        'grand_total' => 11800,
    ]);

    expect($quotation->isFinanciallyLocked())->toBeFalse();
});

it('leaves a part paid quotation editable', function () {
    /*
     * Only settlement locks. Until then the price is still being agreed, and a
     * reduction that puts it below what has arrived simply reconciles as
     * overpaid — visible, not hidden.
     */
    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000,
        'tax' => 1800,
        'grand_total' => 11800,
    ]);

    Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => 5000,
        'status' => 'successful',
    ]);

    expect($quotation->fresh()->isFinanciallyLocked())->toBeFalse();
});

/* ─────────────────────────────────────── the discount route ───────────── */

it('refuses a discount on a settled and invoiced quotation', function () {
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 2000])
        ->assertSessionHasErrors('discount');

    $quotation->refresh();

    expect((float) $quotation->discount)->toBe(0.0)
        ->and((float) $quotation->grand_total)->toBe(11800.0)
        ->and($quotation->status)->toBe(QuotationStatus::Approved)
        ->and($quotation->approved_at)->not->toBeNull();
});

it('refuses a discount at the service, not only at the endpoint', function () {
    $quotation = settledAndInvoiced();

    expect(fn () => app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 2000))
        ->toThrow(InvalidArgumentException::class, 'settled and invoiced');
});

it('does not revoke the approval when a locked discount is refused', function () {
    /*
     * The defect this whole lock exists for: the refused edit used to go
     * through, take the quotation back to `sent`, and leave the invoice
     * pointing at a record that was no longer approved.
     */
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);
    $invoiceId = CommercialInvoice::where('company_quotation_id', $quotation->id)->value('id');

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 9000]);

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Approved)
        ->and(CommercialInvoice::find($invoiceId))->not->toBeNull()
        ->and((float) CommercialInvoice::find($invoiceId)->final_amount)->toBe(11800.0);
});

/* ────────────────────────────────────── the line-item route ───────────── */

it('refuses a line-item rewrite on a settled and invoiced quotation', function () {
    /* The discount is the obvious door; the lines are the other one. */
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.update', $quotation), [
            'items' => [['title' => 'Cheaper', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0]],
        ])
        ->assertSessionHasErrors('items');

    expect((float) $quotation->fresh()->grand_total)->toBe(11800.0)
        ->and($quotation->fresh()->items()->count())->toBe(0);
});

it('refuses a line-item rewrite at the service too', function () {
    $quotation = settledAndInvoiced();

    expect(fn () => app(LeadWorkspaceService::class)->saveItemsAndCalculateTotals(
        $quotation,
        [['title' => 'Cheaper', 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0]],
        0.0,
    ))->toThrow(InvalidArgumentException::class, 'settled and invoiced');
});

it('still allows notes and terms on a locked quotation', function () {
    /* The money is fixed. What somebody wrote about it is not money. */
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.update', $quotation), [
            'notes' => 'Customer confirmed receipt of the invoice.',
        ])
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->notes)->toBe('Customer confirmed receipt of the invoice.');
});

/* ───────────────────────────────── revision is the way through ────────── */

it('lets a locked quotation be revised into a new one', function () {
    /*
     * The escape hatch, and the reason the lock is not a dead end: a new
     * version carries its own items, totals and approval.
     */
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.revise', $quotation), [
            'items' => [['title' => 'Revised scope', 'quantity' => 1, 'unit_price' => 8000, 'discount' => 0]],
        ])
        ->assertSessionHasNoErrors();

    $revised = CompanyQuotation::where('parent_id', $quotation->id)->firstOrFail();

    expect($revised->id)->not->toBe($quotation->id)
        ->and($revised->version)->toBeGreaterThan((int) $quotation->version)
        ->and($revised->status)->toBe(QuotationStatus::Draft)
        /*
         * Phase 2 — GST replaces the old flat-tax carryover. The parent here
         * has no explicit gst_rate, so the revision falls back to the default
         * 18% rate on its own taxable amount: 8000 * 1.18 = 9440.
         */
        ->and((float) $revised->grand_total)->toBe(9440.0)
        ->and($revised->isFinanciallyLocked())->toBeFalse();
});

it('leaves the settled quotation and its invoice untouched by a revision', function () {
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);
    $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->firstOrFail();

    $this->actingAs($this->rep)->post(route('company.crm.quotations.revise', $quotation), [
        'items' => [['title' => 'Revised scope', 'quantity' => 1, 'unit_price' => 8000, 'discount' => 0]],
    ]);

    $quotation->refresh();
    $invoice->refresh();

    expect((float) $quotation->grand_total)->toBe(11800.0)
        ->and($quotation->status)->toBe(QuotationStatus::Approved)
        ->and($quotation->payments()->count())->toBe(1)
        ->and((float) $invoice->final_amount)->toBe(11800.0)
        ->and($invoice->status)->toBe('paid');
});

it('lets the new revision be discounted freely', function () {
    $quotation = settledAndInvoiced(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)->post(route('company.crm.quotations.revise', $quotation), [
        'items' => [['title' => 'Revised scope', 'quantity' => 1, 'unit_price' => 8000, 'discount' => 0]],
    ]);

    $revised = CompanyQuotation::where('parent_id', $quotation->id)->firstOrFail();

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $revised), ['discount_type' => 'fixed', 'discount_value' => 1000])
        ->assertSessionHasNoErrors();

    // Taxable 8000 - 1000 = 7000; 18% GST = 1260; grand total = 8260.
    expect((float) $revised->fresh()->discount)->toBe(1000.0)
        ->and((float) $revised->fresh()->grand_total)->toBe(8260.0);
});

/* ─────────────────────────────────────────── the payload ──────────────── */

it('tells the client when the terms are locked', function () {
    $locked = settledAndInvoiced(['organization_id' => $this->organization->id]);

    $open = CompanyQuotation::factory()->create([
        'organization_id' => $this->organization->id,
        'lead_id' => Lead::factory()->create()->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000,
        'tax' => 1800,
        'grand_total' => 11800,
    ]);

    $page = app(CommercialPageService::class);

    expect($page->detailPayload($locked, $this->rep)['quotation']['financiallyLocked'])->toBeTrue()
        ->and($page->detailPayload($open, $this->rep)['quotation']['financiallyLocked'])->toBeFalse();
});
