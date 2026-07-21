<?php

namespace Database\Factories\Shared\Notification\Models;

use App\Shared\Notification\Models\EnterpriseNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnterpriseNotificationFactory extends Factory
{
    protected $model = EnterpriseNotification::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid,
            'type' => 'App\Shared\Notification\Notifications\EnterpriseNotificationMessage',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => 1,
            'data' => [
                'title' => $this->faker->sentence,
                'message' => $this->faker->paragraph,
            ],
            'title' => $this->faker->sentence,
            'category' => 'system',
            'priority' => 'normal',
        ];
    }
}
