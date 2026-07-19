<?php

namespace App\Services;

class BillableItemService
{
    /**
     * Calculate the final amount for a billable item.
     */
    public function calculateItemFinalAmount(int $quantity, float $unitPrice, float $discountAmount): float
    {
        $subtotal = $unitPrice * $quantity;

        return max(0.00, $subtotal - $discountAmount);
    }
}
