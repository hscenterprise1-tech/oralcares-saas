<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ProcedureMaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcedureMaster>
 */
class ProcedureMasterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => 'PROC-'.$this->faker->unique()->numberBetween(100, 999),
            'name' => $this->faker->words(3, true),
            'base_price' => 100.00,
            'duration_minutes' => 15,
            'is_active' => true,
        ];
    }
}
