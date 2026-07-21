<?php

namespace App\Shared\Notification\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class EnterpriseNotificationMessage extends Notification implements ShouldQueue
{
    use Queueable;

    // This is essentially a marker class or a base class. 
    // In our custom NotificationEngineService, we manually create the DatabaseNotification
    // model, but if we were to broadcast via Reverb or push to other channels natively via 
    // Laravel's notification system, we would define toMail(), toBroadcast(), etc. here.
    
    public function __construct(
        public string $title,
        public string $message,
        public array $metadata = []
    ) {}

    public function via(object $notifiable): array
    {
        // For standard Laravel routing, this would return the channels.
        // Since we are using our custom NotificationEngineService to handle channels
        // based on user preferences, this is just a placeholder if needed natively.
        return [];
    }
}
