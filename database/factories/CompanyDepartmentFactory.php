<?php

namespace Database\Factories;

use App\DepartmentStatus;
use App\Models\CompanyDepartment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyDepartment>
 */
class CompanyDepartmentFactory extends Factory
{
    protected $model = CompanyDepartment::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'code' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => DepartmentStatus::Active,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Indicate that the department is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DepartmentStatus::Inactive,
        ]);
    }
}
