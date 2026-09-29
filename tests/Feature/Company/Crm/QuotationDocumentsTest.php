<?php

use App\Models\CommercialInvoice;
use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\CompanyQuotationItem;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2 — Proforma Invoice, Tax Invoice and Receipt.
 *
 * `Lead::organization_id` must reference the provider organisation once one
 * exists (a deal belongs to the org selling it), so every fixture here hangs
 * its lead off the same provider org created in `beforeEach` — see
 * `GstQuotationWiringTest` for the same constraint.
 */
beforeEach(function () {
    $this->seller = Organization::factory()->provider()->create([
        'name' => 'OralCares Technologies Pvt Ltd',
        'state' => 'Maharashtra',
        'gst' => '27AAAAA0000A1Z5',
    ]);

    $this->rep = grantCrmPermissions(User::factory()->create([
        'user_type' => UserType::CompanyUser,
        'organization_id' => $this->seller->id,
    ]));

    $this->lead = Lead::factory()->create([
        'organization_id' => $this->seller->id,
        'company_name' => 'Apex Dental Care',
        'state' => 'Karnataka',
        'gstin' => '29BBBBB1111B2Z6',
    ]);

    $employee = CompanyEmployee::create([
        'organization_id' => $this->seller->id,
        'user_id' => $this->rep->id,
        'employee_code' => 'EMP-DOC1',
        'employment_status' => 'active',
    ]);

    $this->quotation = CompanyQuotation::create([
        'organization_id' => $this->seller->id,
        'lead_id' => $this->lead->id,
        'prepared_by_id' => $employee->id,
        'quotation_number' => 'QT-'.now()->year.'-5001',
        'version' => 1,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 100000,
        'discount' => 0,
        'taxable_amount' => 100000,
        'gst_rate' => 18,
        'tax_type' => 'igst',
        'cgst_amount' => 0,
        'sgst_amount' => 0,
        'igst_amount' => 18000,
        'tax' => 18000,
        'grand_total' => 118000,
    ]);

    CompanyQuotationItem::create([
        'company_quotation_id' => $this->quotation->id,
        'title' => 'Enterprise Practice Suite',
        'quantity' => 1,
        'unit_price' => 100000,
        'discount' => 0,
        'total' => 100000,
    ]);
});

/* ─────────────────────────────────────────── Proforma Invoice ──────────── */

it('issues a proforma invoice for an approved quotation and freezes its number', function () {
    $issued = app(LeadWorkspaceService::class)->issueProforma($this->quotation, $this->rep);

    expect($issued->proforma_number)->toBe('PI-'.now()->year.'-5001')
        ->and($issued->proforma_issued_at)->not->toBeNull();
});

it('is idempotent when a proforma is issued more than once', function () {
    $first = app(LeadWorkspaceService::class)->issueProforma($this->quotation, $this->rep);
    $firstIssuedAt = $first->proforma_issued_at;

    $second = app(LeadWorkspaceService::class)->issueProforma($first->fresh(), $this->rep);

    expect($second->proforma_number)->toBe($first->proforma_number)
        ->and($second->proforma_issued_at->equalTo($firstIssuedAt))->toBeTrue();
});

it('refuses to issue a proforma for a quotation that is not approved', function () {
    $this->quotation->update(['status' => QuotationStatus::Sent, 'approved_at' => null]);

    expect(fn () => app(LeadWorkspaceService::class)->issueProforma($this->quotation->fresh(), $this->rep))
        ->toThrow(InvalidArgumentException::class, 'can only be issued for an approved quotation');
});

it('refuses to issue a proforma once the quotation has already been settled and invoiced', function () {
    Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    expect(CommercialInvoice::where('company_quotation_id', $this->quotation->id)->exists())->toBeTrue();

    expect(fn () => app(LeadWorkspaceService::class)->issueProforma($this->quotation->fresh(), $this->rep))
        ->toThrow(InvalidArgumentException::class, 'already been settled and invoiced');
});

it('404s the proforma routes before one has been issued', function () {
    $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.proforma', $this->quotation))
        ->assertNotFound();

    $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.proforma.pdf', $this->quotation))
        ->assertNotFound();
});

it('issues and renders a proforma invoice clearly labelled as not a tax invoice', function () {
    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.proforma.issue', $this->quotation))
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.proforma', $this->quotation->fresh()));

    $response->assertSuccessful();

    $html = $response->getContent();
    expect($html)->toContain('PROFORMA INVOICE')
        ->and($html)->toContain('PI-'.now()->year.'-5001')
        ->and($html)->toContain('Apex Dental Care')
        ->and($html)->toContain('not a demand for payment')
        ->and($html)->toContain('IGST @ 18%')
        ->and($html)->toContain('118,000.00');
});

it('denies proforma issuance and viewing to a user without CRM access', function () {
    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($outsider)
        ->post(route('company.crm.quotations.proforma.issue', $this->quotation))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->get(route('company.crm.quotations.proforma', $this->quotation))
        ->assertForbidden();
});

/* ─────────────────────────────────────────── Tax Invoice ───────────────── */

