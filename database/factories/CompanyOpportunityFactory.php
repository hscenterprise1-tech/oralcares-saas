<?php

namespace Database\Factories;

use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\Models\Lead;
use App\OpportunityStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyOpportunity>
 */
class CompanyOpportunityFactory extends Factory
{
    protected $model = CompanyOpportunity::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'title' => fake()->sentence(3),
            'stage' => OpportunityStage::New,
            'value' => fake()->randomFloat(2, 1000, 10000),
            'assigned_id' => CompanyEmployee::factory(),
            'closed_at' => null,
            'notes' => fake()->paragraph(),
        ];
    }
}
