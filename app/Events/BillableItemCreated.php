<?php

namespace App\Events;

use App\Models\BillingReference;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillableItemCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BillingReference $billingReference
    ) {}
}
