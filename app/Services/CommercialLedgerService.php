<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\CommercialDocument;
use App\Models\CommercialInvoice;
use App\Models\CommercialPayment;
use App\Models\CompanyQuotation;
use App\Models\Organization;
use App\Models\Payment;
use App\QuotationStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single financial ledger.
 *
 * The audit found the same commercial event represented twice with no link
 * between the two:
 *
 *   CRM      Lead → CommercialDocument(type=invoice) → Payment
 *   Finance  CompanySubscription → CommercialInvoice → CommercialPayment
 *
 * Money booked against a deal therefore never reached Finance. Rather than
 * removing either side mid-flight, this service makes Finance the ledger and
 * projects every CRM commercial event into it, keyed on the originating
 * record so the projection is idempotent.
 *
 * Ownership after this change:
 *
 *   CRM      owns the deal, the quotation and the agreement
 *   Finance  owns invoices, payments and the money record
 *
 * The CRM Documents page keeps working and keeps writing its own tables; those
 * writes are now mirrored rather than lost. Retiring that page is an IA
 * concern for a later phase, and nothing here depends on when that happens.
 */
class CommercialLedgerService
{
    /**
     * Document types that were once treated as money owed.
     *
     * Legacy. The commercial flow is Quotation → Approval → Payment → Invoice →
     * Won and has no agreement step, so nothing creates these any more. The
     * constant survives because historical rows still carry the type and are
     * still read — one live payment of ₹5.4L points at an agreement, and it is
     * left exactly where it is.
     *
     * @var list<string>
     */
    public const LEGACY_BILLABLE_TYPES = ['agreement', 'invoice'];

    /** Nothing has been paid against the document. */
    public const UNPAID = 'unpaid';

    /** Some money has arrived, but not all of it. */
    public const PARTIALLY_PAID = 'partially_paid';

    /** Settled exactly. */
    public const PAID = 'paid';

    /** More money arrived than the document asks for. */
    public const OVERPAID = 'overpaid';

    /** The document's own arithmetic does not hold; its total cannot be trusted. */
    public const MISMATCH = 'mismatch';

    /**
     * Rounding tolerance, in rupees.
     *
     * Totals are stored as `decimal:2` and arrive here as strings. Comparing
     * them exactly would report a one-paisa rounding artefact as an underpaid
     * invoice, which is noise a finance team would learn to ignore — and an
     * alert people ignore is worse than no alert.
     */
    public const TOLERANCE = 0.01;

    /**
     * How a legacy commercial document's status once mapped onto an invoice.
     *
     * No longer read. An invoice is raised from a settled quotation, not
     * inferred from a document's status. Kept as the record of how the
     * historical rows in `commercial_invoices` came to have the statuses they
     * have, since nothing else explains them.
     *
     * @var array<string, string>
     */
    protected const LEGACY_DOCUMENT_STATUS_MAP = [
        'sent' => 'issued',
        'viewed' => 'issued',
        'accepted' => 'issued',
        'paid' => 'paid',
        'expired' => 'overdue',
        'rejected' => 'cancelled',
        'archived' => 'cancelled',
    ];

