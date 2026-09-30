<?php

namespace App\Services;

use App\Models\CommercialPayment;

class ReceiptNumberService
{
    /**
     * Generate the next unique receipt number.
     *
     * Mirrors `QuotationNumberService::generateNextNumber()` exactly: must be
     * called from inside a transaction so `lockForUpdate()` serializes
     * concurrent callers against the latest existing row for the year's
     * prefix. The caller pairs this with a retry against the table's own
     * unique constraint on `receipt_number` for the one row this cannot
     * cover — the very first receipt of a year, which has nothing yet to
     * lock.
     */
    public function generateNextNumber(): string
    {
        $year = now()->year;
        $prefix = "RCPT-{$year}-";

        $latest = CommercialPayment::where('receipt_number', 'like', "{$prefix}%")
            ->orderBy('receipt_number', 'desc')
            ->lockForUpdate()
            ->first();

        if (! $latest) {
            return "{$prefix}0001";
        }

        $parts = explode('-', $latest->receipt_number);
        $sequence = isset($parts[2]) ? (int) $parts[2] : 0;
        $nextSequence = $sequence + 1;

        return $prefix.str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }
}
