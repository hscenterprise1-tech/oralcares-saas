<?php

namespace Database\Factories;

use App\Models\CompanyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPlan>
 */
class CompanyPlanFactory extends Factory
{
    protected $model = CompanyPlan::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Starter', 'Standard', 'Professional', 'Enterprise']),
            'key' => fake()->unique()->randomElement(['starter', 'standard', 'professional', 'enterprise']),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 99, 999),
            'billing_period' => 'monthly',
            'max_users' => 5,
            'max_doctors' => 3,
            'max_branches' => 1,
            'storage_limit_gb' => 10,
            'is_active' => true,
        ];
    }
}
