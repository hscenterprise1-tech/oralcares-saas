<?php

namespace App\Events;

use App\Models\ClinicProvisioningRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A provisioning run gave up after its last retry. The run row keeps the
 * stage it reached and the error, and can be retried from there.
 */
class ClinicProvisioningFailed
{
    use Dispatchable;

    public function __construct(public ClinicProvisioningRun $run, public string $error) {}
}
