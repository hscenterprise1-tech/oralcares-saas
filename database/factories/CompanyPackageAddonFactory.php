<?php

namespace Database\Factories;

use App\Models\CompanyPackageAddon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyPackageAddon>
 */
class CompanyPackageAddonFactory extends Factory
{
    protected $model = CompanyPackageAddon::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'type' => 'module',
            'price' => fake()->numberBetween(1000, 20000),
            'pricing_basis' => 'flat',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
