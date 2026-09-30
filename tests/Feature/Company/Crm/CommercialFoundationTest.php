<?php

use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Services\CRM\CommercialPageService;
use App\Services\LeadWorkspaceService;
use App\Services\QuotationApprovalService;
use App\Support\CrmPermissions;
use App\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * Commercials CC1 — money attaches to the quotation it settles.
 *
 * The commercial flow is Quotation → Approval → Payment → Invoice → Won. There
 * is no agreement step, and the invoice follows the money rather than preceding
 * it: it is raised once an approved quotation has been settled in full.
 *
 * Each quotation is independent. A customer may hold several, and payments key
 * on the quotation so money never bleeds between two proposals.
 *
 * The live ₹5.4L payment predates all of this. It points at an agreement, has
 * no quotation, and stays exactly that way — orphaned and visible rather than
 * back-linked to a proposal it was never raised against.
 */
function approvedQuotation(array $attributes = [], ?Lead $lead = null): CompanyQuotation
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

function settledPayment(CompanyQuotation $quotation, float $amount, string $status = 'successful'): Payment
{
    return Payment::factory()->create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'commercial_document_id' => null,
        'amount' => $amount,
        'status' => $status,
    ]);
}

/* ─────────────────────────────────────────────── projection ────────────── */

it('raises no invoice for an unpaid quotation', function () {
    /* The invoice follows the money — a quotation nobody has paid has none. */
    expect(app(CommercialLedgerService::class)->generateInvoice(approvedQuotation()))->toBeNull();
});

it('raises no invoice for a quotation that is not approved', function () {
    $quotation = approvedQuotation(['status' => QuotationStatus::Sent]);
    settledPayment($quotation, 11800);

    expect(app(CommercialLedgerService::class)->generateInvoice($quotation))->toBeNull();
});

it('raises the invoice once an approved quotation is settled in full', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 11800);

    $invoice = app(CommercialLedgerService::class)->generateInvoice($quotation);

    expect($invoice)->not->toBeNull()
        ->and($invoice->company_quotation_id)->toBe($quotation->id)
        ->and((float) $invoice->final_amount)->toBe(11800.0)
        ->and($invoice->status)->toBe('paid');
});

it('raises no invoice while a quotation is only part paid', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 5000);

    expect(app(CommercialLedgerService::class)->generateInvoice($quotation))->toBeNull()
        ->and(CommercialInvoice::count())->toBe(0);
});

it('raises no invoice for an overpaid quotation', function () {
    /*
     * Paper with the wrong number on it is worse than no paper. An overpayment
     * is for a person to resolve before anything is issued to a customer.
     */
    $quotation = approvedQuotation();
    settledPayment($quotation, 20000);

    expect(app(CommercialLedgerService::class)->generateInvoice($quotation))->toBeNull();
});

it('links payment, invoice and quotation in one chain', function () {
    $quotation = approvedQuotation();
    $payment = settledPayment($quotation, 11800);

    $booked = app(CommercialLedgerService::class)->recordPayment($payment);

    expect($booked)->not->toBeNull()
        ->and($booked->commercial_invoice_id)->not->toBeNull()
        ->and(CommercialInvoice::find($booked->commercial_invoice_id)->company_quotation_id)
        ->toBe($quotation->id);
});

it('books a legacy document payment with no invoice rather than inventing one', function () {
    /*
     * The shape of the live ₹5.4L row: a payment against an agreement, from
     * before this flow existed. It reaches the ledger and stays honestly
     * orphaned — it is not back-linked to any quotation.
     */
    $lead = Lead::factory()->create();
    $document = CommercialDocument::factory()->create([
        'lead_id' => $lead->id,
        'type' => 'agreement',
        'status' => 'paid',
    ]);

    $payment = Payment::factory()->create([
        'lead_id' => $lead->id,
        'commercial_document_id' => $document->id,
        'company_quotation_id' => null,
        'amount' => 540000,
        'status' => 'successful',
    ]);

    $booked = app(CommercialLedgerService::class)->recordPayment($payment);

    expect($booked)->not->toBeNull()
        ->and($booked->commercial_invoice_id)->toBeNull()
        ->and(CommercialInvoice::count())->toBe(0);
});

