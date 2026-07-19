<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationQueued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Notification $notification
    ) {}
}
