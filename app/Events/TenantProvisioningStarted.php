<?php

namespace App\Events;

use App\Models\ProvisioningAudit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TenantProvisioningStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProvisioningAudit $audit
    ) {}
}
