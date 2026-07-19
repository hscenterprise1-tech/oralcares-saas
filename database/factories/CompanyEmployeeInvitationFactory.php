<?php

namespace Database\Factories;

use App\Models\CompanyEmployee;
use App\Models\CompanyEmployeeInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyEmployeeInvitation>
 */
class CompanyEmployeeInvitationFactory extends Factory
{
    protected $model = CompanyEmployeeInvitation::class;

    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'company_employee_id' => CompanyEmployee::factory(),
            'email' => fake()->unique()->safeEmail(),
            'expires_at' => now()->addDays(3),
            'accepted_at' => null,
            'revoked_at' => null,
            'created_by' => User::factory()->masterAdmin(),
        ];
    }

    /**
     * Indicate that the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that the invitation has been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }

    /**
     * Indicate that the invitation has been revoked.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }
}
