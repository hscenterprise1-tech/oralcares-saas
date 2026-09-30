<?php

namespace Database\Seeders;

use App\Models\CompanyPlan;
use Illuminate\Database\Seeder;

class CompanyPlanSeeder extends Seeder
{
    /**
     * Seed a starter catalog of packages so there's something to trial/quote
     * against on day one. All figures are placeholders — edit them (or add
     * more plans) from Sales Teams... err, Packages settings in the CRM.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'key' => 'starter',
                'description' => 'For single-branch clinics just getting started.',
                'price' => 4999,
                'billing_period' => 'monthly',
                'max_users' => 5,
                'max_doctors' => 2,
                'max_branches' => 1,
                'storage_limit_gb' => 10,
                'is_trial_available' => true,
                'trial_duration_days' => (int) config('crm.trial_default_days', 14),
            ],
            [
                'name' => 'Growth',
                'key' => 'growth',
                'description' => 'For growing practices with multiple doctors and branches.',
                'price' => 12999,
                'billing_period' => 'monthly',
                'max_users' => 20,
                'max_doctors' => 5,
                'max_branches' => 3,
                'storage_limit_gb' => 50,
                'is_trial_available' => true,
                'trial_duration_days' => (int) config('crm.trial_default_days', 14),
            ],
            [
                'name' => 'Enterprise',
                'key' => 'enterprise',
                'description' => 'For hospital chains and large multi-specialty groups.',
                'price' => 34999,
                'billing_period' => 'monthly',
                'max_users' => 100,
                'max_doctors' => 25,
                'max_branches' => 10,
                'storage_limit_gb' => 250,
                'is_trial_available' => true,
                'trial_duration_days' => (int) config('crm.trial_default_days', 14),
            ],
        ];

        foreach ($plans as $plan) {
            CompanyPlan::firstOrCreate(['key' => $plan['key']], $plan);
        }
    }
}
