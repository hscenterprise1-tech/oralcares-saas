<?php

namespace App\Services;

class DiscountService
{
    /**
     * Calculate bill-level discount based on amount or percentage.
     */
    public function calculateBillLevelDiscount(float $totalAmount, float $discountValue, string $discountType): float
    {
        if ($discountType === 'percentage') {
            return round(($totalAmount * ($discountValue / 100)), 2);
        }

        return round($discountValue, 2);
    }
}
