<?php

namespace App\Shared\Notification\Channels;

use App\Shared\Notification\Contracts\NotificationChannelInterface;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Enums\NotificationStatus;
use App\Shared\Notification\Mail\EnterpriseNotificationMail;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OmniMailChannel implements NotificationChannelInterface
{
    public function send(EnterpriseNotification $notification, SendNotificationDTO $dto): bool
    {
        try {
            $notifiable = $notification->notifiable;

            if (! method_exists($notifiable, 'routeNotificationForMail') && ! isset($notifiable->email)) {
                throw new \Exception('No email route found for notifiable');
            }

            $email = method_exists($notifiable, 'routeNotificationForMail')
                        ? $notifiable->routeNotificationForMail($notification)
                        : $notifiable->email;

            // Use Laravel Mail Facade for sending
            Mail::to($email)->send(new EnterpriseNotificationMail($dto));

            NotificationLog::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::EMAIL,
                'status' => NotificationStatus::SENT,
                'recipient_contact' => $email,
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('OmniMailChannel Error: '.$e->getMessage());

            NotificationLog::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::EMAIL,
                'status' => NotificationStatus::FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getName(): string
    {
        return NotificationChannel::EMAIL->value;
    }
}
