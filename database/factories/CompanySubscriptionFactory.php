<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\CompanyPlan;
use App\Models\CompanySubscription;
use App\SubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySubscription>
 */
class CompanySubscriptionFactory extends Factory
{
    protected $model = CompanySubscription::class;

    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            // CompanyOpportunity was retired and `opportunity_id` dropped
            // with it (see 2026_09_14_152709_drop_orphan_crm_tables_and_dead_columns) —
            // setting it here throws "no such column" on every fresh build.
            'company_plan_id' => CompanyPlan::factory(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addYear(),
            'trial_ends_at' => null,
            'canceled_at' => null,
            'max_users' => 10,
            'max_doctors' => 5,
            'max_branches' => 2,
            'storage_limit_gb' => 20,
        ];
    }
}
