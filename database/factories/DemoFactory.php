<?php

namespace Database\Factories;

use App\Models\Demo;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Demo>
 */
class DemoFactory extends Factory
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
            'presenter_id' => User::factory(),
            'scheduled_at' => fake()->dateTimeBetween('now', '+1 week'),
            'status' => 'scheduled',
            'meeting_link' => fake()->url(),
            'notes' => fake()->sentence(),
        ];
    }
}
