<?php

namespace App\Shared\Notification\Channels;

use App\Shared\Notification\Contracts\NotificationChannelInterface;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Enums\NotificationStatus;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Models\NotificationLog;
use Illuminate\Support\Facades\Log;

class InAppChannel implements NotificationChannelInterface
{
    public function send(EnterpriseNotification $notification, SendNotificationDTO $dto): bool
    {
        try {
            // In-app notifications are basically just creating the database record,
            // which is already done by the DatabaseChannel in Laravel when the notification
            // is persisted. However, since we are wrapping this in our Engine, this channel
            // explicitly marks the log that it was "delivered" to the database.

            NotificationLog::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::IN_APP,
                'status' => NotificationStatus::DELIVERED,
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('InAppChannel Error: '.$e->getMessage());

            NotificationLog::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::IN_APP,
                'status' => NotificationStatus::FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getName(): string
    {
        return NotificationChannel::IN_APP->value;
    }
}