it('does not book a payment that has not settled', function () {
    $quotation = approvedQuotation();
    $pending = settledPayment($quotation, 11800, 'pending');

    expect(app(CommercialLedgerService::class)->recordPayment($pending))->toBeNull();
});

/* ─────────────────────────────────────────────── idempotency ───────────── */

it('raises the invoice once however often it is generated', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 11800);
    $ledger = app(CommercialLedgerService::class);

    $first = $ledger->generateInvoice($quotation);
    $second = $ledger->generateInvoice($quotation->fresh());
    $third = $ledger->generateInvoice($quotation->fresh());

    expect(CommercialInvoice::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id);
});

it('never double-counts a payment', function () {
    $quotation = approvedQuotation();
    $payment = settledPayment($quotation, 11800);
    $ledger = app(CommercialLedgerService::class);

    $ledger->recordPayment($payment);
    $ledger->recordPayment($payment->fresh());
    $ledger->recordPayment($payment->fresh());

    expect(CommercialPayment::count())->toBe(1)
        ->and(CommercialInvoice::count())->toBe(1);
});

/* ──────────────────────────────────────────── reconciliation ───────────── */

it('reports an unpaid quotation as unpaid', function () {
    $reconciliation = app(CommercialLedgerService::class)->reconcile(approvedQuotation());

    expect($reconciliation['state'])->toBe(CommercialLedgerService::UNPAID)
        ->and($reconciliation['expected'])->toBe(11800.0)
        ->and($reconciliation['settled'])->toBe(0.0)
        ->and($reconciliation['balance'])->toBe(11800.0);
});

it('reports an exactly settled quotation as paid', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 11800);

    $reconciliation = app(CommercialLedgerService::class)->reconcile($quotation);

    expect($reconciliation['state'])->toBe(CommercialLedgerService::PAID)
        ->and($reconciliation['balance'])->toBe(0.0);
});

it('reports part payment as partially paid, with what is still owed', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 5000);

    $reconciliation = app(CommercialLedgerService::class)->reconcile($quotation);

    expect($reconciliation['state'])->toBe(CommercialLedgerService::PARTIALLY_PAID)
        ->and($reconciliation['settled'])->toBe(5000.0)
        ->and($reconciliation['balance'])->toBe(6800.0);
});

it('adds part payments up rather than looking at only the last one', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 5000);
    settledPayment($quotation, 6800);

    $reconciliation = app(CommercialLedgerService::class)->reconcile($quotation);

    expect($reconciliation['state'])->toBe(CommercialLedgerService::PAID)
        ->and($reconciliation['payments'])->toBe(2);
});

it('counts only settled money towards the balance', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 5000);
    settledPayment($quotation, 6800, 'pending');

    expect(app(CommercialLedgerService::class)->reconcile($quotation)['state'])
        ->toBe(CommercialLedgerService::PARTIALLY_PAID);
});

it('reports more money than was asked for as overpaid', function () {
    $quotation = approvedQuotation(['subtotal' => 4999, 'tax' => 0, 'discount' => 0, 'grand_total' => 4999]);
    settledPayment($quotation, 540000);

    $reconciliation = app(CommercialLedgerService::class)->reconcile($quotation);

    expect($reconciliation['state'])->toBe(CommercialLedgerService::OVERPAID)
        ->and($reconciliation['balance'])->toBe(-535001.0);
});

it('reports a quotation whose own arithmetic does not hold as a mismatch', function () {
    $broken = approvedQuotation(['subtotal' => 10000, 'tax' => 1800, 'discount' => 0, 'grand_total' => 99999]);
    settledPayment($broken, 5000);

    expect(app(CommercialLedgerService::class)->reconcile($broken)['state'])
        ->toBe(CommercialLedgerService::MISMATCH);
});

