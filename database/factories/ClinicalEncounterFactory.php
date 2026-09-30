<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicalEncounter;
use App\Models\Organization;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalEncounter>
 */
class ClinicalEncounterFactory extends Factory
{
    protected $model = ClinicalEncounter::class;

    public function definition(): array
    {
        $organization = Organization::factory();

        return [
            'organization_id' => $organization,
            'clinic_id' => fn (array $attributes) => Clinic::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'branch_id' => fn (array $attributes) => Branch::factory()->create([
                'clinic_id' => $attributes['clinic_id'],
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'patient_id' => fn (array $attributes) => Patient::factory()->create([
                'clinic_id' => $attributes['clinic_id'],
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'provider_id' => null,
            'encounter_number' => 'ENC-'.fake()->unique()->numerify('#####'),
            'encounter_type' => 'consultation',
            'status' => 'in_progress',
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'ended_at' => now(),
        ]);
    }
}
