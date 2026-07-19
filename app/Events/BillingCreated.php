<?php

namespace App\Events;

use App\Models\PatientBilling;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillingCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PatientBilling $billing
    ) {}
}