it('tolerates a rounding paisa rather than crying underpayment', function () {
    $quotation = approvedQuotation();
    settledPayment($quotation, 11799.995);

    expect(app(CommercialLedgerService::class)->reconcile($quotation)['state'])
        ->toBe(CommercialLedgerService::PAID);
});

it('keeps money on one quotation from bleeding into another', function () {
    /*
     * A customer may hold several quotations, each independent. Money keys on
     * the quotation, so paying one settles nothing on the other.
     */
    $lead = Lead::factory()->create();
    $first = approvedQuotation([], $lead);
    $second = approvedQuotation([], $lead);

    settledPayment($first, 11800);

    $ledger = app(CommercialLedgerService::class);

    expect($ledger->reconcile($first)['state'])->toBe(CommercialLedgerService::PAID)
        ->and($ledger->reconcile($second)['state'])->toBe(CommercialLedgerService::UNPAID);
});

/* ─────────────────────────────────────────────────── the Won gate ──────── */

it('wins the deal on any approved and fully paid quotation', function () {
    $lead = Lead::factory()->create();
    $quotation = approvedQuotation([], $lead);
    settledPayment($quotation, 11800);

    expect($lead->fresh()->hasApprovedPaidQuotation())->toBeTrue();
});

it('does not win the deal on a part paid quotation', function () {
    $lead = Lead::factory()->create();
    settledPayment(approvedQuotation([], $lead), 5000);

    expect($lead->fresh()->hasApprovedPaidQuotation())->toBeFalse();
});

it('does not win the deal on a paid quotation that was never approved', function () {
    $lead = Lead::factory()->create();
    settledPayment(approvedQuotation(['status' => QuotationStatus::Sent], $lead), 11800);

    expect($lead->fresh()->hasApprovedPaidQuotation())->toBeFalse();
});

it('allows two approved and paid quotations on one deal', function () {
    /*
     * A customer may buy twice on the same deal. Requiring a single blessed
     * quotation would leave the second purchase unable to close the deal it
     * belongs to.
     */
    $lead = Lead::factory()->create();
    $first = approvedQuotation([], $lead);
    $second = approvedQuotation([], $lead);

    settledPayment($first, 11800);
    settledPayment($second, 11800);

    $ledger = app(CommercialLedgerService::class);

    expect($ledger->reconcile($first)['state'])->toBe(CommercialLedgerService::PAID)
        ->and($ledger->reconcile($second)['state'])->toBe(CommercialLedgerService::PAID)
        ->and($lead->fresh()->hasApprovedPaidQuotation())->toBeTrue()
        /* One invoice each. Two purchases are two pieces of paper. */
        ->and(CommercialInvoice::count())->toBe(2);
});
/* ──────────────────────────────────────────────── the policy ───────────── */

beforeEach(function () {
    $this->organization = Organization::factory()->create();

    /*
     * A sales rep: authors quotations, cannot sign off their own discount.
     *
     * Stated explicitly rather than taking the helper's default set, which
     * carries `view-operations` — a grant this codebase treats as a manager
     * signal, and the one that decides approval. A "rep" holding it would make
     * the separation untestable.
     */

    /*
     * The quotation is reached through its lead — that is what `CrmScope`
     * scopes by, because a quotation raised against a pre-Won deal has no
     * organisation of its own.
     */
    $this->rep = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'view-leads', 'create leads', 'edit own leads', 'manage-quotations'],
    );

    /*
     * Assigned to the rep, because a rep who is not a manager sees the deals
     * they own and no others. An unassigned lead would make the scoped list
     * empty for reasons that have nothing to do with what these tests check.
     */
    $this->lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $this->quotation = CompanyQuotation::factory()->create([
        'organization_id' => $this->organization->id,
        'lead_id' => $this->lead->id,
        'status' => QuotationStatus::Sent,
    ]);

    /** Can look at the pipeline and nothing more. */
    $this->reader = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations'],
    );

    /** Management: approves. */
    $this->manager = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'view-operations'],
    );
});

