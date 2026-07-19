<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReminderTriggered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Notification $notification,
        public string $reminderType
    ) {}
}
