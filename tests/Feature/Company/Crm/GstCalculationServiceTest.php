<?php

use App\Services\CRM\GstCalculationService;

/**
 * Phase 2 — the single canonical GST calculation.
 *
 * Every other surface (quotation totals, the PDF, the printable view, the
 * tax invoice) reads what this class produces rather than re-deriving it, so
 * every rule the CGST/SGST/IGST split depends on is pinned here.
 */
beforeEach(function () {
    $this->gst = new GstCalculationService;
});

it('splits into CGST and SGST when seller and customer share a state', function () {
    $result = $this->gst->calculate(100000.00, 'Maharashtra', 'Maharashtra', 18.00);

    expect($result['tax_type'])->toBe('cgst_sgst')
        ->and($result['taxable_amount'])->toBe(100000.00)
        ->and($result['gst_rate'])->toBe(18.00)
        ->and($result['cgst_amount'])->toBe(9000.00)
        ->and($result['sgst_amount'])->toBe(9000.00)
        ->and($result['igst_amount'])->toBe(0.0)
        ->and($result['total_tax'])->toBe(18000.00);
});

it('is case- and whitespace-insensitive when comparing states', function () {
    $result = $this->gst->calculate(1000.00, '  Maharashtra ', 'MAHARASHTRA', 18.00);

    expect($result['tax_type'])->toBe('cgst_sgst');
});

it('charges IGST in full when seller and customer are in different states', function () {
    $result = $this->gst->calculate(100000.00, 'Maharashtra', 'Karnataka', 18.00);

    expect($result['tax_type'])->toBe('igst')
        ->and($result['igst_amount'])->toBe(18000.00)
        ->and($result['cgst_amount'])->toBe(0.0)
        ->and($result['sgst_amount'])->toBe(0.0)
        ->and($result['total_tax'])->toBe(18000.00);
});

it('computes the correct total tax but withholds the split when a state is unknown', function () {
    $sellerUnknown = $this->gst->calculate(50000.00, null, 'Karnataka', 18.00);
    $customerUnknown = $this->gst->calculate(50000.00, 'Maharashtra', null, 18.00);
    $bothUnknown = $this->gst->calculate(50000.00, null, null, 18.00);

    foreach ([$sellerUnknown, $customerUnknown, $bothUnknown] as $result) {
        expect($result['tax_type'])->toBeNull()
            ->and($result['cgst_amount'])->toBe(0.0)
            ->and($result['sgst_amount'])->toBe(0.0)
            ->and($result['igst_amount'])->toBe(0.0)
            // The rate is not in question even when the split is unknown —
            // only which boxes the tax goes into is undetermined.
            ->and($result['total_tax'])->toBe(9000.00);
    }
});

it('produces zero tax on a zero-rated quotation without guessing a split', function () {
    $result = $this->gst->calculate(5000.00, 'Maharashtra', 'Maharashtra', 0.00);

    expect($result['total_tax'])->toBe(0.0)
        ->and($result['tax_type'])->toBeNull()
        ->and($result['cgst_amount'])->toBe(0.0)
        ->and($result['sgst_amount'])->toBe(0.0);
});

it('never produces a negative taxable amount', function () {
    $result = $this->gst->calculate(-500.00, 'Maharashtra', 'Maharashtra', 18.00);

    expect($result['taxable_amount'])->toBe(0.0)
        ->and($result['total_tax'])->toBe(0.0);
});

it('rounds an odd-paisa CGST/SGST split without losing or inventing a paisa', function () {
    // 18% of 100.01 = 18.0018, rounds to 18.00 total — an amount chosen
    // specifically to test the case where rounding could drift.
    $result = $this->gst->calculate(33.33, 'Maharashtra', 'Maharashtra', 18.00);

    expect($result['cgst_amount'] + $result['sgst_amount'])->toEqualWithDelta($result['total_tax'], 0.001);
});

it('splits a genuinely odd-paisa total tax into two amounts that add back to it exactly', function () {
    // 18% of 100.03 = 18.0054 -> rounds to 18.01, an odd-paisa total that
    // cannot split evenly in half.
    $result = $this->gst->calculate(100.03, 'Maharashtra', 'Maharashtra', 18.00);

    expect($result['total_tax'])->toBe(18.01)
        ->and(round($result['cgst_amount'] + $result['sgst_amount'], 2))->toBe(18.01)
        ->and(abs($result['cgst_amount'] - $result['sgst_amount']))->toBeLessThanOrEqual(0.01);
});