it('lets a read-only user see a quotation', function () {
    expect(Gate::forUser($this->reader)->allows('view', $this->quotation))->toBeTrue()
        ->and(Gate::forUser($this->reader)->allows('viewAny', CompanyQuotation::class))->toBeTrue();
});

it('does not let reading a quotation grant editing one', function () {
    /*
     * The defect: `update` accepted `view-quotations` or `view-crm`, so anyone
     * who could open the Commercials page could rewrite a price on a proposal
     * that had already gone to the customer.
     */
    expect(Gate::forUser($this->reader)->allows('update', $this->quotation))->toBeFalse()
        ->and(Gate::forUser($this->reader)->allows('create', CompanyQuotation::class))->toBeFalse();
});

it('lets a rep author and edit, but not approve their own discount', function () {
    expect(Gate::forUser($this->rep)->allows('create', CompanyQuotation::class))->toBeTrue()
        ->and(Gate::forUser($this->rep)->allows('update', $this->quotation))->toBeTrue()
        ->and(Gate::forUser($this->rep)->allows('approve', $this->quotation))->toBeFalse()
        ->and(Gate::forUser($this->rep)->allows('reject', $this->quotation))->toBeFalse();
});

it('lets management approve', function () {
    expect(Gate::forUser($this->manager)->allows('approve', $this->quotation))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('reject', $this->quotation))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('delete', $this->quotation))->toBeTrue();
});

it('keeps view, update and approve as three separate authorities', function () {
    /* Nobody in this fixture holds all three, and that is the point. */
    $abilities = fn (User $u) => [
        'view' => Gate::forUser($u)->allows('view', $this->quotation),
        'update' => Gate::forUser($u)->allows('update', $this->quotation),
        'approve' => Gate::forUser($u)->allows('approve', $this->quotation),
    ];

    expect($abilities($this->reader))->toBe(['view' => true, 'update' => false, 'approve' => false])
        ->and($abilities($this->rep))->toBe(['view' => true, 'update' => true, 'approve' => false])
        ->and($abilities($this->manager))->toBe(['view' => true, 'update' => false, 'approve' => true]);
});

/* ──────────────────────────────────────── tenant isolation ─────────────── */

it('hides a quotation belonging to another tenant', function () {
    $outsider = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => Organization::factory()->create()->id]),
    );

    expect(Gate::forUser($outsider)->allows('view', $this->quotation))->toBeFalse()
        ->and(Gate::forUser($outsider)->allows('update', $this->quotation))->toBeFalse();
});

it('refuses approval across a tenant boundary even to management', function () {
    /*
     * Approval is the sharpest authority in the module, so it gets the tenant
     * check too — a manager elsewhere must not clear somebody else's discount.
     */
    $otherManager = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => Organization::factory()->create()->id]),
        ['view-crm', 'view-quotations', 'view-operations'],
    );

    expect(Gate::forUser($otherManager)->allows('approve', $this->quotation))->toBeFalse();
});

it('refuses a clinic user outright', function () {
    $clinicUser = User::factory()->create(['user_type' => UserType::ClinicUser]);

    expect(Gate::forUser($clinicUser)->allows('view', $this->quotation))->toBeFalse()
        ->and(Gate::forUser($clinicUser)->allows('update', $this->quotation))->toBeFalse();
});

/* ────────────────────────────────────────── the page payload ───────────── */

