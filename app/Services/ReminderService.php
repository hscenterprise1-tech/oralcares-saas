<?php

namespace App\Services;

use App\Events\ReminderTriggered;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Notification;
use App\Models\NotificationTemplate;

class ReminderService
{
    public function __construct(
        protected NotificationService $notificationService,
        protected ChannelResolverService $channelResolver
    ) {}

    /**
     * Schedule payment alerts, prescription checks, or custom follow-up reminders.
     */
    public function scheduleReminder(
        Clinic $clinic,
        ?Branch $branch,
        string $type,
        string $date,
        string $recipientType,
        int $recipientId,
        NotificationTemplate $template,
        array $placeholders
    ): Notification {
        // Resolve the recipient's preferred active channel
        $channel = $this->channelResolver->resolvePreferredChannel($clinic, $recipientType, $recipientId);

        // Schedule at date + start of business hours (e.g. 09:00:00)
        $scheduledAt = $date.' 09:00:00';

        $notification = $this->notificationService->queueNotification(
            $clinic,
            $branch,
            $recipientType,
            $recipientId,
            $channel,
            'medium',
            $template,
            $placeholders,
            $scheduledAt
        );

        event(new ReminderTriggered($notification, $type));

        return $notification;
    }
}
