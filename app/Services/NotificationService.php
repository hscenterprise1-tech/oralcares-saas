<?php

namespace App\Services;

use App\Events\NotificationFailed;
use App\Events\NotificationQueued;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\ClinicWhatsAppMessage;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Communication\PatientMessaging;
use App\Services\WhatsApp\ClinicWhatsApp;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /** Not sent, and never will be on its own: no provider carries the channel. */
    public const SKIPPED = 'skipped';

    public const NO_PROVIDER_REASON = 'No provider is connected for this channel, so nothing was sent.';

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

        // A patient message's channel is decided again now, so a WhatsApp
        // setting changed since it was queued applies.
        if ($notification->recipient_type === 'patient' && ($clinic = Clinic::query()->find($notification->clinic_id))) {
            $code = (string) $notification->template()->withoutGlobalScopes()->value('code');
            $channel = app(PatientMessaging::class)->channelFor($clinic, $code, (int) $notification->recipient_id);
            if ($channel !== $notification->channel) {
                $notification->update(['channel' => $channel]);
            }
        }

        // WhatsApp is delivered for real, on the clinic's resolved number
        // (`ClinicWhatsApp`); its send job and delivery callbacks finish the
        // status. A delivery that cannot go fails here with its reason.
        if ($notification->channel === 'whatsapp') {
            $delivery = app(ClinicWhatsApp::class)->deliver($notification);
            if ($delivery->status === ClinicWhatsAppMessage::SKIPPED) {
                $notification->update(['status' => 'failed', 'failed_reason' => $delivery->failure_reason, 'retry_count' => $notification->retry_count + 1]);
                event(new NotificationFailed($notification));
            }

            return;
        }

        // SMS, e-mail and in-app have no provider connected yet, so nothing
        // leaves the platform: the message is skipped with its reason, never
        // reported as delivered. The log carries identifiers only — never the
        // rendered text, which names the patient and their care.
        $notification->update([
            'status' => self::SKIPPED,
            'failed_reason' => self::NO_PROVIDER_REASON,
        ]);

        Log::info('Patient message skipped: no provider for channel.', [
            'notification_id' => $notification->id,
            'clinic_id' => $notification->clinic_id,
            'channel' => $notification->channel,
            'event_key' => $notification->event_key,
        ]);
    }
}
