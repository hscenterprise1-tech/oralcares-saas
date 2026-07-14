<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
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
            'key' => fake()->unique()->word(),
            'name' => fake()->jobTitle(),
            'description' => fake()->sentence(),
            'is_system' => false,
            'is_active' => true,
            'source_system_role_id' => null,
        ];
    }

    /**
     * Indicate that the role is a system template.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'clinic_id' => null,
            'is_system' => true,
        ]);
    }

    /**
     * Indicate that the role is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
