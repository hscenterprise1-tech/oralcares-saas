<?php

namespace App\Events;

use App\Models\PatientBillingRefund;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RefundInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PatientBillingRefund $refund
    ) {}
}
