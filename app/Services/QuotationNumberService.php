<?php

namespace App\Services;

use App\Models\CompanyQuotation;

class QuotationNumberService
{
    /**
     * Generate the next unique quotation number.
     */
    public function generateNextNumber(): string
    {
        $year = now()->year;
        $prefix = "QT-{$year}-";

        // Find the latest quotation number for the current year
        $latest = CompanyQuotation::where('quotation_number', 'like', "{$prefix}%")
            ->orderBy('quotation_number', 'desc')
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
