<?php

namespace Database\Factories;

use App\Models\Task;
use App\TaskPriority;
use App\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::Medium,
            'due_at' => now()->addDays(2),
            'completed_at' => null,
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            'creator_id' => null,
            'taskable_type' => null,
            'taskable_id' => null,
        ];
    }
}
