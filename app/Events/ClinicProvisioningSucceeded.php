<?php

namespace App\Events;

use App\Models\ClinicProvisioningRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every provisioning stage for a run has completed.
 */
class ClinicProvisioningSucceeded
{
    use Dispatchable;

    public function __construct(public ClinicProvisioningRun $run) {}
}
