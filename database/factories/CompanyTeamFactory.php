<?php

namespace Database\Factories;

use App\Models\CompanyDepartment;
use App\Models\CompanyTeam;
use App\TeamStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyTeam>
 */
class CompanyTeamFactory extends Factory
{
    protected $model = CompanyTeam::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'department_id' => CompanyDepartment::factory(),
            'name' => ucwords($name),
            'code' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => TeamStatus::Active,
        ];
    }

    /**
     * Indicate that the team is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TeamStatus::Inactive,
        ]);
    }
}
