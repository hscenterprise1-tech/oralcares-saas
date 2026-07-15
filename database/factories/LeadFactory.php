<?php

namespace Database\Factories;

use App\LeadStatus;
use App\Models\Lead;
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
            'source' => 'website',
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            'clinic_id' => null,
        ];
    }
}
