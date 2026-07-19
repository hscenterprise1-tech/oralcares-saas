<?php

namespace Database\Factories;

use App\CompanyRoleStatus;
use App\Models\CompanyRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyRole>
 */
class CompanyRoleFactory extends Factory
{
    protected $model = CompanyRole::class;

    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'name' => $name,
            'key' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => CompanyRoleStatus::Active,
        ];
    }

    /**
     * Indicate that the role is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CompanyRoleStatus::Inactive,
        ]);
    }
}
