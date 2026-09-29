<?php

namespace App\Services\CRM;

use App\LeadStatus;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as LedgerStates;

/**
 * The commercial life of a deal, stated once.
 *
 * The flow is Quotation → Approval → Payment → Invoice → Won. There is no
 * agreement step: the paths that generated and accepted one are gone, money is
 * recorded against an approved quotation, and the invoice is raised from a
 * settled quotation rather than drawn up by hand.
 *
 * The historical agreement documents are still real and still readable — see
 * `Lead::hasAcceptedAgreement()`, which the Won gate consults so that deals
 * closed under the old flow keep answering truthfully. Nothing here writes
 * them.
 */
class DealCommercialService
{
    /**
     * Where the deal stands on the road to Won.
     *
     * Quotation → Approved → Payment → Invoice → Won. The agreement step that
     * used to sit between Approved and Payment is gone: it described paperwork
     * this product never drew, and money now attaches directly to the approved
     * quotation.
     *
     * One statement of the lifecycle, read by the stage rules, the conversion
     * gate and the page — so the deal cannot be told it is closable in one
     * place and blocked in another.
     *
     * @return array<string, mixed>
     */
    public function lifecycle(Lead $lead, ?Collection $quotations = null, ?LedgerStates $ledgerStates = null): array
    {
        /*
         * The quotations may already be in hand.
         *
         * `DealPageService::commercial()` loads this deal's quotations to render
         * them, then called this method, which loaded the same rows again and
         * reconciled them again — two SELECTs and two grouped payment queries
         * for one set. The caller now passes what it has; nothing else changes,
         * and a caller with nothing still gets the old behaviour.
         */
        $ledger = app(CommercialLedgerService::class);

        $quotations ??= $lead->quotations()->get();

        $sentQuotation = $quotations->first(fn (CompanyQuotation $q) => in_array($this->statusOf($q), [
            QuotationStatus::Sent->value,
            QuotationStatus::Approved->value,
        ], true));

        $approvedQuotation = $quotations->first(fn (CompanyQuotation $q) => $this->statusOf($q) === QuotationStatus::Approved->value);

        /*
         * Any approved quotation settled in full closes the deal — a customer
         * may buy twice, and either purchase should be able to close the deal
         * it belongs to.
         *
         * Reconciled as a set, not one at a time. This was
         * `$quotations->first(fn ($q) => ... $ledger->reconcile($q) ...)`, which
         * asks the `payments` table once per approved quotation until it finds a
         * match — and the deal page runs the same reconciliation again through
         * `Lead::hasApprovedPaidQuotation()`. One grouped query answers for the
         * whole set, so the cost no longer moves with how much a customer has
         * bought.
         */
        $ledgerStates ??= $ledger->reconcileMany($quotations);

        $settled = $quotations->first(fn (CompanyQuotation $q) => $this->statusOf($q) === QuotationStatus::Approved->value
            && ($ledgerStates[$q->id]['state'] ?? null) === CommercialLedgerService::PAID);

        $payment = $lead->payments()->where('status', 'successful')->latest('id')->first();
        $invoice = $settled?->invoice;

        $isWon = ($lead->status instanceof \BackedEnum ? $lead->status->value : (string) $lead->status) === LeadStatus::Won->value;

        return [
            'steps' => [
                [
                    'key' => 'quotation',
                    'label' => 'Quotation',
                    'done' => $sentQuotation !== null,
                    'detail' => $sentQuotation?->quotation_number,
                    'hint' => 'Raise a quotation and send it.',
                ],
                [
                    'key' => 'approved',
                    'label' => 'Approved',
                    'done' => $approvedQuotation !== null,
                    'detail' => $approvedQuotation?->quotation_number,
                    'hint' => 'The customer has to accept the numbers before money is recorded against them.',
                ],
                [
                    'key' => 'payment',
                    'label' => 'Payment',
                    'done' => $payment !== null,
                    'detail' => $payment ? '₹'.number_format((float) $payment->amount) : null,
                    'hint' => 'Record money received against the approved quotation.',
                ],
                [
                    'key' => 'invoice',
                    'label' => 'Invoice',
                    'done' => $invoice !== null,
                    'detail' => $invoice?->invoice_number,
                    'hint' => 'Raised automatically once a quotation is settled in full.',
                ],
                [
                    'key' => 'won',
                    'label' => 'Won',
                    'done' => $isWon,
                    'detail' => null,
                    'hint' => 'Available once a quotation is approved and settled in full.',
                ],
            ],
            'settled_quotation_id' => $settled?->id,
            'invoice_number' => $invoice?->invoice_number,
            'can_close' => $settled !== null,
        ];
    }

    protected function statusOf(CompanyQuotation $quotation): string
    {
        return $quotation->status instanceof \BackedEnum
            ? $quotation->status->value
            : (string) $quotation->status;
    }
}
