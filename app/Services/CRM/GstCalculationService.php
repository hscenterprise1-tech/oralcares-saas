<?php

namespace App\Services\CRM;

/**
 * The one place GST is computed.
 *
 * India's GST splits one tax figure into two different shapes depending on
 * where the two parties sit: CGST+SGST when the seller and the customer are
 * in the same state, IGST when they are not. Getting this wrong is not a
 * rounding error, it is the wrong tax law applied to the transaction — so
 * this is the only class allowed to decide it, and every other surface
 * (the quotation service, the PDF, the printable view, the tax invoice)
 * reads the numbers this produces rather than re-deriving them.
 *
 * Deliberately conservative about the one judgement call it makes: if either
 * party's state is unknown, it will not guess which split applies. The total
 * tax still computes correctly — the rate is not in question — only the
 * CGST/SGST-vs-IGST breakdown is withheld until both states are on record.
 */
class GstCalculationService
{
    /**
     * @return array{
     *     taxable_amount: float,
     *     gst_rate: float,
     *     tax_type: ?string,
     *     cgst_amount: float,
     *     sgst_amount: float,
     *     igst_amount: float,
     *     total_tax: float,
     * }
     */
    public function calculate(
        float $taxableAmount,
        ?string $sellerState,
        ?string $customerState,
        float $gstRate,
    ): array {
        $taxableAmount = max(0.0, round($taxableAmount, 2));
        $totalTax = round($taxableAmount * $gstRate / 100, 2);

        /*
         * No tax, no type. A 0%-rated quotation has nothing to categorise as
         * CGST/SGST or IGST even when both states are known — there is no
         * tax line for the type to describe.
         */
        $taxType = $totalTax > 0.0 ? $this->resolveTaxType($sellerState, $customerState) : null;

        if ($taxType === null || $totalTax <= 0.0) {
            return [
                'taxable_amount' => $taxableAmount,
                'gst_rate' => $gstRate,
                'tax_type' => $taxType,
                'cgst_amount' => 0.0,
                'sgst_amount' => 0.0,
                'igst_amount' => 0.0,
                'total_tax' => $totalTax,
            ];
        }

        if ($taxType === 'igst') {
            return [
                'taxable_amount' => $taxableAmount,
                'gst_rate' => $gstRate,
                'tax_type' => 'igst',
                'cgst_amount' => 0.0,
                'sgst_amount' => 0.0,
                'igst_amount' => $totalTax,
                'total_tax' => $totalTax,
            ];
        }

        /*
         * CGST and SGST split the same total exactly in half. A total with
         * an odd paisa (e.g. ₹0.01) cannot split evenly, so the remainder is
         * assigned to SGST rather than left unaccounted for — CGST is always
         * the rounded-down half, SGST is whatever makes the two sum back to
         * the total.
         */
        $cgst = round($totalTax / 2, 2);
        $sgst = round($totalTax - $cgst, 2);

        return [
            'taxable_amount' => $taxableAmount,
            'gst_rate' => $gstRate,
            'tax_type' => 'cgst_sgst',
            'cgst_amount' => $cgst,
            'sgst_amount' => $sgst,
            'igst_amount' => 0.0,
            'total_tax' => $totalTax,
        ];
    }

    /**
     * 'cgst_sgst' | 'igst' | null — null when either state is missing, which
     * is a fact to render honestly, never a reason to default to one or the
     * other.
     */
    protected function resolveTaxType(?string $sellerState, ?string $customerState): ?string
    {
        $seller = $this->normalizeState($sellerState);
        $customer = $this->normalizeState($customerState);

        if ($seller === null || $customer === null) {
            return null;
        }

        return $seller === $customer ? 'cgst_sgst' : 'igst';
    }

    protected function normalizeState(?string $state): ?string
    {
        $state = trim((string) $state);

        return $state === '' ? null : mb_strtolower($state);
    }
}
