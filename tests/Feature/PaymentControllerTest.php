<?php

use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Where money is recorded, after CC3.
 *
 * It used to be recorded against a commercial document, and only against one
 * of type `invoice` — a document type the CRM lifecycle never produced. So the
 * only way to book a payment was to hand-draw paperwork the flow did not have,
 * and money recorded any other way reconciled against nothing.
 *
 * Money is now recorded against the approved quotation it settles. The invoice
 * follows the money rather than preceding it.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();

    $this->finance = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'record-payment'],
    );
});

it('no longer routes payments through a commercial document', function () {
    expect(Route::has('company.crm.documents.payments.store'))->toBeFalse()
        ->and(Route::has('company.crm.leads.payments.store'))->toBeFalse();
});

it('records payment against an approved quotation and raises its invoice', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $this->organization->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 1000, 'tax' => 0, 'discount' => 0, 'grand_total' => 1000,
    ]);

    $this->actingAs($this->finance)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 1000,
            'gateway' => 'manual',
            'status' => 'successful',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('payments', [
        'company_quotation_id' => $quotation->id,
        'amount' => 1000,
        'status' => 'successful',
    ]);

    /* The invoice is raised from the settlement, not drawn in advance. */
    expect(CommercialInvoice::where('company_quotation_id', $quotation->id)->exists())->toBeTrue();
});

it('prevents recording payment against a quotation nobody has approved', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $this->organization->id,
        'status' => QuotationStatus::Sent,
        'subtotal' => 1000, 'tax' => 0, 'discount' => 0, 'grand_total' => 1000,
    ]);

    $this->actingAs($this->finance)
        ->post(route('company.crm.quotations.payments.store', $quotation), [
            'amount' => 1000,
            'gateway' => 'manual',
            'status' => 'successful',
        ])
        ->assertSessionHasErrors('payment');

    $this->assertDatabaseEmpty('payments');
    expect(CommercialInvoice::count())->toBe(0);
});

it('leaves the historical document-keyed payment exactly as it is', function () {
    /*
     * The ₹5.4L on deal 7049 points at a commercial document and at no
     * quotation. It is never back-linked: the money is real, and the quotation
     * it would have belonged to does not exist. Nothing in the corrected flow
     * may rewrite it.
     */
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    $document = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'paid',
        'grand_total' => 540000,
    ]);

    $payment = Payment::factory()->create([
        'lead_id' => $lead->id,
        'commercial_document_id' => $document->id,
        'company_quotation_id' => null,
        'amount' => 540000,
        'status' => 'successful',
    ]);

    expect($payment->fresh()->commercial_document_id)->toBe($document->id)
        ->and($payment->fresh()->company_quotation_id)->toBeNull()
        ->and($lead->hasSuccessfulPayment())->toBeTrue()
        /* It settles no quotation, and so raises no invoice. That is the truth about it. */
        ->and(CommercialInvoice::count())->toBe(0);
});
