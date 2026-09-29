<?php

namespace App\Services;

use App\Models\CompanyQuotation;

class QuotationNumberService
{
    /**
     * Generate the next unique quotation number.
     *
     * Must be called from inside a transaction — `lockForUpdate()` only
     * serializes concurrent callers for as long as that transaction holds
     * the row lock. `createQuotation()` already wraps this call in one.
     *
     * This closes the race for every year after its first quotation: two
     * concurrent callers both reading the latest row now block on each
     * other, so the second sees the first's committed number before
     * computing its own. It does not by itself cover the one row that does
     * not exist yet — the very first quotation of a prefix has nothing to
     * lock — so `createQuotation()` pairs this with a retry against the
     * table's own unique constraint on `[quotation_number, version]` as the
     * final safety net for that narrower case, rather than adding a second
     * locking mechanism here.
     */
    public function generateNextNumber(): string
    {
        $year = now()->year;
        $prefix = "QT-{$year}-";

        $latest = CompanyQuotation::where('quotation_number', 'like', "{$prefix}%")
            ->orderBy('quotation_number', 'desc')
            ->lockForUpdate()
            ->first();

        if (! $latest) {
            return "{$prefix}0001";
        }

        $parts = explode('-', $latest->quotation_number);
        $sequence = isset($parts[2]) ? (int) $parts[2] : 0;
        $nextSequence = $sequence + 1;

        return $prefix.str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT);
    }
}
