<?php

use App\Models\CommercialInvoice;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Services\CRM\CommercialPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Commercials CC2 — payment, and the invoice that follows it.
 *
 * The flow is Quotation → Approval → Payment → Invoice → Won. Money is only
 * recorded against an approved quotation, and the invoice is a consequence of
 * settlement rather than a step somebody performs: a part payment produces no
 * paper, and an overpayment produces none either until a person has looked at
 * it.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    /** Prices the deal. Not the authority to say it was paid. */
    $this->rep = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'view-leads', 'create leads', 'edit own leads', 'manage-quotations'],
    );

    /** States that money arrived. Cannot price or approve. */
    $this->finance = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'record-payment'],
    );
});

function payableQuotation(array $attributes = [], ?Lead $lead = null): CompanyQuotation
{
    $lead ??= Lead::factory()->create();

    return CompanyQuotation::factory()->create(array_merge([
        'lead_id' => $lead->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000,
        'tax' => 1800,
        'discount' => 0,
        'grand_total' => 11800,
    ], $attributes));
}

/* ───────────────────────────────────── who may record money ───────────── */

it('records money against an approved quotation', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->finance)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 11800,
            'gateway' => 'bank_transfer',
            'transaction_id' => 'UTR-001',
            'status' => 'successful',
        ])
        ->assertSessionHasNoErrors();

    expect($quotation->payments()->count())->toBe(1)
        ->and((float) $quotation->payments()->first()->amount)->toBe(11800.0);
});

it('refuses money against a quotation that is not approved', function () {
    /*
     * A proposal nobody has signed off is not something a customer owes, so
     * booking money against it would put revenue in the ledger for terms that
     * were never agreed.
     */
    $quotation = payableQuotation([
        'organization_id' => $this->organization->id,
        'status' => QuotationStatus::Sent,
        'approved_at' => null,
    ]);

    $this->actingAs($this->finance)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 11800, 'gateway' => 'bank_transfer', 'status' => 'successful',
        ])
        ->assertSessionHasErrors('payment');

    expect($quotation->payments()->count())->toBe(0);
});

it('refuses money from someone without the record-payment permission', function () {
    /* The rep priced it; that is not the authority to declare it settled. */
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 11800, 'gateway' => 'bank_transfer', 'status' => 'successful',
        ])
        ->assertForbidden();
});

it('refuses money across a tenant boundary', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $outsider = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => Organization::factory()->create()->id]),
        ['view-crm', 'view-quotations', 'record-payment'],
    );

    $this->actingAs($outsider)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 11800, 'gateway' => 'bank_transfer', 'status' => 'successful',
        ])
        ->assertForbidden();
});

/* ─────────────────────────────── partial, exact, over ─────────────────── */

it('raises no invoice on a partial payment, and one on settlement', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);
    $ledger = app(CommercialLedgerService::class);

    $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $quotation), [
        'amount' => 5000, 'gateway' => 'upi', 'status' => 'successful',
    ])->assertSessionHasNoErrors();

    expect(CommercialInvoice::count())->toBe(0)
        ->and($ledger->reconcile($quotation->fresh())['state'])->toBe(CommercialLedgerService::PARTIALLY_PAID);

    $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $quotation), [
        'amount' => 6800, 'gateway' => 'upi', 'status' => 'successful',
    ])->assertSessionHasNoErrors();

    expect(CommercialInvoice::where('company_quotation_id', $quotation->id)->count())->toBe(1)
        ->and($ledger->reconcile($quotation->fresh())['state'])->toBe(CommercialLedgerService::PAID);
});

it('aggregates several payments rather than reading only the last', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    foreach ([3000, 4000, 4800] as $amount) {
        $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => $amount, 'gateway' => 'upi', 'status' => 'successful',
        ]);
    }

    $reconciliation = app(CommercialLedgerService::class)->reconcile($quotation->fresh());

    expect($reconciliation['settled'])->toBe(11800.0)
        ->and($reconciliation['payments'])->toBe(3)
        ->and($reconciliation['state'])->toBe(CommercialLedgerService::PAID);
});

it('raises no invoice on an overpayment and flags it instead', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->finance)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 20000, 'gateway' => 'bank_transfer', 'status' => 'successful',
        ])
        ->assertSessionHasNoErrors();

    expect(CommercialInvoice::count())->toBe(0)
        ->and(app(CommercialLedgerService::class)->reconcile($quotation->fresh())['state'])
        ->toBe(CommercialLedgerService::OVERPAID);
});

