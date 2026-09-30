<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Trial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trial>
 */
class TrialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'start_date' => now(),
            'end_date' => now()->addDays(14),
            'status' => 'active',
            'expected_users' => 5,
            'usage_metrics' => [],
        ];
    }
}
