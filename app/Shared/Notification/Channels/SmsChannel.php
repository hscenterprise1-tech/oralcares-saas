<?php

namespace App\Shared\Notification\Channels;

use App\Shared\Notification\Contracts\NotificationChannelInterface;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Models\EnterpriseNotification;
use Illuminate\Support\Facades\Log;

class SmsChannel implements NotificationChannelInterface
{
    public function getName(): string
    {
        return NotificationChannel::SMS->value;
    }

    public function send(EnterpriseNotification $notification, SendNotificationDTO $dto): bool
    {
        // 1. Resolve Recipient Phone Number
        $notifiable = $notification->notifiable;
        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            Log::warning('Cannot send SMS: No phone number for recipient', [
                'type' => get_class($notifiable),
                'id' => $notifiable->getKey(),
            ]);

            return false;
        }

        // 2. Prepare Payload
        $payload = [
            'to' => $phone,
            'message' => $dto->message,
            'notification_id' => $notification->id,
        ];

        // 3. Dispatch to Integration Engine (Stubbed for now)
        // e.g., Twilio API, AWS SNS
        Log::info('Stub: Dispatching SMS via Integration Engine', $payload);

        // 4. Log the Delivery (for analytics/audit)
        $notification->logs()->create([
            'channel' => $this->getName(),
            'status' => 'sent',
            'provider' => 'stub',
            'provider_message_id' => 'stub-'.uniqid(),
            'sent_at' => now(),
        ]);

        return true;
    }
}
