<?php

namespace Database\Factories;

use App\Models\CompanyFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFeature>
 */
class CompanyFeatureFactory extends Factory
{
    protected $model = CompanyFeature::class;

    public function definition(): array
    {
        $feature = fake()->unique()->randomElement([
            'reception', 'patients', 'doctors', 'billing', 'xray', 'lab', 'inventory', 'reports',
        ]);

        return [
            'name' => ucwords($feature),
            'key' => $feature,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
