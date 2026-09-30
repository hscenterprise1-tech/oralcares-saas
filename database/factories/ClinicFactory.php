<?php

namespace Database\Factories;

use App\ClinicStatus;
use App\Models\Clinic;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Clinic>
 */
class ClinicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company().' Dental Clinic',
            'slug' => fake()->unique()->slug(),
            'status' => ClinicStatus::Active,
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
