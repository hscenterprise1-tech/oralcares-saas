<?php

namespace App\Jobs;

use App\Models\CompanyProvisioningProcess;
use App\Services\TenantProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TenantProvisioningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public CompanyProvisioningProcess $process
    ) {}

    /**
     * Execute the job.
     */
    public function handle(TenantProvisioningService $service): void
    {
        $service->runProcess($this->process);
    }
}
