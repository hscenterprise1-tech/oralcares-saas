<?php

namespace App\Events;

use App\Models\Clinic;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantProvisioningCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Clinic $clinic
    ) {}
}