it('raises no invoice for a quotation whose own totals do not add up', function () {
    $broken = payableQuotation([
        'organization_id' => $this->organization->id,
        'subtotal' => 10000, 'tax' => 1800, 'discount' => 0, 'grand_total' => 99999,
    ]);

    $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $broken), [
        'amount' => 99999, 'gateway' => 'bank_transfer', 'status' => 'successful',
    ]);

    expect(app(CommercialLedgerService::class)->reconcile($broken->fresh())['state'])
        ->toBe(CommercialLedgerService::MISMATCH)
        ->and(CommercialInvoice::count())->toBe(0);
});

it('records a pending payment without letting it settle anything', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->finance)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 11800, 'gateway' => 'card', 'status' => 'pending',
        ])
        ->assertSessionHasNoErrors();

    expect($quotation->payments()->count())->toBe(1)
        ->and(app(CommercialLedgerService::class)->reconcile($quotation->fresh())['state'])
        ->toBe(CommercialLedgerService::UNPAID)
        ->and(CommercialInvoice::count())->toBe(0);
});

/* ──────────────────────────────────────── money isolation ─────────────── */

it('gives each settled quotation on one deal its own invoice', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $first = payableQuotation(['organization_id' => $this->organization->id], $lead);
    $second = payableQuotation(['organization_id' => $this->organization->id], $lead);

    foreach ([$first, $second] as $quotation) {
        $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 11800, 'gateway' => 'bank_transfer', 'status' => 'successful',
        ])->assertSessionHasNoErrors();
    }

    expect(CommercialInvoice::where('company_quotation_id', $first->id)->count())->toBe(1)
        ->and(CommercialInvoice::where('company_quotation_id', $second->id)->count())->toBe(1)
        ->and(CommercialInvoice::count())->toBe(2)
        ->and($lead->fresh()->hasApprovedPaidQuotation())->toBeTrue();
});

it('keeps two quotations money entirely apart', function () {
    $mine = payableQuotation(['organization_id' => $this->organization->id]);
    $theirs = payableQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $mine), [
        'amount' => 11800, 'gateway' => 'bank_transfer', 'status' => 'successful',
    ]);

    $ledger = app(CommercialLedgerService::class);

    expect($ledger->reconcile($mine->fresh())['state'])->toBe(CommercialLedgerService::PAID)
        ->and($ledger->reconcile($theirs->fresh())['state'])->toBe(CommercialLedgerService::UNPAID)
        ->and($theirs->payments()->count())->toBe(0);
});

/* ────────────────────────────────────────── the payload ───────────────── */

it('tells the payload who may record money', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);
    $page = app(CommercialPageService::class);

    expect($page->detailPayload($quotation, $this->rep)['can']['recordPayment'])->toBeFalse()
        ->and($page->detailPayload($quotation, $this->finance)['can']['recordPayment'])->toBeTrue();
});

it('reports a settled quotation with its invoice number', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->finance)->post(route('company.crm.quotations.payments.store', $quotation), [
        'amount' => 11800, 'gateway' => 'bank_transfer', 'status' => 'successful',
    ]);

    $payload = app(CommercialPageService::class)->detailPayload($quotation->fresh(), $this->rep);

    expect($payload['quotation']['settlement']['status'])->toBe('paid')
        ->and($payload['quotation']['settlement']['number'])->not->toBeNull()
        ->and($payload['payments'])->toHaveCount(1);
});

it('shows an approved but unpaid quotation as awaiting money, with no invoice number', function () {
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);

    $settlement = app(CommercialPageService::class)
        ->detailPayload($quotation, $this->rep)['quotation']['settlement'];

    expect($settlement)->not->toBeNull()
        ->and($settlement['status'])->toBe('not issued')
        ->and($settlement['number'])->toBeNull()
        ->and($settlement['reconciliation']['state'])->toBe(CommercialLedgerService::UNPAID);
});

it('gives an unapproved quotation no settlement at all', function () {
    /* Money owed begins at approval, not before. */
    $quotation = payableQuotation([
        'organization_id' => $this->organization->id,
        'status' => QuotationStatus::Draft,
        'approved_at' => null,
    ]);

    expect(app(CommercialPageService::class)->detailPayload($quotation, $this->rep)['quotation']['settlement'])
        ->toBeNull();
});

/* ────────────────────────────── no agreement anywhere ─────────────────── */

it('mentions no agreement in the commercial payload', function () {
    /*
     * The correction in one assertion: there is no agreement step, so nothing
     * the module hands the client may name one.
     */
    $quotation = payableQuotation(['organization_id' => $this->organization->id]);
    $page = app(CommercialPageService::class);

    $detail = json_encode($page->detailPayload($quotation, $this->rep));
    $list = json_encode($page->listPayload($this->rep));

    expect(strtolower($detail))->not->toContain('agreement')
        ->and(strtolower($list))->not->toContain('agreement');
});