    /**
     * Project a CRM invoice document into the Finance ledger.
     *
     * Returns null for documents that are not invoices, or that have not yet
     * been issued. Safe to call repeatedly: the row is keyed on
     * `commercial_document_id`.
     */
    public function generateInvoice(CompanyQuotation $quotation): ?CommercialInvoice
    {
        /*
         * The invoice follows the money, not the other way round.
         *
         * It is raised once an approved quotation has been settled in full —
         * which is why a partly paid quotation has no invoice yet, and why an
         * overpaid or internally inconsistent one has none either. Both of
         * those are states a person has to resolve, and issuing paper against
         * them would put a wrong number on a document a customer keeps.
         */
        if ($this->statusOf($quotation) !== QuotationStatus::Approved->value) {
            return null;
        }

        $reconciliation = $this->reconcile($quotation);

        if ($reconciliation['state'] !== self::PAID) {
            return null;
        }

        return DB::transaction(function () use ($quotation) {
            $seller = Organization::provider();

            /*
             * A tax invoice snapshots the GST facts at the moment it is
             * raised — the quotation already carries the canonical
             * calculation (`GstCalculationService`, applied once at creation
             * and on every discount change), so this copies it rather than
             * recomputing it a second time from the seller/buyer state.
             */
            return CommercialInvoice::updateOrCreate(
                /* Keyed on the quotation, so a re-run replaces rather than duplicates. */
                ['company_quotation_id' => $quotation->id],
                [
                    'clinic_id' => $this->resolveClinicIdForQuotation($quotation),
                    'invoice_number' => $this->invoiceNumberFor($quotation),
                    'status' => 'paid',
                    'amount' => $quotation->subtotal,
                    'tax_amount' => $quotation->tax,
                    'discount_amount' => $quotation->discount,
                    'final_amount' => $quotation->grand_total,
                    'seller_gstin' => $seller?->gst,
                    'seller_state' => $seller?->state,
                    'buyer_gstin' => $quotation->lead?->gstin,
                    'buyer_state' => $quotation->lead?->state,
                    'taxable_amount' => $quotation->taxable_amount,
                    'gst_rate' => $quotation->gst_rate,
                    'tax_type' => $quotation->tax_type,
                    /*
                     * These three are NOT NULL with a database default of 0 —
                     * a quotation built in memory and never re-fetched (e.g.
                     * straight from a factory that never touched them) has
                     * them unset rather than hydrated from that default, so
                     * this falls back to the same 0 explicitly.
                     */
                    'cgst_amount' => $quotation->cgst_amount ?? 0,
                    'sgst_amount' => $quotation->sgst_amount ?? 0,
                    'igst_amount' => $quotation->igst_amount ?? 0,
                    'due_date' => $quotation->valid_until ?? now(),
                    'issued_at' => now(),
                    'paid_at' => now(),
                ]
            );
        });
    }

    /**
     * Project a CRM payment into the Finance ledger.
     *
     * Only settled money is booked — a pending or failed gateway attempt is
     * not a payment. Keyed on `payment_id`, so a retried webhook or a repeated
     * save cannot double-count revenue.
     */
    public function recordPayment(Payment $payment): ?CommercialPayment
    {
        if ($payment->status !== 'successful') {
            return null;
        }

        return DB::transaction(function () use ($payment) {
            $quotation = $payment->company_quotation_id
                ? CompanyQuotation::find($payment->company_quotation_id)
                : null;

            /*
             * A payment against a quotation raises the invoice the moment the
             * quotation is fully settled. A payment carrying only the legacy
             * document link — the ₹5.4L row — books into the ledger with no
             * invoice, which is the truth about it.
             */
            $invoice = $quotation ? $this->generateInvoice($quotation) : null;

            $commercialPayment = CommercialPayment::updateOrCreate(
                ['payment_id' => $payment->id],
                [
                    'clinic_id' => $invoice?->clinic_id ?? ($quotation ? $this->resolveClinicIdForQuotation($quotation) : null),
                    'commercial_invoice_id' => $invoice?->id,
                    'amount' => $payment->amount,
                    'payment_method' => $payment->gateway ?? 'unknown',
                    'payment_type' => 'deal',
                    'transaction_reference' => $payment->transaction_id,
                    'paid_at' => $payment->created_at ?? now(),
                ]
            );

            /*
             * A receipt is proof this specific payment succeeded, so it gets
             * its own number the first time this row is created — never
             * reassigned on a later re-run, which is why this only fires
             * when the column is still empty.
             */
            if ($commercialPayment->receipt_number === null) {
                $commercialPayment->receipt_number = $this->assignReceiptNumber($commercialPayment);
            }

            return $commercialPayment;
        });
    }