it('serves money as numbers rather than as formatted strings', function () {
    /*
     * The old payload shipped `'₹ 1,234.00'`, which the client cannot sort,
     * total or re-render.
     */
    $payload = app(CommercialPageService::class)->listPayload($this->rep);
    $row = collect($payload['rows'])->firstWhere('id', $this->quotation->id);

    expect($row['grandTotal'])->toBeFloat()
        ->and($payload['money']['proposed'])->toBeFloat();
});

it('puts a money discrepancy at the top of the list', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $overpaid = approvedQuotation([
        'organization_id' => $this->organization->id,
        'subtotal' => 1000, 'tax' => 0, 'discount' => 0, 'grand_total' => 1000,
    ], $lead);

    settledPayment($overpaid, 9999);

    $payload = app(CommercialPageService::class)->listPayload($this->rep);

    expect($payload['rows'][0]['settlement']['needsAttention'])->toBeTrue()
        ->and($payload['rows'][0]['settlement']['reconciliation']['state'])
        ->toBe(CommercialLedgerService::OVERPAID)
        ->and($payload['counts']['needsAttention'])->toBe(1);
});

it('carries can flags that are real gate checks', function () {
    $page = app(CommercialPageService::class);

    $readerPayload = $page->listPayload($this->reader);
    $repPayload = $page->listPayload($this->rep);
    $repRow = collect($repPayload['rows'])->firstWhere('id', $this->quotation->id);

    /*
     * The reader's per-record flags are checked on the detail payload, which is
     * the surface a reader reaches by opening a link — the list is scoped to
     * their own assigned deals and would be empty here for reasons unrelated to
     * permissions.
     */
    $readerDetail = $page->detailPayload($this->quotation, $this->reader);

    expect($readerPayload['can']['create'])->toBeFalse()
        ->and($readerDetail['can']['update'])->toBeFalse()
        ->and($readerDetail['can']['approve'])->toBeFalse()
        ->and($repPayload['can']['create'])->toBeTrue()
        ->and($repRow['can']['update'])->toBeTrue()
        ->and($repRow['can']['approve'])->toBeFalse();
});

it('reports what is agreed, settled and still outstanding', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $quotation = approvedQuotation(['organization_id' => $this->organization->id], $lead);
    settledPayment($quotation, 5000);

    expect(app(CommercialLedgerService::class)->reconcile($quotation))
        ->toMatchArray(['expected' => 11800.0, 'settled' => 5000.0, 'balance' => 6800.0]);
});

it('shows the payments behind one quotation on its detail payload', function () {
    $lead = Lead::factory()->create([
        'organization_id' => $this->organization->id,
        'assigned_user_id' => $this->rep->id,
    ]);

    $quotation = approvedQuotation(['organization_id' => $this->organization->id], $lead);
    settledPayment($quotation, 5000);

    expect($quotation->payments()->count())->toBe(1)
        ->and((float) $quotation->payments()->first()->amount)->toBe(5000.0);
});

/* ──────────────────────────────────── the header discount ─────────────── */

it('defaults the header discount from the line items', function () {
    /*
     * The line total remains the default — it is only the default. Nothing
     * about making the header editable changes what an untouched quotation
     * says.
     */
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)->post(route('company.crm.quotations.store'), [
        'lead_id' => $lead->id,
        'items' => [
            ['title' => 'Starter', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 500],
        ],
    ])->assertSessionHasNoErrors();

    $quotation = CompanyQuotation::where('lead_id', $lead->id)->firstOrFail();

    // Taxable 10000 - 500 = 9500; 18% GST = 1710; grand total = 11210.
    expect((float) $quotation->discount)->toBe(500.0)
        ->and($quotation->discount_is_manual)->toBeFalse()
        ->and((float) $quotation->grand_total)->toBe(11210.0);
});

