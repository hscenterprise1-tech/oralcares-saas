<?php

namespace App\Services\CRM;

use App\LeadStatus;
use App\Models\CommercialInvoice;
use App\Models\CompanyPackageAddon;
use App\Models\CompanyPlan;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Support\CrmScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Everything a Commercials surface needs, assembled server-side.
 *
 * One builder, because the module has to answer a question no single existing
 * payload could: *what have we billed, and who has paid?* The quotations
 * workspace listed proposals and stopped there — agreements and payments were
 * only ever visible from inside one deal — so a module named Commercials could
 * not show the state of the money.
 *
 * Two things this fixes about how the old payload was shaped:
 *
 *   - **Money travels as numbers.** It used to arrive pre-formatted as
 *     `'₹ 1,234.00'` strings, which the client cannot sort, total or
 *     re-render. `inr()` already exists in the design kit; formatting is the
 *     client's job and the amount is the server's.
 *   - **Permissions are decided here.** Every `can` flag is a real Gate check,
 *     so what a page offers and what the server will accept cannot drift apart.
 *
 * Reconciliation state comes from {@see CommercialLedgerService} and is never
 * recomputed locally — there is one arithmetic for whether a document is
 * settled, and it lives in the ledger.
 */
class CommercialPageService
{
    public function __construct(protected CommercialLedgerService $ledger) {}

    /**
     * The Commercials list: proposals and the agreements they became.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listPayload(?User $user, array $filters = []): array
    {
        $quotations = CrmScope::quotations($user)
            /*
             * `assignedTo` is a CompanyEmployee, not a User — `company_employees`
             * has no name of its own, so the name comes through the employee's
             * user record.
             */
            ->with(['lead:id,company_name,contact_name,status,organization_id', 'items', 'assignedTo.user:id,name'])
            ->latest()
            ->get();

        $rows = $quotations
            ->map(fn (CompanyQuotation $q) => $this->quotationRow($q, $user, $this->settlement($q)))
            ->sortBy([
                fn (array $a, array $b) => $a['urgency'] <=> $b['urgency'],
                /* Oldest first inside a bucket: the one waiting longest leads. */
                fn (array $a, array $b) => strcmp((string) $a['updatedAt'], (string) $b['updatedAt']),
            ])
            ->values();

        $rows = $this->applyFilters($rows, $filters);

