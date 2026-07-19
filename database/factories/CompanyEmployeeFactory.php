<?php

namespace Database\Factories;

use App\EmploymentStatus;
use App\Models\CompanyDepartment;
use App\Models\CompanyEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyEmployee>
 */
class CompanyEmployeeFactory extends Factory
{
    protected $model = CompanyEmployee::class;

    public function definition(): array
    {
        return [
            'employee_code' => 'EMP-'.fake()->unique()->numberBetween(100000, 999999),
            'user_id' => User::factory()->companyUser(),
            'department_id' => CompanyDepartment::factory(),
            'team_id' => null,
            'manager_id' => null,
            'designation' => fake()->jobTitle(),
            'employment_status' => EmploymentStatus::Active,
            'joining_date' => fake()->date(),
            'profile_photo' => null,
            'timezone' => fake()->timezone(),
            'language' => fake()->languageCode(),
            'notes' => fake()->paragraph(),
        ];
    }

    /**
     * Indicate that the employee reports to a manager.
     */
    public function reportsTo(CompanyEmployee $manager): static
    {
        return $this->state(fn (array $attributes) => [
            'manager_id' => $manager->id,
        ]);
    }

    /**
     * Indicate that the employee has a status.
     */
    public function status(EmploymentStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => $status,
        ]);
    }
}