it('lets an authorised user set the headline discount', function () {
    $quotation = approvedQuotation(['organization_id' => $this->organization->id, 'approved_at' => null, 'status' => QuotationStatus::Draft]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 2000])
        ->assertSessionHasNoErrors();

    $quotation->refresh();

    // Taxable 10000 - 2000 = 8000; 18% GST = 1440; grand total = 9440.
    expect((float) $quotation->discount)->toBe(2000.0)
        ->and($quotation->discount_is_manual)->toBeTrue()
        ->and((float) $quotation->grand_total)->toBe(9440.0);
});

it('does not let a read-only user set a discount', function () {
    $quotation = approvedQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->reader)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 2000])
        ->assertForbidden();
});

it('revokes approval when the discount on an approved quotation changes', function () {
    /*
     * Without this the approval gate is walked around in two moves: get a
     * modest discount approved, then raise it.
     */
    $quotation = approvedQuotation([
        'organization_id' => $this->organization->id,
        'sent_at' => now(),
    ]);

    expect(app(QuotationApprovalService::class)->isApproved($quotation))->toBeTrue();

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 4000])
        ->assertSessionHasNoErrors();

    $quotation->refresh();

    expect($quotation->approved_at)->toBeNull()
        ->and($quotation->status)->toBe(QuotationStatus::Sent)
        ->and(app(QuotationApprovalService::class)->isApproved($quotation))->toBeFalse();
});

it('leaves approval alone when the discount is set to what it already was', function () {
    $quotation = approvedQuotation(['organization_id' => $this->organization->id, 'discount' => 1500, 'grand_total' => 10300]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 1500])
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->approved_at)->not->toBeNull();
});

it('recalculates the approval threshold from the new discount', function () {
    config(['crm.quotation_approval_discount_percent' => 20]);

    $quotation = approvedQuotation(['organization_id' => $this->organization->id, 'approved_at' => null, 'status' => QuotationStatus::Draft]);
    $approvals = app(QuotationApprovalService::class);

    expect($approvals->requiresApproval($quotation))->toBeFalse();

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 3000, $this->rep);

    /* 3000 of a 10000 subtotal is 30%, over the 20% threshold. */
    expect($approvals->requiresApproval($quotation->fresh()))->toBeTrue();
});

it('refuses a discount larger than the quotation itself', function () {
    $quotation = approvedQuotation(['organization_id' => $this->organization->id]);

    $this->actingAs($this->rep)
        ->put(route('company.crm.quotations.discount', $quotation), ['discount_type' => 'fixed', 'discount_value' => 999999])
        ->assertSessionHasErrors('discount');
});

it('does not let a line-item edit destroy a discount somebody chose', function () {
    $lead = Lead::factory()->create(['organization_id' => $this->organization->id]);
    $quotation = approvedQuotation(['organization_id' => $this->organization->id], $lead);

    app(LeadWorkspaceService::class)->setQuotationDiscount($quotation, 'fixed', 2500, $this->rep);

    $this->actingAs($this->rep)->post(route('company.crm.quotations.revise', $quotation->fresh()), [
        'items' => [['title' => 'Revised', 'quantity' => 1, 'unit_price' => 10000, 'discount' => 0]],
    ]);

    /* The original keeps the number a person set. */
    expect((float) $quotation->fresh()->discount)->toBe(2500.0);
});

/* ───────────────────────────────── record-payment permission ──────────── */

it('keeps recording money separate from editing a quotation', function () {
    /*
     * Writing down that money arrived is a finance act, not a sales one. A rep
     * who prices the deal is not automatically the person who says it was paid.
     */
    expect(CrmPermissions::check($this->rep, 'manage-quotations'))->toBeTrue()
        ->and(CrmPermissions::check($this->rep, 'record-payment'))->toBeFalse();

    $finance = grantCrmPermissions(
        User::factory()->companyUser()->create(['organization_id' => $this->organization->id]),
        ['view-crm', 'view-quotations', 'record-payment'],
    );

    expect(CrmPermissions::check($finance, 'record-payment'))->toBeTrue()
        ->and(CrmPermissions::check($finance, 'manage-quotations'))->toBeFalse();
});
