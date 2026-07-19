<?php

namespace App\Events;

use App\Models\PatientBilling;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BillCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PatientBilling $billing
    ) {}
}
