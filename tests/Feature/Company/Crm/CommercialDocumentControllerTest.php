<?php

use App\Http\Controllers\Company\Crm\CommercialDocumentController;
use App\Http\Controllers\Company\Crm\PaymentController;
use App\Models\CommercialDocument;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CRM\DealPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * What is left of commercial documents after CC3.
 *
 * The Documents surface is retired: nothing creates, revises or accepts a
 * commercial document any more, and the controller and its routes are gone.
 * The corrected flow is Quotation → Approval → Payment → Invoice → Won.
 *
 * The rows themselves are not gone, and must not be. One live payment of ₹5.4L
 * points at a historical agreement, `Lead::hasAcceptedAgreement()` still reads
 * these documents so deals closed under the old flow keep winning, and the
 * status rules on the model still govern anything that touches them.
 *
 * These tests were the controller's; they now hold the line the controller
 * used to hold — that the retired routes stay retired, and that the historical
 * records stay readable and correctly guarded.
 */

/* ─────────────────────────────── the surface is retired ────────────────── */

it('no longer routes any commercial documents endpoint', function () {
    foreach ([
        'company.crm.documents.index',
        'company.crm.documents.create',
        'company.crm.documents.store',
        'company.crm.documents.show',
        'company.crm.documents.status',
        'company.crm.documents.revise',
        'company.crm.documents.payments.store',
    ] as $name) {
        expect(Route::has($name))->toBeFalse("route {$name} still exists");
    }
});

it('has no controller left to reach them with', function () {
    expect(class_exists(CommercialDocumentController::class))->toBeFalse()
        ->and(class_exists(PaymentController::class))->toBeFalse();
});

/* ──────────────────────────── the records are still real ───────────────── */

it('keeps a historical agreement readable, with the payment that settled it', function () {
    $user = User::factory()->masterAdmin()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $user->id]);

    $agreement = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'paid',
        'grand_total' => 540000,
    ]);

    /*
     * The shape of the live ₹5.4L row: keyed to the document, not to any
     * quotation. It is deliberately never back-linked — the money is real, the
     * quotation it would have belonged to does not exist.
     */
    $payment = Payment::factory()->create([
        'lead_id' => $lead->id,
        'commercial_document_id' => $agreement->id,
        'company_quotation_id' => null,
        'amount' => 540000,
        'status' => 'successful',
    ]);

    expect($lead->hasAcceptedAgreement())->toBeTrue()
        ->and($lead->hasSuccessfulPayment())->toBeTrue()
        ->and($payment->fresh()->company_quotation_id)->toBeNull()
        ->and($agreement->fresh()->grand_total)->not->toBeNull();
});

it('still refuses an invalid status transition on a historical document', function () {
    $lead = Lead::factory()->create();
    $document = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'draft',
    ]);

    /* draft → accepted was never a legal move, and still is not. */
    expect(fn () => $document->update(['status' => 'accepted']))
        ->toThrow(ValidationException::class);

    expect($document->fresh()->status)->toBe('draft');
});

it('still allows the transitions the status rules do permit', function () {
    $lead = Lead::factory()->create();
    $document = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'status' => 'draft',
    ]);

    $document->update(['status' => 'under_review']);

    expect($document->fresh()->status)->toBe('under_review');
});

/* ────────────────────────── the surfaces still render ──────────────────── */

it('renders the Commercials workspace with no agreement anywhere in its payload', function () {
    $organization = Organization::factory()->create();

    $user = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $organization->id]),
        ['view-crm', 'view-quotations', 'view-leads', 'manage-quotations', 'record-payment'],
    );

    $lead = Lead::factory()->create(['organization_id' => $organization->id]);

    $quotation = CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'organization_id' => $organization->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000, 'tax' => 0, 'discount' => 0, 'grand_total' => 10000,
    ]);

    Payment::factory()->create([
        'lead_id' => $lead->id,
        'company_quotation_id' => $quotation->id,
        'amount' => 10000,
        'status' => 'successful',
    ]);

    $this->actingAs($user)
        ->get(route('company.crm.quotations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Company/Crm/Commercial/Index'));

    $this->actingAs($user)
        ->get(route('company.crm.quotations.show', $quotation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Company/Crm/Commercial/Show')
            ->where('quotation.financiallyLocked', true)
        );
});

it('renders Deal Detail with the corrected commercial zone', function () {
    $organization = Organization::factory()->create();

    $user = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $organization->id]),
        ['view-crm', 'view-quotations', 'view-leads', 'edit own leads', 'manage-quotations', 'record-payment'],
    );

    $lead = Lead::factory()->create([
        'organization_id' => $organization->id,
        'assigned_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('company.crm.leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Company/Crm/Leads/Show'));

    /* The zone payload the page composes from: invoices, never agreements. */
    $commercial = app(DealPageService::class)->commercial($lead);

    expect($commercial)->toHaveKey('invoices')
        ->and($commercial)->not->toHaveKey('agreements')
        ->and($commercial['lifecycle'])->toHaveKeys(['steps', 'settled_quotation_id', 'invoice_number', 'can_close'])
        ->and($commercial['lifecycle'])->not->toHaveKey('can_generate_agreement')
        ->and(collect($commercial['lifecycle']['steps'])->pluck('key')->all())
        ->toBe(['quotation', 'approved', 'payment', 'invoice', 'won']);
});
