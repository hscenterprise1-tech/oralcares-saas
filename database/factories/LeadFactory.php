<?php

namespace Database\Factories;

use App\LeadPriority;
use App\LeadStatus;
use App\Models\Lead;
use App\Models\Organization;
use App\OrganizationRole;
use App\OrganizationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = $this->faker->unique()->company().' Dental';

        return [
            'company_name' => $company,
            'slug' => function (array $attributes) {
                return Str::slug($attributes['company_name']);
            },
            'contact_name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'status' => LeadStatus::New,
            'priority' => LeadPriority::Medium,
            'source' => 'website',
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            /*
             * Every deal belongs to a CRM tenant.
             *
             * This defaulted to null, which was harmless while the policy read
             * a null tenant as "matches everybody". Now that the policy is
             * strict, a tenantless deal is one nobody but a master admin can
             * open — so a factory that produced them was producing records
             * that could not be worked. Tests that want a tenantless row still
             * pass one explicitly.
             */
            'organization_id' => fn () => Organization::provider()?->id
                ?? Organization::factory()->create(['role' => OrganizationRole::Provider])->id,
            'expected_mrr' => $this->faker->randomFloat(2, 100, 1000),
            'expected_arr' => $this->faker->randomFloat(2, 1000, 12000),
            'automated_score' => $this->faker->numberBetween(0, 100),
            'organization_type' => OrganizationType::DentalClinic->value,
            'organization_size' => '1-10',
            'branch_count' => 1,
            'expected_users' => 5,
        ];
    }

    /**
     * State with a complete address (address, city, state all present).
     */
    public function withAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => 'India',
            'postal_code' => $this->faker->postcode(),
        ]);
    }
}
