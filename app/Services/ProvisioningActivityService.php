<?php

namespace App\Services;

use App\Models\CompanyProvisioningActivity;
use App\Models\CompanyProvisioningProcess;

class ProvisioningActivityService
{
    /**
     * Log a provisioning activity event.
     */
    public function log(CompanyProvisioningProcess $process, string $event, ?string $description = null): CompanyProvisioningActivity
    {
        return CompanyProvisioningActivity::create([
            'company_provisioning_process_id' => $process->id,
            'event' => $event,
            'description' => $description,
        ]);
    }
}
