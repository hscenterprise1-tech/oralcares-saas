<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\Organization;
use App\Models\Patient;
use App\PatientStatus;
use App\VisitRegistrationType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'clinic_id' => fn (array $attributes) => isset($attributes['organization_id'])
                ? Clinic::factory()->create(['organization_id' => $attributes['organization_id']])->id
                : Clinic::factory(),
            'organization_id' => fn (array $attributes) => isset($attributes['clinic_id'])
                ? (Clinic::find($attributes['clinic_id'])?->organization_id ?? Organization::factory())
                : null,
            'uhid' => null, // Set explicitly in tests or via UhidGenerator
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => "{$firstName} {$lastName}",
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('##########'),
            'alternate_phone' => null,
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-1 year')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'blood_group' => fake()->randomElement(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', null]),
            'registration_type' => VisitRegistrationType::New->value,
            'status' => PatientStatus::Active->value,
            // Legacy — kept for backward compatibility
            'patient_number' => null,
        ];
    }

    /**
     * Configure as a repeat patient.
     */
    public function repeat(): static
    {
        return $this->state(['registration_type' => VisitRegistrationType::Repeat->value]);
    }

    /**
     * Configure as inactive.
     */
    public function inactive(): static
    {
        return $this->state(['status' => PatientStatus::Inactive->value]);
    }
}
