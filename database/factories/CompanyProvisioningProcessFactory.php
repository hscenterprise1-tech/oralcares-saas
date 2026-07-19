<?php

namespace Database\Factories;

use App\Models\CompanyOpportunity;
use App\Models\CompanyProvisioningProcess;
use App\ProvisioningState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyProvisioningProcess>
 */
class CompanyProvisioningProcessFactory extends Factory
{
    protected $model = CompanyProvisioningProcess::class;

    public function definition(): array
    {
        return [
            'opportunity_id' => CompanyOpportunity::factory(),
            'clinic_id' => null,
            'status' => ProvisioningState::Pending,
            'current_step' => null,
            'completed_steps' => [],
            'error_message' => null,
            'retry_count' => 0,
        ];
    }
}