        return [
            'rows' => $rows->all(),
            'counts' => $this->counts($rows),
            'money' => $this->money($rows),
            'filters' => [
                'search' => trim((string) ($filters['search'] ?? '')),
                'status' => $filters['status'] ?? null,
            ],
            'can' => [
                'create' => $user ? Gate::forUser($user)->allows('create', CompanyQuotation::class) : false,
            ],
            /*
             * Only deals still in play. Raising a proposal against a deal that
             * is already won or lost is a mistake the picker should not offer
             * in the first place.
             */
            'leads' => $this->pickableLeads($user),
            'statuses' => array_map(fn (QuotationStatus $s) => [
                'value' => $s->value,
                'label' => ucwords(str_replace('_', ' ', $s->value)),
            ], QuotationStatus::cases()),
            'packages' => $this->pickablePackages(),
            'addons' => $this->pickableAddons(),
        ];
    }

    /**
     * The active package catalogue a new quotation may be built from.
     *
     * @return list<array<string, mixed>>
     */
    protected function pickablePackages(): array
    {
        return CompanyPlan::where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'name', 'description', 'price', 'max_users', 'additional_user_price', 'billing_period'])
            ->map(fn (CompanyPlan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => (float) $plan->price,
                'maxUsers' => $plan->max_users,
                'additionalUserPrice' => $plan->additional_user_price !== null ? (float) $plan->additional_user_price : null,
                'billingPeriod' => $plan->billing_period,
            ])
            ->all();
    }

    /**
     * The active add-on catalogue a package may attach.
     *
     * @return list<array<string, mixed>>
     */
    protected function pickableAddons(): array
    {
        return CompanyPackageAddon::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'type', 'price', 'pricing_basis'])
            ->map(fn (CompanyPackageAddon $addon) => [
                'id' => $addon->id,
                'name' => $addon->name,
                'type' => $addon->type,
                'price' => (float) $addon->price,
                'pricingBasis' => $addon->pricing_basis,
            ])
            ->all();
    }

    /**
     * Deals a new quotation could be raised against.
     *
     * Scoped the same way the list is, so the picker cannot offer a deal the
     * user is not allowed to see.
     *
     * @return list<array<string, mixed>>
     */
    protected function pickableLeads(?User $user): array
    {
        return CrmScope::leads($user)
            ->whereNotIn('status', [LeadStatus::Won->value, LeadStatus::Lost->value])
            ->orderBy('company_name')
            ->limit(200)
            ->get(['id', 'company_name', 'contact_name'])
            ->map(fn (Lead $l) => [
                'id' => $l->id,
                'company' => $l->company_name ?: $l->contact_name ?: 'Unnamed',
            ])
            ->all();
    }

    /**
     * One quotation, with whatever it turned into.
     *
     * @param  array<string, mixed>|null  $agreement
     * @return array<string, mixed>
     */
    protected function quotationRow(CompanyQuotation $quotation, ?User $user, ?array $agreement): array
    {
        $status = $this->statusValue($quotation);

        return [
            'id' => $quotation->id,
            'number' => $quotation->quotation_number,
            'company' => $quotation->lead?->company_name ?: $quotation->lead?->contact_name ?: 'Unnamed',
            'leadId' => $quotation->lead_id,
            'status' => $status,
            'statusLabel' => ucwords(str_replace('_', ' ', $status)),
            'urgency' => $this->urgency($status, $agreement),
            'owner' => $quotation->assignedTo?->user?->name,

            /* Numbers, not formatted strings. */
            'subtotal' => (float) $quotation->subtotal,
            'discount' => (float) $quotation->discount,
            'grandTotal' => (float) $quotation->grand_total,
            'lineCount' => $quotation->items->count(),
            'currency' => 'INR',

            /*
             * Sent so the client can disable the control rather than let
             * somebody fill in a form the server will refuse.
             */
            'financiallyLocked' => $quotation->isFinanciallyLocked(),

            'validUntil' => $quotation->valid_until?->toDateString(),
            'expiringSoon' => $this->expiringSoon($quotation),
            'updatedAt' => $quotation->updated_at?->toISOString(),

            /*
             * The agreement is what makes this a commercial record rather than
             * a proposal, and its reconciliation is the answer to "have they
             * paid?" — the question the old list could not reach.
             */
            'settlement' => $agreement,

            'can' => [
                'update' => $user ? Gate::forUser($user)->allows('update', $quotation) : false,
                'approve' => $user ? Gate::forUser($user)->allows('approve', $quotation) : false,
                'recordPayment' => $user ? Gate::forUser($user)->allows('recordPayment', $quotation) : false,
            ],
        ];
    }

    /**
     * How this quotation stands financially.
     *
     * Null until it is approved: an unapproved proposal is not money owed, and
     * showing it a settlement state would invite somebody to chase a payment
     * nobody has agreed to make.
     *
     * @return array<string, mixed>|null
     */
    public function settlement(CompanyQuotation $quotation): ?array
    {
        if ($this->statusValue($quotation) !== QuotationStatus::Approved->value) {
            return null;
        }

        $reconciliation = $this->ledger->reconcile($quotation);
        $invoice = $quotation->invoice;

        return [
            'id' => $quotation->id,
            'number' => $invoice?->invoice_number,
            'type' => 'invoice',
            'status' => $invoice?->status ?? 'not issued',
            'grandTotal' => (float) $quotation->grand_total,
            'reconciliation' => $reconciliation,
            /*
             * Surfaced separately because these two are the states somebody has
             * to act on, and a list that buries them among five is a list that
             * gets skimmed.
             */
            'needsAttention' => in_array(
                $reconciliation['state'],
                [CommercialLedgerService::OVERPAID, CommercialLedgerService::MISMATCH],
                true,
            ),
        ];
    }

    /**
     * The full record for one quotation.
     *
     * @return array<string, mixed>
     */
    public function detailPayload(CompanyQuotation $quotation, ?User $user = null): array
    {
        $quotation->loadMissing(['lead:id,company_name,contact_name,phone,email,status,organization_id', 'items', 'assignedTo.user:id,name']);

        return [
            'quotation' => $this->quotationRow($quotation, $user, $this->settlement($quotation)),
            'terms' => $quotation->terms,
            'paymentTerms' => $quotation->payment_terms,
            'implementationTerms' => $quotation->implementation_terms,
            'supportTerms' => $quotation->support_terms,
            'cancellationTerms' => $quotation->cancellation_terms,
            'notes' => $quotation->notes,
            'rejectionReason' => $quotation->rejection_reason,
            'sentAt' => $quotation->sent_at?->toISOString(),
            'approvedAt' => $quotation->approved_at?->toISOString(),
            'versions' => $this->versions($quotation),
            'history' => $this->history($quotation),
            'items' => $quotation->items->map(fn ($item) => [
                'id' => $item->id,
                'itemType' => $item->item_type ?? 'custom',
                'title' => $item->title,
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (float) $item->unit_price,
                'discount' => (float) ($item->discount ?? 0),
                'total' => (float) $item->total,
            ])->all(),
            'payments' => $this->payments($quotation),
            'gst' => $this->gst($quotation),
            'documents' => $this->documents($quotation, $user),
            'can' => [
                'update' => $user ? Gate::forUser($user)->allows('update', $quotation) : false,
                'approve' => $user ? Gate::forUser($user)->allows('approve', $quotation) : false,
                'recordPayment' => $user ? Gate::forUser($user)->allows('recordPayment', $quotation) : false,
                'delete' => $user ? Gate::forUser($user)->allows('delete', $quotation) : false,
            ],
        ];
    }

    /**
     * The GST breakdown, or null for a quotation that predates it.
     *
     * `hasGstBreakdown()` is the one place that question is answered — a
     * legacy quotation keeps rendering its old flat `tax` figure instead of a
     * fabricated split.
     *
     * @return array<string, mixed>|null
     */
    protected function gst(CompanyQuotation $quotation): ?array
    {
        if (! $quotation->hasGstBreakdown()) {
            return null;
        }

        return [
            'taxableAmount' => (float) $quotation->taxable_amount,
            'rate' => (float) $quotation->gst_rate,
            'type' => $quotation->tax_type,
            'cgst' => (float) $quotation->cgst_amount,
            'sgst' => (float) $quotation->sgst_amount,
            'igst' => (float) $quotation->igst_amount,
        ];
    }

    /**
     * The proforma and tax invoice documents for this quotation, and whether
     * a proforma may still be issued.
     *
     * @return array<string, mixed>
     */
    protected function documents(CompanyQuotation $quotation, ?User $user): array
    {
        $invoice = CommercialInvoice::where('company_quotation_id', $quotation->id)->first();

        return [
            'proforma' => $quotation->proforma_number ? [
                'number' => $quotation->proforma_number,
                'issuedAt' => $quotation->proforma_issued_at?->toISOString(),
                'url' => route('company.crm.quotations.proforma', $quotation),
                'pdfUrl' => route('company.crm.quotations.proforma.pdf', $quotation),
            ] : null,
            /*
             * Mirrors `LeadWorkspaceService::issueProforma()`'s own guard —
             * approved, not yet invoiced, not already issued — so the button
             * never offers something the server would refuse.
             */
            'canIssueProforma' => ($user ? Gate::forUser($user)->allows('update', $quotation) : false)
                && $this->statusValue($quotation) === QuotationStatus::Approved->value
                && ! $quotation->proforma_number
                && ! $invoice,
            'taxInvoice' => $invoice ? [
                'number' => $invoice->invoice_number,
                'url' => route('company.crm.quotations.tax-invoice', $quotation),
                'pdfUrl' => route('company.crm.quotations.tax-invoice.pdf', $quotation),
            ] : null,
        ];
    }

    /**
     * The revision chain this quotation belongs to.
     *
     * A revised quotation is a new row pointing at its parent, so the chain is
     * the commercial history: what was offered first, and what it became. Shown
     * whole rather than as "v3" alone, because the interesting question is
     * usually what changed between them.
     *
     * @return list<array<string, mixed>>
     */
    protected function versions(CompanyQuotation $quotation): array
    {
        $root = $quotation->parent_id
            ? CompanyQuotation::find($quotation->parent_id) ?? $quotation
            : $quotation;

        return CompanyQuotation::query()
            ->where('id', $root->id)
            ->orWhere('parent_id', $root->id)
            ->orderBy('version')
            ->get()
            ->map(fn (CompanyQuotation $q) => [
                'id' => $q->id,
                'number' => $q->quotation_number,
                'version' => (int) $q->version,
                'status' => $this->statusValue($q),
                'grandTotal' => (float) $q->grand_total,
                'isCurrent' => $q->id === $quotation->id,
                'at' => $q->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * What has happened to this quotation.
     *
     * @return list<array<string, mixed>>
     */
    protected function history(CompanyQuotation $quotation): array
    {
        return $quotation->activities()
            ->with('employee.user:id,name')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($activity) => [
                'id' => $activity->id,
                'action' => $activity->event,
                'description' => $activity->description,
                'at' => $activity->created_at?->toISOString(),
                /* Quotation activity is attributed to an employee, not a user. */
                'by' => $activity->employee?->user?->name,
            ])
            ->all();
    }

    /**
     * Money received against a document.
     *
     * Amounts and references only. A payment record is commercial, not
     * personal, and nothing here reaches into a customer's own data.
     *
     * @return list<array<string, mixed>>
     */
    protected function payments(CompanyQuotation $quotation): array
    {
        return Payment::query()
            ->where('company_quotation_id', $quotation->id)
            ->with('ledgerEntry:id,payment_id,receipt_number')
            ->latest('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'status' => $p->status,
                'gateway' => $p->gateway,
                'reference' => $p->transaction_id,
                'at' => $p->created_at?->toISOString(),
                /*
                 * Only a successful payment ever gets a ledger row — see
                 * `CommercialLedgerService::recordPayment()` — so this is
                 * null for the pending/failed rows in the same list, which
                 * is exactly the "never for a failed or pending payment"
                 * rule a receipt has to hold.
                 */
                'receiptNumber' => $p->ledgerEntry?->receipt_number,
                'receiptUrl' => $p->ledgerEntry?->receipt_number
                    ? route('company.crm.quotations.payments.receipt', [$quotation, $p])
                    : null,
            ])
            ->all();
    }

    /**
     * Ordering weight. Lower needs attention sooner.
     *
     * A money discrepancy outranks everything: it is the only state where the
     * record itself is wrong rather than merely unfinished.
     */
    protected function urgency(string $status, ?array $agreement): int
    {
        if ($agreement && $agreement['needsAttention']) {
            return 0;
        }

        return match ($status) {
            'negotiation' => 1,
            'sent' => 2,
            'approved' => 3,
            'draft' => 4,
            'rejected' => 5,
            'expired' => 6,
            default => 7,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function counts(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'draft' => $rows->where('status', 'draft')->count(),
            'sent' => $rows->where('status', 'sent')->count(),
            'approved' => $rows->where('status', 'approved')->count(),
            'expiringSoon' => $rows->where('expiringSoon', true)->count(),
            'needsAttention' => $rows->filter(fn ($r) => ($r['settlement']['needsAttention'] ?? false))->count(),
        ];
    }

    /**
     * Portfolio totals, as numbers.
     *
     * `settled` counts only money actually received, so it can be smaller than
     * `agreed` — and saying so is the point of having the figure at all.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    protected function money(Collection $rows): array
    {
        $settlements = $rows->pluck('settlement')->filter();

        return [
            'proposed' => round((float) $rows->sum('grandTotal'), 2),
            'approved' => round((float) $rows->where('status', 'approved')->sum('grandTotal'), 2),
            'agreed' => round((float) $settlements->sum('grandTotal'), 2),
            'settled' => round((float) $settlements->sum(fn ($s) => $s['reconciliation']['settled']), 2),
            'outstanding' => round((float) $settlements->sum(fn ($s) => max(0, $s['reconciliation']['balance'])), 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    protected function applyFilters(Collection $rows, array $filters): Collection
    {
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        if ($search !== '') {
            $rows = $rows->filter(fn (array $r) => str_contains(
                strtolower($r['company'].' '.$r['number'].' '.($r['owner'] ?? '')),
                $search,
            ))->values();
        }

        if (! empty($filters['status'])) {
            $rows = $rows->where('status', $filters['status'])->values();
        }

        return $rows;
    }

    protected function expiringSoon(CompanyQuotation $quotation): bool
    {
        return $quotation->valid_until
            && $quotation->valid_until->isFuture()
            && now()->diffInDays($quotation->valid_until) <= 7;
    }

    protected function statusValue(CompanyQuotation $quotation): string
    {
        return $quotation->status instanceof QuotationStatus
            ? $quotation->status->value
            : (string) $quotation->status;
    }
}