it('snapshots the GST breakdown and seller/buyer GSTIN onto the tax invoice at generation time', function () {
    Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $invoice = CommercialInvoice::where('company_quotation_id', $this->quotation->id)->firstOrFail();

    expect($invoice->seller_gstin)->toBe('27AAAAA0000A1Z5')
        ->and($invoice->seller_state)->toBe('Maharashtra')
        ->and($invoice->buyer_gstin)->toBe('29BBBBB1111B2Z6')
        ->and($invoice->buyer_state)->toBe('Karnataka')
        ->and((float) $invoice->taxable_amount)->toBe(100000.0)
        ->and($invoice->tax_type)->toBe('igst')
        ->and((float) $invoice->igst_amount)->toBe(18000.0)
        ->and((float) $invoice->final_amount)->toBe(118000.0)
        ->and($invoice->invoice_number)->toBe('INV-'.now()->year.'-5001');
});

it('derives the invoice number by removing only the literal QT- prefix, not any leading Q/T/- characters', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->seller->id]);

    $quotation = CompanyQuotation::factory()->create([
        'organization_id' => $this->seller->id,
        'lead_id' => $lead->id,
        'quotation_number' => 'QT-TEST-100',
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 1000,
        'discount' => 0,
        'tax' => 0,
        'grand_total' => 1000,
    ]);

    Payment::create([
        'lead_id' => $lead->id,
        'company_quotation_id' => $quotation->id,
        'amount' => 1000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->firstOrFail();

    // A plain ltrim($number, 'QT-') would also strip the leading "T" off
    // "TEST-100" (T is in the trim charlist), producing "INV-EST-100".
    expect($invoice->invoice_number)->toBe('INV-TEST-100');
});

it('404s the tax invoice routes before the quotation is settled', function () {
    $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.tax-invoice', $this->quotation))
        ->assertNotFound();
});

it('renders the tax invoice with the correct GST breakdown once settled', function () {
    Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.tax-invoice', $this->quotation->fresh()));

    $response->assertSuccessful();

    $html = $response->getContent();
    expect($html)->toContain('TAX INVOICE')
        ->and($html)->toContain('INV-'.now()->year.'-5001')
        ->and($html)->toContain('27AAAAA0000A1Z5')
        ->and($html)->toContain('29BBBBB1111B2Z6')
        ->and($html)->toContain('IGST @ 18%')
        ->and($html)->toContain('118,000.00');
});

/* ─────────────────────────────────────────── Receipt ───────────────────── */

it('generates a receipt only for a successful payment, never a pending or failed one', function () {
    $pending = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 50000,
        'currency' => 'INR',
        'status' => 'pending',
        'gateway' => 'bank_transfer',
    ]);

    expect($pending->ledgerEntry()->first())->toBeNull();

    $failed = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 50000,
        'currency' => 'INR',
        'status' => 'failed',
        'gateway' => 'bank_transfer',
    ]);

    expect($failed->ledgerEntry()->first())->toBeNull();

    $successful = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
        'transaction_id' => 'UTR-900001',
    ]);

    $entry = $successful->ledgerEntry()->first();
    expect($entry)->not->toBeNull()
        ->and($entry->receipt_number)->toStartWith('RCPT-'.now()->year.'-');
});

it('404s the receipt route for a payment with no successful ledger entry', function () {
    $pending = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 50000,
        'currency' => 'INR',
        'status' => 'pending',
        'gateway' => 'bank_transfer',
    ]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.payments.receipt', [$this->quotation, $pending]))
        ->assertNotFound();
});

it('renders the receipt for a successful payment with amount, method and reference', function () {
    $payment = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
        'transaction_id' => 'UTR-900002',
    ]);

    $response = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.payments.receipt', [$this->quotation->fresh(), $payment]));

    $response->assertSuccessful();

    $html = $response->getContent();
    expect($html)->toContain('PAYMENT RECEIPT')
        ->and($html)->toContain('118,000.00')
        ->and($html)->toContain('Bank Transfer')
        ->and($html)->toContain('UTR-900002')
        ->and($html)->toContain($payment->ledgerEntry()->first()->receipt_number);
});

it('404s a receipt requested through a quotation it does not belong to', function () {
    $otherQuotation = CompanyQuotation::factory()->create([
        'organization_id' => $this->seller->id,
        'lead_id' => $this->lead->id,
        'status' => QuotationStatus::Approved,
    ]);

    $payment = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.payments.receipt', [$otherQuotation, $payment]))
        ->assertNotFound();
});

it('denies the receipt to a user without CRM access', function () {
    $payment = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $outsider = User::factory()->create(['user_type' => UserType::CompanyUser]);

    $this->actingAs($outsider)
        ->get(route('company.crm.quotations.payments.receipt', [$this->quotation, $payment]))
        ->assertForbidden();
});

it('serves the proforma, tax invoice and receipt as real PDFs', function () {
    app(LeadWorkspaceService::class)->issueProforma($this->quotation, $this->rep);

    $proformaPdf = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.proforma.pdf', $this->quotation->fresh()));
    $proformaPdf->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
    expect($proformaPdf->getContent())->toStartWith('%PDF');

    $payment = Payment::create([
        'lead_id' => $this->quotation->lead_id,
        'company_quotation_id' => $this->quotation->id,
        'amount' => 118000,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $invoicePdf = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.tax-invoice.pdf', $this->quotation->fresh()));
    $invoicePdf->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
    expect($invoicePdf->getContent())->toStartWith('%PDF');

    $receiptPdf = $this->actingAs($this->rep)
        ->get(route('company.crm.quotations.payments.receipt.pdf', [$this->quotation->fresh(), $payment]));
    $receiptPdf->assertSuccessful()->assertHeader('Content-Type', 'application/pdf');
    expect($receiptPdf->getContent())->toStartWith('%PDF');
});
