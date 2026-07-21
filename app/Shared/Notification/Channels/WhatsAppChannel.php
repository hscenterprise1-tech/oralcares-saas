<?php

namespace App\Shared\Notification\Channels;

use App\Shared\Notification\Contracts\NotificationChannelInterface;
use App\Shared\Notification\DTO\SendNotificationDTO;
use App\Shared\Notification\Enums\NotificationChannel;
use App\Shared\Notification\Enums\NotificationStatus;
use App\Shared\Notification\Models\EnterpriseNotification;
use App\Shared\Notification\Models\NotificationLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel implements NotificationChannelInterface
{
    public function send(EnterpriseNotification $notification, SendNotificationDTO $dto): bool
    {
        try {
            $notifiable = $notification->notifiable;
            
            // Assuming notifiable has a phone_number property or a routeNotificationForWhatsApp method
            $phone = method_exists($notifiable, 'routeNotificationForWhatsApp') 
                        ? $notifiable->routeNotificationForWhatsApp($notification) 
                        : ($notifiable->phone_number ?? $notifiable->phone ?? null);

            if (!$phone) {
                throw new \Exception('No phone number found for WhatsApp notification');
            }

            // Using Meta WhatsApp Cloud API as the default enterprise implementation
            $token = config('services.whatsapp.token');
            $phoneNumberId = config('services.whatsapp.phone_number_id');
            
            if (!$token || !$phoneNumberId) {
                throw new \Exception('WhatsApp API credentials not configured');
            }

            $response = Http::withToken($token)
                ->post("https://graph.facebook.com/v17.0/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $dto->title . "\n\n" . $dto->message,
                    ]
                ]);

            if ($response->failed()) {
                throw new \Exception('WhatsApp API Error: ' . $response->body());
            }

            NotificationLog::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::WHATSAPP,
                'status' => NotificationStatus::SENT,
                'recipient_contact' => $phone,
                'provider_response' => $response->json(),
                'sent_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsAppChannel Error: ' . $e->getMessage());
            
            NotificationLog::create([
                'notification_id' => $notification->id,
                'channel' => NotificationChannel::WHATSAPP,
                'status' => NotificationStatus::FAILED,
                'error_message' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    public function getName(): string
    {
        return NotificationChannel::WHATSAPP->value;
    }
}
