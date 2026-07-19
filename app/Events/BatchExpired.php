<?php

namespace App\Events;

use App\Models\InventoryBatch;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BatchExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public InventoryBatch $batch
    ) {}
}
