<?php

namespace App\Events;

use App\Models\InventoryTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockAdjusted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public InventoryTransaction $transaction
    ) {}
}
