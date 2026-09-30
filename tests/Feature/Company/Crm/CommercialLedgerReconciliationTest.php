<?php

use App\LeadStatus;
use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Services\FinanceWorkspaceService;
use App\Services\LeadWorkspaceService;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * One financial ledger.
 *
 * Phase 0 found the same commercial event represented twice with no foreign key
 * between the two chains, and made Finance the ledger. That still holds; what
 * has changed since is which CRM record the money hangs off.
 *
 * The commercial flow is Quotation → Approval → Payment → Invoice → Won. There
 * is no agreement step, and no `type=invoice` document is ever created — so the
 * projection now runs from a settled quotation. These tests were written
 * against the document chain and have been re-pointed; every one of them still
 * checks the same property.
 */
/**
 * An approved quotation, settled in full — the state that raises an invoice.
 */
function settledQuotation(?Lead $lead = null, array $attributes = []): CompanyQuotation
{
    $lead ??= Lead::factory()->create();

    $quotation = CompanyQuotation::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 100000,
        'tax' => 18000,
        'discount' => 0,
        'grand_total' => 118000,
    ], $attributes));

    Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => 118000,
        'status' => 'successful',
    ]);

    return $quotation->fresh();
}

/** The legacy shape, kept so the historical rows stay covered. */
function legacyAgreementDocument(?Lead $lead = null, array $attributes = []): CommercialDocument
{
    $lead ??= Lead::factory()->create();

    return CommercialDocument::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'paid',
        'subtotal' => 100000,
        'tax_amount' => 18000,
        'discount_amount' => 0,
        'grand_total' => 118000,
    ], $attributes));
}

it('projects a settled quotation into the finance ledger', function () {
    $quotation = settledQuotation();

    $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->first();

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->final_amount)->toBe(118000.0)
        ->and($invoice->status)->toBe('paid');
});

it('does not book an unpaid quotation as revenue', function () {
    /* Money owed is not money received. */
    CompanyQuotation::factory()->create([
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 100000,
        'tax' => 18000,
        'grand_total' => 118000,
    ]);

    expect(CommercialInvoice::count())->toBe(0);
});

it('books neither a quotation awaiting approval nor a legacy agreement', function () {
    /*
     * The half that was always right: a proposal is not money owed. And the
     * half that changed — a legacy agreement is no longer projected either,
     * because invoices follow settled quotations now. The historical rows that
     * already exist are untouched.
     */
    $lead = Lead::factory()->create();

    CompanyQuotation::factory()->create(['lead_id' => $lead->id, 'status' => QuotationStatus::Sent, 'grand_total' => 118000]);
    legacyAgreementDocument($lead);

    expect(CommercialInvoice::count())->toBe(0);
});

it('keeps the ledger in step as money arrives against a quotation', function () {
    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 100000,
        'tax' => 18000,
        'discount' => 0,
        'grand_total' => 118000,
    ]);

    Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => 50000,
        'status' => 'successful',
    ]);

    /* Part paid raises no invoice. */
    expect(CommercialInvoice::count())->toBe(0);

    Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => 68000,
        'status' => 'successful',
    ]);

    $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->paid_at)->not->toBeNull()
        ->and(CommercialInvoice::count())->toBe(1);
});

it('ignores pending payments and books them when they settle', function () {
    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 100000,
        'tax' => 18000,
        'discount' => 0,
        'grand_total' => 118000,
    ]);

    $payment = Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => 118000,
        'status' => 'pending',
    ]);

    expect(CommercialPayment::count())->toBe(0);

    $payment->update(['status' => 'successful']);

    expect(CommercialPayment::count())->toBe(1)
        ->and(CommercialInvoice::count())->toBe(1);
});

it('never double-counts revenue when a payment is saved repeatedly', function () {
    $quotation = settledQuotation();
    $payment = $quotation->payments()->first();

    app(CommercialLedgerService::class)->recordPayment($payment);
    app(CommercialLedgerService::class)->recordPayment($payment->fresh());
    $payment->update(['transaction_id' => 'retry-ref']);

    expect(CommercialPayment::where('payment_id', $payment->id)->count())->toBe(1)
        ->and((float) CommercialPayment::sum('amount'))->toBe(118000.0);
});

it('surfaces CRM-originated money on the finance workspace', function () {
    settledQuotation();

    $finance = app(FinanceWorkspaceService::class);

    expect((float) $finance->getInvoicesPayload()['kpi']['total_invoiced'])->toBe(118000.0)
        ->and((float) $finance->getPaymentsPayload()['kpi']['total_collected'])->toBe(118000.0);
});

it('attributes pre-sale money to the tenant once the deal is provisioned', function () {
    $actor = User::factory()->create(['user_type' => UserType::MasterAdmin]);

    /* Quoted and paid while still a lead — the normal commercial sequence. */
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Negotiation,
        'company_name' => 'Harbour Dental',
        'email' => 'ops@harbour.test',
    ]);

    $quotation = settledQuotation($lead);

    /* The tenant does not exist yet, so the ledger rows carry no clinic. */
    expect(CommercialInvoice::where('company_quotation_id', $quotation->id)->value('clinic_id'))->toBeNull();

    app(LeadWorkspaceService::class)->transitionStage($lead, LeadStatus::Won->value, $actor, 'test');

    $clinic = Clinic::where('organization_id', $lead->fresh()->customer_organization_id)->firstOrFail();

    expect(CommercialInvoice::where('company_quotation_id', $quotation->id)->value('clinic_id'))->toBe($clinic->id)
        ->and(CommercialPayment::whereNotNull('payment_id')->value('clinic_id'))->toBe($clinic->id);
});

it('exposes the ledger link from both sides', function () {
    $quotation = settledQuotation();
    $payment = $quotation->payments()->first();

    expect($quotation->fresh()->invoice)->toBeInstanceOf(CommercialInvoice::class)
        ->and($payment->fresh()->ledgerEntry)->toBeInstanceOf(CommercialPayment::class)
        ->and($payment->fresh()->quotation->id)->toBe($quotation->id);
});
