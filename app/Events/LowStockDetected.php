<?php

namespace App\Events;

use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public InventoryItem $item,
        public Branch $branch,
        public float $currentQuantity
    ) {}
}
