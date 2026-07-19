<?php

namespace App\Services;

use App\Events\NotificationFailed;
use App\Events\NotificationQueued;
use App\Events\NotificationSent;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(
        protected CommunicationPreferenceService $prefService,
        protected TemplateService $templateService
    ) {}

    /**
     * Queue a new notification.
     */
    public function queueNotification(
        Clinic $clinic,
        ?Branch $branch,
        string $recipientType,
        int $recipientId,
        string $channel,
        string $priority,
        NotificationTemplate $template,
        array $placeholders,
        ?string $scheduledAt = null
    ): Notification {
        $notification = Notification::create([
            'clinic_id' => $clinic->id,
            'branch_id' => $branch?->id,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'channel' => $channel,
            'priority' => $priority,
            'status' => 'pending',
            'notification_template_id' => $template->id,
            'metadata' => [
                'placeholders' => $placeholders,
            ],
            'scheduled_at' => $scheduledAt,
        ]);

        event(new NotificationQueued($notification));

        return $notification;
    }

    /**
     * Deliver the pending notification.
     */
    public function sendNotification(Notification $notification): void
    {
        $notification->update(['status' => 'processing']);

        try {
            $rendered = $this->templateService->renderTemplate(
                $notification->template,
                $notification->metadata['placeholders'] ?? []
            );

            // Simulation Layer (Do NOT invoke real third-party integrations like Meta/Twilio)
            if ($notification->channel === 'whatsapp') {
                // MANDATORY: Verify that independent clinic WhatsApp configurations are mapped
                // Verify that no shared company credentials or templates are accessed.
                $hasCustomSenderConfig = $notification->clinic_id > 0; // Simulated credentials check

                if (! $hasCustomSenderConfig) {
                    throw new \RuntimeException('Clinic does not have a connected independent WhatsApp Business API account.');
                }

                Log::info("WhatsApp message sent via clinic-owned business account (Clinic ID: {$notification->clinic_id}). Content: {$rendered['content']}");
            } else {
                Log::info("Notification sent via {$notification->channel}. Subject: {$rendered['subject']}, Content: {$rendered['content']}");
            }

            $notification->update([
                'status' => 'delivered',
                'sent_at' => now(),
            ]);

            event(new NotificationSent($notification));
        } catch (\Exception $e) {
            $notification->update([
                'status' => 'failed',
                'failed_reason' => $e->getMessage(),
                'retry_count' => $notification->retry_count + 1,
            ]);

            event(new NotificationFailed($notification));
        }
    }
}
