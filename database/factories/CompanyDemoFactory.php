<?php

namespace Database\Factories;

use App\DemoOutcome;
use App\Models\CompanyDemo;
use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyDemo>
 */
class CompanyDemoFactory extends Factory
{
    protected $model = CompanyDemo::class;

    public function definition(): array
    {
        return [
            'opportunity_id' => CompanyOpportunity::factory(),
            'scheduled_at' => now()->addDays(2),
            'presenter_id' => CompanyEmployee::factory(),
            'meeting_link' => fake()->url(),
            'outcome' => DemoOutcome::Scheduled,
            'notes' => fake()->paragraph(),
            'feedback' => null,
            'follow_up_date' => null,
        ];
    }
}
