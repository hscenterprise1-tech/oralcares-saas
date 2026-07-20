<?php

namespace App\Events;

use App\Models\CommercialInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommercialInvoicePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CommercialInvoice $invoice
    ) {}
}
