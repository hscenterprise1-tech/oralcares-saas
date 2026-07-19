<?php

namespace Database\Factories;

use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\Models\CompanyTrial;
use App\TrialStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyTrial>
 */
class CompanyTrialFactory extends Factory
{
    protected $model = CompanyTrial::class;

    public function definition(): array
    {
        return [
            'opportunity_id' => CompanyOpportunity::factory(),
            'start_date' => now(),
            'end_date' => now()->addDays(14),
            'status' => TrialStatus::Active,
            'modules_enabled' => ['patients', 'billing', 'calendar'],
            'max_users' => 5,
            'max_branches' => 1,
            'max_doctors' => 3,
            'storage_limit_gb' => 10,
            'feature_flags' => ['whatsapp_integration' => false],
            'trial_owner_id' => CompanyEmployee::factory(),
        ];
    }
}
