<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'name' => ucwords($name),
            'key' => Str::slug($name).'-'.Str::random(4),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'assignment_strategy' => Team::STRATEGY_ROUND_ROBIN,
            'territory_map' => null,
        ];
    }

    public function territoryBased(array $territoryMap): static
    {
        return $this->state(fn (array $attributes) => [
            'assignment_strategy' => Team::STRATEGY_TERRITORY,
            'territory_map' => $territoryMap,
        ]);
    }
}
