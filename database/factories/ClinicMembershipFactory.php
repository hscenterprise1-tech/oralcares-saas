<?php

namespace Database\Factories;

use App\ClinicMembershipStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicMembership>
 */
class ClinicMembershipFactory extends Factory
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
            'user_id' => User::factory(),
            'is_primary_owner' => false,
            'has_all_branches_access' => false,
            'status' => ClinicMembershipStatus::Active,
        ];
    }

    /**
     * Indicate that the membership is a primary owner.
     */
    public function primaryOwner(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary_owner' => true,
            'has_all_branches_access' => true,
        ]);
    }

    /**
     * Indicate that the membership has access to all branches.
     */
    public function allBranchesAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_all_branches_access' => true,
        ]);
    }

    /**
     * Indicate that the membership is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClinicMembershipStatus::Inactive,
        ]);
    }

    /**
     * Indicate that the membership is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ClinicMembershipStatus::Suspended,
        ]);
    }
}
