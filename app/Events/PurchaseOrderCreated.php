<?php

namespace App\Events;

use App\Models\InventoryPurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public InventoryPurchaseOrder $purchaseOrder
    ) {}
}
