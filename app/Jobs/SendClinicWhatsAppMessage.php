<?php

namespace App\Jobs;

use App\Models\ClinicWhatsAppMessage;
use App\Models\Notification;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppTemplate;
use App\Services\Communication\PatientMessageEligibility;
use App\Services\Marketing\WhatsAppManager;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Send one queued clinic WhatsApp message on the number it was resolved to.
 *
 * Only a `queued` message is sent, under a row lock, so a retried or
 * duplicated job never sends twice. A rate limit waits and retries without
 * counting as a failure; any other error retries up to `MAX_ATTEMPTS`, then
 * the message is `failed` with the provider's reason.
 */
class SendClinicWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    /** Seconds before each retry. */
    public const BACKOFF = [60, 300];

    public int $tries = 10;

    public function __construct(public int $messageId)
    {
        $this->onQueue(config('queue.queues.notifications'));
    }

    public function handle(WhatsAppManager $whatsApp): void
    {
        $claimed = DB::transaction(function () {
            $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->lockForUpdate()->find($this->messageId);
            if (! $message || $message->status !== ClinicWhatsAppMessage::QUEUED) {
                return null;
            }
            $message->increment('attempts');

            return $message;
        });
        if ($claimed === null) {
            return;
        }

        // The last moment before the provider: a patient merged or deleted
        // since this was queued is not contacted. Skipped with
        // the reason, not failed, so it is never offered for retry.
        if ($claimed->patient_id !== null
            && ($reason = app(PatientMessageEligibility::class)->blockReasonFor((int) $claimed->clinic_id, (int) $claimed->patient_id)) !== null) {
            $claimed->update(['status' => ClinicWhatsAppMessage::SKIPPED, 'failure_reason' => $reason]);
            $this->notification($claimed, ['status' => NotificationService::SKIPPED, 'failed_reason' => $reason]);

            return;
        }

        $connection = WhatsAppConnection::query()->find($claimed->whatsapp_connection_id);
        $template = WhatsAppTemplate::query()->find($claimed->whatsapp_template_id);
        // The number must still be the one this clinic may use.
        $usable = $connection && $connection->isActive() && $template
            && ($connection->isShared() ? $connection->clinic_id === null : $connection->clinic_id === $claimed->clinic_id);
        if (! $usable) {
            $this->markFailed($claimed, 'The WhatsApp number is no longer active for this clinic.');

            return;
        }

        $components = [['type' => 'body', 'parameters' => array_map(fn (string $value) => ['type' => 'text', 'text' => $value], (array) $claimed->variables)]];
        $result = $whatsApp->driver('meta')->sendTemplateMessage($connection, (string) $claimed->recipient_phone, $template->name, $template->language, $components);

        if ($result['success'] ?? false) {
            $claimed->update(['status' => ClinicWhatsAppMessage::SENT, 'provider_message_id' => $result['provider_message_id'] ?? null, 'sent_at' => now(), 'failure_reason' => null]);
            $this->notification($claimed, ['status' => 'sent', 'sent_at' => now()]);

            return;
        }

        if ($result['rate_limited'] ?? false) {
            $claimed->decrement('attempts');
            $this->release((int) ($result['retry_after'] ?? 60));

            return;
        }

        $reason = mb_substr((string) ($result['error'] ?? 'WhatsApp did not accept the message.'), 0, 255);
        if ($claimed->attempts >= self::MAX_ATTEMPTS) {
            $this->markFailed($claimed, $reason);

            return;
        }
        $claimed->update(['failure_reason' => $reason]);
        $this->release(self::BACKOFF[$claimed->attempts - 1] ?? 300);
    }

    private function markFailed(ClinicWhatsAppMessage $message, string $reason): void
    {
        $message->update(['status' => ClinicWhatsAppMessage::FAILED, 'failure_reason' => $reason, 'failed_at' => now()]);
        $this->notification($message, ['status' => 'failed', 'failed_reason' => $reason]);
    }

    /** Keep the patient message this delivery carries in step. */
    private function notification(ClinicWhatsAppMessage $message, array $attributes): void
    {
        if ($message->notification_id) {
            Notification::query()->whereKey($message->notification_id)->update($attributes);
        }
    }
}
