<?php

namespace App\Events;

use App\Models\CommercialInvoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommercialInvoiceGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CommercialInvoice $invoice
    ) {}
}