    /**
     * Allocate a unique receipt number for a settled payment.
     *
     * Same shape as `LeadWorkspaceService::saveQuotationWithUniqueNumber()`:
     * `ReceiptNumberService::generateNextNumber()`'s `lockForUpdate()` closes
     * the race for every receipt after a year's first, and this retries
     * against the table's own unique constraint on `receipt_number` for that
     * one remaining row, which has nothing yet to lock.
     */
    protected function assignReceiptNumber(CommercialPayment $commercialPayment): string
    {
        $numberService = app(ReceiptNumberService::class);
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $receiptNumber = $numberService->generateNextNumber();

            try {
                $commercialPayment->update(['receipt_number' => $receiptNumber]);

                return $receiptNumber;
            } catch (QueryException $e) {
                $isDuplicate = (string) $e->getCode() === '23000'
                    && str_contains($e->getMessage(), 'receipt_number');

                if (! $isDuplicate || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Could not allocate a unique receipt number.');
    }

    /**
     * Backfill the tenant on ledger rows raised before the customer existed.
     *
     * A deal is invoiced while it is still a lead, so its ledger rows carry a
     * null `clinic_id` until provisioning creates the tenant. Called from
     * CustomerProvisioningEngine so the ledger becomes tenant-attributable the
     * moment the customer exists.
     */
    public function attachTenant(Organization $organization, Clinic $clinic): int
    {
        /*
         * Both keys, because the ledger now holds two generations of row: the
         * quotation-keyed invoices this flow raises, and the document-keyed
         * ones raised before it existed. Backfilling only one would leave the
         * other permanently unattributable to the customer.
         */
        $documentIds = CommercialDocument::query()
            ->whereHas('lead', fn ($q) => $q->where('customer_organization_id', $organization->id))
            ->pluck('id');

        $quotationIds = CompanyQuotation::query()
            ->whereHas('lead', fn ($q) => $q->where('customer_organization_id', $organization->id))
            ->pluck('id');

        if ($documentIds->isEmpty() && $quotationIds->isEmpty()) {
            return 0;
        }

        $scope = fn ($query) => $query->where(function ($q) use ($documentIds, $quotationIds) {
            $q->whereIn('commercial_document_id', $documentIds)
                ->orWhereIn('company_quotation_id', $quotationIds);
        });

        $invoiceIds = $scope(CommercialInvoice::query())->pluck('id');

        $updated = $scope(CommercialInvoice::query())
            ->whereNull('clinic_id')
            ->update(['clinic_id' => $clinic->id]);

        CommercialPayment::whereIn('commercial_invoice_id', $invoiceIds)
            ->whereNull('clinic_id')
            ->update(['clinic_id' => $clinic->id]);

        return $updated;
    }

    /**
     * What this document is owed, what has arrived, and whether they agree.
     *
     * Kept separate from the invoice's own `status` on purpose. The status is
     * the CRM's assertion about the document; this is the arithmetic. They can
     * disagree, and when they do that disagreement is the most useful thing on
     * the record — the live data has a ₹5.4L payment sitting against a ₹4,999
     * agreement, and nothing in the product noticed.
     *
     * Only settled payments count. A pending gateway attempt is not money.
     *
     * @return array{state: string, expected: float, settled: float, balance: float, payments: int}
     */
    /**
     * Reconcile every quotation on a deal in one query.
     *
     * `reconcile()` asks the `payments` table once per quotation. That is fine
     * for a single document and wrong everywhere it is called in a loop — the
     * deal page reconciled the same set two or three times per render, once in
     * `DealCommercialService::lifecycle()` and again through
     * `Lead::hasApprovedPaidQuotation()`, at a query per approved quotation
     * each time.
     *
     * This sums the settled payments for the whole set in a single grouped
     * query and derives each state from the result, so reconciliation costs one
     * query no matter how many quotations a deal carries.
     *
     * Quotations with no payments are present in the result with a zero
     * settlement rather than absent — "nobody has paid" is an answer, and a
     * caller that had to distinguish it from "not reconciled" would be doing
     * this class's job.
     *
     * @param  Collection<int, CompanyQuotation>  $quotations
     * @return Collection<int, array{state: string, expected: float, settled: float, balance: float, payments: int}>
     *                                                                                                               Keyed by quotation id.
     */
    public function reconcileMany(Collection $quotations): Collection
    {
        if ($quotations->isEmpty()) {
            return collect();
        }

        $totals = Payment::query()
            ->whereIn('company_quotation_id', $quotations->pluck('id'))
            ->where('status', 'successful')
            ->selectRaw('company_quotation_id, coalesce(sum(amount), 0) as settled_total, count(*) as payment_count')
            ->groupBy('company_quotation_id')
            ->get()
            ->keyBy('company_quotation_id');

        return $quotations->mapWithKeys(function (CompanyQuotation $quotation) use ($totals) {
            $row = $totals->get($quotation->id);
            $expected = (float) $quotation->grand_total;
            $settled = (float) ($row->settled_total ?? 0);

            return [$quotation->id => [
                'state' => $this->reconciliationState($quotation, $expected, $settled),
                'expected' => round($expected, 2),
                'settled' => round($settled, 2),
                'balance' => round($expected - $settled, 2),
                'payments' => (int) ($row->payment_count ?? 0),
            ]];
        });
    }

    public function reconcile(CompanyQuotation $quotation): array
    {
        $expected = (float) $quotation->grand_total;

        $payments = Payment::query()
            ->where('company_quotation_id', $quotation->id)
            ->where('status', 'successful')
            ->get();

        $settled = (float) $payments->sum('amount');

        return [
            'state' => $this->reconciliationState($quotation, $expected, $settled),
            'expected' => round($expected, 2),
            'settled' => round($settled, 2),
            'balance' => round($expected - $settled, 2),
            'payments' => $payments->count(),
        ];
    }

    /**
     * Which of the five states this document is in.
     *
     * `mismatch` outranks everything, because it means the document's own total
     * is wrong — and once the expected figure cannot be trusted, calling the
     * same document "partially paid" would be arithmetic on a number nobody
     * should be relying on.
     */
    protected function reconciliationState(CompanyQuotation $quotation, float $expected, float $settled): string
    {
        $lineTotal = (float) $quotation->subtotal + (float) $quotation->tax - (float) $quotation->discount;

        if (abs($lineTotal - $expected) > self::TOLERANCE) {
            return self::MISMATCH;
        }

        if ($settled <= self::TOLERANCE) {
            return self::UNPAID;
        }

        if ($settled > $expected + self::TOLERANCE) {
            return self::OVERPAID;
        }

        if ($settled >= $expected - self::TOLERANCE) {
            return self::PAID;
        }

        return self::PARTIALLY_PAID;
    }

    /**
     * The tenant a quotation's money belongs to, if one exists yet.
     *
     * A deal is quoted while it is still a lead, so this is null until
     * provisioning creates the customer — which is what `attachTenant()` then
     * backfills.
     */
    protected function resolveClinicIdForQuotation(CompanyQuotation $quotation): ?int
    {
        if ($quotation->clinic_id) {
            return (int) $quotation->clinic_id;
        }

        $organization = $quotation->lead?->customerOrganization;

        if (! $organization) {
            return null;
        }

        return Clinic::where('organization_id', $organization->id)->value('id');
    }

    /**
     * The invoice's own number.
     *
     * Derived from the quotation it settles, so the two are traceable to each
     * other by sight on a printed page rather than only through a foreign key.
     */
    protected function invoiceNumberFor(CompanyQuotation $quotation): string
    {
        /*
         * `ltrim($number, 'QT-')` strips any of the characters Q, T, - from
         * either end — accidentally safe today only because no quotation
         * number starts with another Q/T/- run, but fragile. `Str::after()`
         * removes the literal prefix once, which is what was actually meant.
         */
        return 'INV-'.Str::after((string) $quotation->quotation_number, 'QT-');
    }

    protected function statusOf(CompanyQuotation $quotation): string
    {
        return $quotation->status instanceof QuotationStatus
            ? $quotation->status->value
            : (string) $quotation->status;
    }

    /**
     * The tenant a CRM document's money belongs to, if one exists yet.
     */
    protected function resolveClinicId(CommercialDocument $document): ?int
    {
        $organization = $document->lead?->customerOrganization;

        if (! $organization) {
            return null;
        }

        return Clinic::where('organization_id', $organization->id)->value('id');
    }
}
