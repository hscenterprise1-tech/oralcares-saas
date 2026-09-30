<?php

namespace Database\Factories;

use App\ClinicType;
use App\CustomerChurnRisk;
use App\Models\Organization;
use App\OrganizationRole;
use App\OrganizationScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => 'other',
            'email' => fake()->companyEmail(),
            'mobile' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'clinic_type' => ClinicType::DentalClinic,
            'organization_scale' => OrganizationScale::SingleClinic,
            'health_score' => 100,
            'renewal_probability' => 100,
            'churn_risk' => CustomerChurnRisk::Low,
            'expansion_opportunity' => false,
        ];
    }

    /**
     * The company running OralCares.
     *
     * There is exactly one, so tests that need it should say so rather than
     * relying on being the first row inserted.
     */
    public function provider(): static
    {
        return $this->state(fn () => ['role' => OrganizationRole::Provider]);
    }

    /** A clinic or hospital we sold to. The default. */
    public function customer(): static
    {
        return $this->state(fn () => ['role' => OrganizationRole::Customer]);
    }
}
