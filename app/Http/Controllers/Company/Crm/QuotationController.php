<?php

namespace App\Http\Controllers\Company\Crm;

use App\ActivityType;
use App\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\CommercialInvoice;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Payment;
use App\PaymentMethod;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Services\CRM\CommercialPageService;
use App\Services\LeadWorkspaceService;
use App\Services\QuotationApprovalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QuotationController extends Controller
{
    public function __construct(
        protected LeadWorkspaceService $workspaceService,
        protected QuotationApprovalService $approvals,
        protected CommercialPageService $page,
    ) {}

    /**
     * Display Quotation Command Center Index Page.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', CompanyQuotation::class);

        /*
         * Served from CommercialPageService rather than the lead workspace: the
         * module has to answer "what have we billed and who has paid?", and the
         * old payload knew only about proposals — money arrived pre-formatted
         * as strings and agreements were not in it at all.
         */
        return Inertia::render('Company/Crm/Commercial/Index', $this->page->listPayload(
            $request->user(),
            $request->only(['search', 'status']),
        ));
    }

    /**
     * Store a new proposal in draft mode.
     */
    /**
     * Raise a quotation, from the quotations page or from a deal.
     *
     * The deal route carries the lead in the URL — `leads/{lead}/create-quotation`
     * — and this method only ever read `lead_id` from the body, so raising a
     * quotation from a deal failed validation every time. The modal showed no
     * error for a field it does not have, so the button did nothing at all.
     */
    public function store(Request $request, ?Lead $lead = null)
    {
        Gate::authorize('create', CompanyQuotation::class);

        if ($lead?->exists) {
            $request->merge(['lead_id' => $lead->id]);
        }

        $validated = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'company_plan_id' => 'nullable|exists:company_plans,id',
            'billing_cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            /*
             * GST is computed, not typed in — see
             * `LeadWorkspaceService::saveItemsAndCalculateTotals()`. This is
             * an escape hatch for the rare quotation that genuinely needs a
             * non-standard rate, not a routine field; nothing in the create
             * form sends it today.
             */
            'gst_rate' => 'nullable|numeric|min:0|max:100',
            'amount' => 'nullable|numeric|min:0',
            /*
             * The package builder: additional seats beyond the plan's own
             * `max_users`, and any catalogue add-ons riding on it. Nothing
             * before Phase 2B ever sent either field, so their presence is
             * exactly what decides whether `LeadWorkspaceService::
             * buildPackageLineItems()` expands the plan, or the older
             * single-line-from-plan behaviour below still applies.
             */
            'additional_users' => 'nullable|integer|min:0',
            'addon_ids' => 'nullable|array',
            'addon_ids.*' => 'integer|exists:company_package_addons,id',
            'payment_terms' => 'nullable|string|max:2000',
            'implementation_terms' => 'nullable|string|max:2000',
            'support_terms' => 'nullable|string|max:2000',
            'cancellation_terms' => 'nullable|string|max:2000',
            'items' => 'nullable|array',
            'items.*.title' => 'required_with:items|string|max:255',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ]);

        $lead = Lead::findOrFail($validated['lead_id']);

        if (Gate::denies('update', $lead)) {
            abort(403, 'Unauthorized to raise a quotation for this lead.');
        }

        $companyPlan = ! empty($validated['company_plan_id']) ? CompanyPlan::find($validated['company_plan_id']) : null;
        $hasAmountOverride = $companyPlan && isset($validated['amount']) && (float) $validated['amount'] > 0;

        if (! empty($validated['items'])) {
            $items = $validated['items'];
        } elseif ($hasAmountOverride) {
            /*
             * A stated amount wins over the list price.
             *
             * The plan's price was used unconditionally, so a rep quoting a
             * ₹5.4L contract on the Starter plan produced a ₹4,999 quotation
             * and had no way to see why. The plan still names the line; what
             * the deal is actually worth decides the number. This is a single
             * custom-priced line rather than the package builder below —
             * the two are different intents and do not combine.
             */
            $items = [
                [
                    'title' => "{$companyPlan->name} Plan — {$companyPlan->billing_period}",
                    'description' => $companyPlan->description ?? "Up to {$companyPlan->max_doctors} doctors, {$companyPlan->max_branches} branch(es), {$companyPlan->max_users} users.",
                    'quantity' => 1,
                    'unit_price' => (float) $validated['amount'],
                    'discount' => 0,
                ],
            ];
        } elseif ($companyPlan) {
            /*
             * The package builder: `createQuotation()` expands the plan
             * itself, its additional users, and its add-ons into their own
             * line items via `buildPackageLineItems()` — nothing to build
             * here, this just needs to not also synthesise a competing item.
             */
            $items = [];
        } else {
            $items = [
                [
                    'title' => 'Commercial License & Support Package',
                    'description' => 'Standard subscription package',
                    'quantity' => 1,
                    'unit_price' => (float) ($validated['amount'] ?? 5000),
                    'discount' => 0,
                ],
            ];
        }

        try {
            $quotation = $this->workspaceService->createQuotation(
                $lead,
                $validated,
                $items,
                $request->user()
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['items' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "Quotation {$quotation->quotation_number} generated successfully in Draft mode.");
    }

    /**
     * Show quotation detail workspace.
     */
    public function show(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        return Inertia::render('Company/Crm/Commercial/Show', array_merge(
            $this->page->detailPayload($quotation, $request->user()),
            /*
             * The approval state carries the discount threshold and why an
             * approval is or is not required — commercial policy the payload
             * builder has no business duplicating.
             */
            ['approval' => $this->approvals->state($quotation, $request->user())],
        ));
    }

    /**
     * Stream the quotation as a real PDF.
     *
     * Rendered from the exact same Blade template as the printable HTML route,
     * so the two can never disagree on a figure — one template, two renderers.
     */
    public function pdf(CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        $number = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $quotation->quotation_number) ?: "quotation-{$quotation->id}";
        $filename = "{$number}-v{$quotation->version}.pdf";

        return Pdf::loadView('crm.quotation_printable', $this->printableViewData($quotation))
            ->setPaper('a4')
            ->stream($filename);
    }

    /**
     * Update draft quotation.
     */
    public function update(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('update', $quotation);

        $validated = $request->validate([
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'items' => 'nullable|array',
        ]);

        /*
         * Notes and terms stay editable on a locked quotation — they are not
         * the commercial terms. Only the money is fixed.
         *
         * There is deliberately no `status` field here. Every real status
         * change — send, approve, reject, the discount-approval revocation —
         * has its own endpoint that runs it through
         * `QuotationStateMachine::ensure()`; a generic "update" accepting an
         * arbitrary status string would let a caller skip that guard
         * entirely, which is exactly the alternate unguarded path the
         * transition guard exists to close.
         */
        if (! empty($validated['items'])) {
            if ($quotation->isFinanciallyLocked()) {
                return back()->withErrors([
                    'items' => 'This quotation has been settled and invoiced, so its line items are fixed. Revise it to quote something different.',
                ]);
            }

            try {
                $this->workspaceService->saveItemsAndCalculateTotals($quotation, $validated['items']);
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['items' => $e->getMessage()]);
            }
        }

        $updateData = [];
        if (array_key_exists('notes', $validated)) {
            $updateData['notes'] = $validated['notes'];
        }
        if (! empty($validated['valid_until'])) {
            $updateData['valid_until'] = $validated['valid_until'];
        }
        if (! empty($validated['terms'])) {
            $updateData['terms'] = $validated['terms'];
        }

        if (! empty($updateData)) {
            CompanyQuotation::where('id', $quotation->id)->update($updateData);

            if ($quotation->lead) {
                $quotation->lead->activities()->create([
                    'user_id' => $request->user()?->id,
                    'type' => ActivityType::Quotation,
                    'description' => "Quotation updated: {$quotation->quotation_number}",
                ]);
            }
        }

        return back()->with('success', "Quotation {$quotation->quotation_number} updated successfully.");
    }

    /**
     * Create a revised version (v2, v3) of a proposal.
     */
    public function revise(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('update', $quotation);

        $validated = $request->validate([
            'company_plan_id' => 'nullable|exists:company_plans,id',
            'billing_cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'valid_until' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'gst_rate' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.title' => 'required|string|max:255',
            'items.*.description' => 'nullable|string|max:1000',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ]);

        try {
            $revised = $this->workspaceService->reviseQuotation(
                $quotation,
                $validated['items'],
                $validated,
                $request->user()
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('success', "Quotation revised to version v{$revised->version}.");
    }

    /**
     * Send commercial quotation to client.
     */
    public function send(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('update', $quotation);

        try {
            $result = $this->workspaceService->sendQuotation($quotation, [], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('success', $result['message']);
    }

    /**
     * Record money received against a quotation.
     *
     * Only against an approved one. A proposal nobody has signed off is not
     * something a customer owes, so booking money against it would put revenue
     * in the ledger for terms that were never agreed.
     *
     * The invoice is not raised here. It follows from settlement — see
     * {@see CommercialLedgerService::generateInvoice()} — so a
     * part payment leaves the quotation open and an overpayment raises nothing
     * at all until a person has looked at it.
     */
    public function storePayment(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('recordPayment', $quotation);

        $status = $quotation->status instanceof QuotationStatus
            ? $quotation->status->value
            : (string) $quotation->status;

        if ($status !== QuotationStatus::Approved->value) {
            return back()->withErrors([
                'payment' => 'Money can only be recorded against an approved quotation.',
            ]);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'gateway' => 'required|string|max:60',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'transaction_id' => 'nullable|string|max:120',
            'payment_date' => 'nullable|date',
            'status' => 'required|string|in:pending,successful,failed',
        ]);

        Payment::create([
            'lead_id' => $quotation->lead_id,
            'company_quotation_id' => $quotation->id,
            'amount' => $validated['amount'],
            'currency' => 'INR',
            'transaction_id' => $validated['transaction_id'] ?? null,
            'gateway' => $validated['gateway'],
            'payment_method' => $validated['payment_method'] ?? null,
            'payment_date' => $validated['payment_date'] ?? null,
            'status' => $validated['status'],
            'recorded_by' => $request->user()?->id,
        ]);

        $reconciliation = app(CommercialLedgerService::class)->reconcile($quotation->fresh());

        return back()->with('success', match ($reconciliation['state']) {
            CommercialLedgerService::PAID => 'Payment recorded. The quotation is settled and its invoice has been raised.',
            CommercialLedgerService::OVERPAID => 'Payment recorded, but more has now been received than this quotation asks for. No invoice was raised.',
            CommercialLedgerService::PARTIALLY_PAID => 'Payment recorded. ₹'.number_format($reconciliation['balance']).' still outstanding.',
            default => 'Payment recorded.',
        });
    }

    /**
     * Set the headline discount.
     *
     * Gated on `manage-quotations` through the policy's `update` — pricing a
     * deal is authoring work. Approving the result is a separate authority, and
     * changing the number here revokes any approval that already stood.
     */
    public function discount(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('update', $quotation);

        $validated = $request->validate([
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => 'required|numeric|min:0',
        ]);

        try {
            $result = $this->workspaceService->setQuotationDiscount(
                $quotation,
                $validated['discount_type'],
                (float) $validated['discount_value'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['discount' => $e->getMessage()]);
        }

        return back()->with('success', $result['approval_revoked']
            ? 'Discount updated. This quotation needs approving again.'
            : ($result['requires_approval'] ? 'Discount updated. It needs management approval before it can be sent.' : 'Discount updated.'));
    }

    /**
     * Approve proposal in CRM console.
     */
    public function approve(Request $request, CompanyQuotation $quotation)
    {
        // Approval is a management authority, not an editing one: a rep who can
        // update a quotation must not be able to clear their own discount.
        Gate::authorize('crm.quotations.approve');
        Gate::authorize('update', $quotation);

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $approved = $this->workspaceService->approveQuotation($quotation, $request->user(), $validated['notes'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('success', "Quotation {$approved->quotation_number} marked Approved and lead stage updated.");
    }

    /**
     * Reject proposal with client reason.
     */
    public function reject(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('crm.quotations.approve');
        Gate::authorize('update', $quotation);

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $rejected = $this->workspaceService->rejectQuotation($quotation, $validated['reason'], $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['quotation' => $e->getMessage()]);
        }

        return back()->with('success', "Quotation {$rejected->quotation_number} status updated to Rejected.");
    }

    /**
     * Render printable quotation view.
     */
    public function printable(CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        return view('crm.quotation_printable', $this->printableViewData($quotation));
    }

    /**
     * Data shared by the printable HTML view and the PDF renderer.
     *
     * Kept in exactly one place so the two can never be built from different
     * data — the seller is the single provider organisation (`Organization::
     * provider()`), never a hardcoded name/address/GSTIN.
     *
     * @return array{quotation: CompanyQuotation, seller: ?Organization}
     */
    protected function printableViewData(CompanyQuotation $quotation): array
    {
        $quotation->load(['lead', 'preparedBy.user', 'items']);

        return [
            'quotation' => $quotation,
            'seller' => Organization::provider(),
        ];
    }

    /**
     * Issue a Proforma Invoice for an approved, not-yet-settled quotation.
     */
    public function issueProforma(Request $request, CompanyQuotation $quotation)
    {
        Gate::authorize('update', $quotation);

        try {
            $quotation = $this->workspaceService->issueProforma($quotation, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['proforma' => $e->getMessage()]);
        }

        return back()->with('success', "Proforma invoice {$quotation->proforma_number} issued.");
    }

    /**
     * Render the printable Proforma Invoice.
     *
     * Shares the quotation printable's own view data and canonical GST
     * fields — a proforma is the same commercial facts, formatted as a
     * request for payment rather than a tax document.
     */
    public function proforma(CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        abort_if(! $quotation->proforma_number, 404, 'No proforma invoice has been issued for this quotation yet.');

        return view('crm.proforma_printable', $this->printableViewData($quotation));
    }

    public function proformaPdf(CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        abort_if(! $quotation->proforma_number, 404, 'No proforma invoice has been issued for this quotation yet.');

        $number = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $quotation->proforma_number) ?: "proforma-{$quotation->id}";

        return Pdf::loadView('crm.proforma_printable', $this->printableViewData($quotation))
            ->setPaper('a4')
            ->stream("{$number}.pdf");
    }

    /**
     * Render the printable Tax Invoice.
     *
     * Reads from the `CommercialInvoice` row's own snapshotted GST and money
     * fields — a legal point-in-time record — rather than re-deriving them
     * from the (possibly since-revised) quotation.
     */
    public function taxInvoice(CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->first();

        abort_if(! $invoice, 404, 'No tax invoice has been raised for this quotation yet.');

        return view('crm.tax_invoice_printable', $this->taxInvoiceViewData($invoice));
    }

    public function taxInvoicePdf(CompanyQuotation $quotation)
    {
        Gate::authorize('view', $quotation);

        $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->first();

        abort_if(! $invoice, 404, 'No tax invoice has been raised for this quotation yet.');

        $number = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $invoice->invoice_number) ?: "invoice-{$invoice->id}";

        return Pdf::loadView('crm.tax_invoice_printable', $this->taxInvoiceViewData($invoice))
            ->setPaper('a4')
            ->stream("{$number}.pdf");
    }

    protected function taxInvoiceViewData(CommercialInvoice $invoice): array
    {
        $invoice->load(['quotation.lead', 'quotation.items', 'quotation.preparedBy.user']);

        return [
            'invoice' => $invoice,
            'seller' => Organization::provider(),
        ];
    }

    /**
     * Render the printable Receipt for one settled payment.
     *
     * Payments are scoped to the quotation in the route so a receipt can
     * never be requested by an id belonging to a different quotation.
     */
    public function receipt(CompanyQuotation $quotation, Payment $payment)
    {
        Gate::authorize('view', $quotation);
        abort_unless($payment->company_quotation_id === $quotation->id, 404);

        $data = $this->receiptViewData($quotation, $payment);

        abort_if(! $data['ledgerEntry']?->receipt_number, 404, 'No receipt has been generated for this payment.');

        return view('crm.receipt_printable', $data);
    }

    public function receiptPdf(CompanyQuotation $quotation, Payment $payment)
    {
        Gate::authorize('view', $quotation);
        abort_unless($payment->company_quotation_id === $quotation->id, 404);

        $data = $this->receiptViewData($quotation, $payment);

        abort_if(! $data['ledgerEntry']?->receipt_number, 404, 'No receipt has been generated for this payment.');

        $number = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $data['ledgerEntry']->receipt_number) ?: "receipt-{$payment->id}";

        return Pdf::loadView('crm.receipt_printable', $data)
            ->setPaper('a4')
            ->stream("{$number}.pdf");
    }

    protected function receiptViewData(CompanyQuotation $quotation, Payment $payment): array
    {
        $payment->load('ledgerEntry');
        $quotation->load('lead');

        return [
            'payment' => $payment,
            'ledgerEntry' => $payment->ledgerEntry,
            'quotation' => $quotation,
            'seller' => Organization::provider(),
        ];
    }

    /**
     * Destroy draft quotation.
     */
    public function destroy(CompanyQuotation $quotation)
    {
        Gate::authorize('delete', $quotation);

        $quotation->items()->delete();
        $quotation->delete();

        return back()->with('success', 'Quotation deleted successfully.');
    }
}
