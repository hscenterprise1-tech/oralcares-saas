<?php

namespace Database\Factories;

use App\ClinicStatus;
use App\Models\Clinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Dental Clinic',
            'slug' => fake()->unique()->slug(),
        ];
    }

    /**
     * Indicate that the clinic is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClinicStatus::Inactive,
        ]);
    }

    /**
     * Indicate that the clinic is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClinicStatus::Suspended,
        ]);
    }
}
