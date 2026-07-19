<?php

namespace App\Events;

use App\Models\PatientBillingPayment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PatientBillingPayment $payment
    ) {}
}
