<?php

namespace Database\Factories;

use App\BranchStatus;
use App\Models\Branch;
use App\Models\Clinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'name' => fake()->city().' Branch',
            'slug' => fake()->unique()->slug(),
            'is_main' => false,
        ];
    }

    /**
     * Indicate that the branch is the main branch.
     */
    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_main' => true,
        ]);
    }

    /**
     * Indicate that the branch is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BranchStatus::Inactive,
        ]);
    }

    /**
     * Indicate that the branch is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BranchStatus::Suspended,
        ]);
    }
}
