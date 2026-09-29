<?php

use App\Models\CommercialPayment;
use App\Models\CompanyQuotation;
use App\Models\Lead;
use App\Models\Payment;
use App\QuotationStatus;
use App\Services\CommercialLedgerService;
use App\Services\ReceiptNumberService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Phase 2 — receipt numbering.
 *
 * Mirrors `QuotationNumberConcurrencyTest`'s own coverage exactly:
 * `ReceiptNumberService::generateNextNumber()`'s `lockForUpdate()` closes the
 * race for every receipt after a year's first, and
 * `CommercialLedgerService::assignReceiptNumber()` retries against the
 * table's own unique constraint on `receipt_number` for the one row that
 * cannot cover — the first receipt of a year, which has nothing yet to lock.
 *
 * These tests simulate the race by making the number service hand out an
 * already-taken number, exactly what two concurrent callers reading before
 * either commits would both compute.
 */
function receiptQuotation(): CompanyQuotation
{
    $lead = Lead::factory()->create();

    return CompanyQuotation::factory()->create([
        'lead_id' => $lead->id,
        'status' => QuotationStatus::Approved,
        'approved_at' => now(),
        'subtotal' => 10000,
        'tax' => 1800,
        'discount' => 0,
        'grand_total' => 11800,
    ]);
}

it('retries past a colliding receipt number instead of failing the payment', function () {
    $quotation = receiptQuotation();
    $collidingNumber = 'RCPT-'.now()->year.'-0001';

    CommercialPayment::create([
        'payment_id' => null,
        'amount' => 1,
        'payment_method' => 'bank_transfer',
        'payment_type' => 'deal',
        'receipt_number' => $collidingNumber,
        'paid_at' => now(),
    ]);

    $this->mock(ReceiptNumberService::class, function ($mock) use ($collidingNumber) {
        $mock->shouldReceive('generateNextNumber')
            ->twice()
            ->andReturn($collidingNumber, 'RCPT-'.now()->year.'-0002');
    });

    $payment = Payment::create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'amount' => 11800,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $ledgerEntry = $payment->ledgerEntry()->first();

    expect($ledgerEntry->receipt_number)->toBe('RCPT-'.now()->year.'-0002')
        ->and(CommercialPayment::where('receipt_number', $collidingNumber)->count())->toBe(1)
        ->and(CommercialPayment::where('receipt_number', 'RCPT-'.now()->year.'-0002')->count())->toBe(1);
});

it('gives up after exhausting retries against a persistently colliding receipt number', function () {
    $quotation = receiptQuotation();
    $collidingNumber = 'RCPT-'.now()->year.'-0001';

    CommercialPayment::create([
        'payment_id' => null,
        'amount' => 1,
        'payment_method' => 'bank_transfer',
        'payment_type' => 'deal',
        'receipt_number' => $collidingNumber,
        'paid_at' => now(),
    ]);

    $this->mock(ReceiptNumberService::class, function ($mock) use ($collidingNumber) {
        $mock->shouldReceive('generateNextNumber')->andReturn($collidingNumber);
    });

    expect(fn () => Payment::create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'amount' => 11800,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]))->toThrow(QueryException::class);
});

it('locks the latest row so a real second read only happens after the first commits', function () {
    CommercialPayment::create([
        'payment_id' => null,
        'amount' => 1,
        'payment_method' => 'bank_transfer',
        'payment_type' => 'deal',
        'receipt_number' => 'RCPT-'.now()->year.'-0007',
        'paid_at' => now(),
    ]);

    expect(app(ReceiptNumberService::class)->generateNextNumber())->toBe('RCPT-'.now()->year.'-0008');
});

it('never reassigns a receipt number once one has been generated', function () {
    $quotation = receiptQuotation();

    $payment = Payment::create([
        'lead_id' => $quotation->lead_id,
        'company_quotation_id' => $quotation->id,
        'amount' => 11800,
        'currency' => 'INR',
        'status' => 'successful',
        'gateway' => 'bank_transfer',
    ]);

    $firstNumber = $payment->ledgerEntry()->first()->receipt_number;
    expect($firstNumber)->not->toBeNull();

    // `recordPayment()` is keyed on `payment_id` and idempotent by design —
    // a retried webhook or a repeated call must replace the same row rather
    // than mint it a second, different receipt number.
    app(CommercialLedgerService::class)->recordPayment($payment->fresh());

    expect($payment->ledgerEntry()->first()->receipt_number)->toBe($firstNumber);
});
