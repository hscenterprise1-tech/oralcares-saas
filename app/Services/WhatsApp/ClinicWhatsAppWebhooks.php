<?php

namespace App\Services\WhatsApp;

use App\Models\ClinicWhatsAppMessage;
use App\Models\ClinicWhatsAppWebhookEvent;
use App\Models\Notification;
use App\Models\WhatsAppConnection;
use App\Services\Marketing\WhatsAppManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Delivery callbacks and replies for clinic numbers (`/webhooks/clinic-whatsapp`).
 *
 * Every event is bound to the connection its `phone_number_id` names; an
 * unknown number is ignored. A status only updates a message sent on that
 * same connection, and only forwards (sent → delivered → read; failed ends
 * it), so a stray or forged id cannot touch another clinic's messages.
 *
 * A reply on a clinic's own number belongs to that clinic. On OralCares'
 * common number it belongs to the clinic that last wrote to that phone on
 * that number; when no clinic has, it is kept only as a raw event and shown
 * to no clinic.
 */
class ClinicWhatsAppWebhooks
{
    private const RANK = [ClinicWhatsAppMessage::QUEUED => 0, ClinicWhatsAppMessage::SENT => 1, ClinicWhatsAppMessage::DELIVERED => 2, ClinicWhatsAppMessage::READ => 3];

    public function __construct(protected WhatsAppManager $whatsApp) {}

    /** The verification challenge: answered when the token is one OralCares set. */
    public function challenge(string $mode, string $token, string $challenge): ?string
    {
        if ($mode !== 'subscribe' || $token === '') {
            return null;
        }
        $configured = (string) config('services.meta_whatsapp.clinic_verify_token');
        if ($configured !== '' && hash_equals($configured, $token)) {
            return $challenge;
        }
        foreach (WhatsAppConnection::query()->whereNotNull('webhook_verify_token')->get() as $connection) {
            if (hash_equals((string) $connection->webhook_verify_token, $token)) {
                return $challenge;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{processed: int, ignored: int}
     */
    public function handle(array $payload, bool $signatureValid): array
    {
        $processed = 0;
        $ignored = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneNumberId = (string) ($change['value']['metadata']['phone_number_id'] ?? '');
                $connection = $phoneNumberId === '' ? null : WhatsAppConnection::query()->where('phone_number_id', $phoneNumberId)->first();
                $events = $this->whatsApp->driver('meta')->parseWebhookPayload(['entry' => [['changes' => [$change]]]]);

                foreach ($events as $event) {
                    if ($connection === null || ! $this->record($connection, $event, $signatureValid)) {
                        $ignored++;

                        continue;
                    }
                    str_starts_with($event['event_type'], 'message.received') ? $this->reply($connection, $event) : $this->status($connection, $event);
                    $processed++;
                }
            }
        }

        return ['processed' => $processed, 'ignored' => $ignored];
    }

    /** Keep the raw event once; false when it was seen before. */
    private function record(WhatsAppConnection $connection, array $event, bool $signatureValid): bool
    {
        try {
            ClinicWhatsAppWebhookEvent::query()->create([
                'whatsapp_connection_id' => $connection->id,
                'provider_event_id' => $event['provider_event_id'] ?? null,
                'provider_message_id' => $event['provider_message_id'] ?? null,
                'event_type' => mb_substr((string) $event['event_type'], 0, 40),
                'payload' => $event['raw'] ?? [],
                'signature_valid' => $signatureValid,
                'processed_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function status(WhatsAppConnection $connection, array $event): void
    {
        $status = (string) ($event['status'] ?? '');
        DB::transaction(function () use ($connection, $event, $status) {
            $message = ClinicWhatsAppMessage::query()->withoutGlobalScopes()
                ->where('whatsapp_connection_id', $connection->id)->where('direction', 'outbound')
                ->where('provider_message_id', $event['provider_message_id'] ?? '')->lockForUpdate()->first();
            if (! $message || $message->status === ClinicWhatsAppMessage::FAILED) {
                return;
            }

            if ($status === 'failed') {
                $message->update(['status' => ClinicWhatsAppMessage::FAILED, 'failed_at' => now(), 'failure_reason' => mb_substr((string) ($event['failure_reason'] ?? 'WhatsApp could not deliver the message.'), 0, 255)]);
                $this->notification($message, ['status' => 'failed', 'failed_reason' => $message->failure_reason]);

                return;
            }
            if (! isset(self::RANK[$status]) || self::RANK[$status] <= (self::RANK[$message->status] ?? 0)) {
                return;
            }
            $message->update(['status' => $status, "{$status}_at" => now()]);
            if (in_array($status, [ClinicWhatsAppMessage::DELIVERED, ClinicWhatsAppMessage::READ], true)) {
                $this->notification($message, ['status' => 'delivered']);
            }
        });
    }

    private function reply(WhatsAppConnection $connection, array $event): void
    {
        $phone = ClinicWhatsApp::normalizePhone((string) ($event['phone'] ?? ''));
        $last = $phone === null ? null : ClinicWhatsAppMessage::query()->withoutGlobalScopes()
            ->where('whatsapp_connection_id', $connection->id)->where('direction', 'outbound')->where('recipient_phone', $phone)
            ->when(! $connection->isShared(), fn ($query) => $query->where('clinic_id', $connection->clinic_id))
            ->latest('id')->first();
        $clinicId = $connection->isShared() ? $last?->clinic_id : $connection->clinic_id;
        if ($clinicId === null) {
            return;
        }

        ClinicWhatsAppMessage::query()->withoutGlobalScopes()->create([
            'clinic_id' => $clinicId,
            'branch_id' => $last?->branch_id,
            'whatsapp_connection_id' => $connection->id,
            'patient_id' => $last?->patient_id,
            'direction' => 'inbound',
            'recipient_phone' => $phone,
            'preview' => mb_substr((string) ($event['body'] ?? ''), 0, 2000),
            'status' => 'received',
            'provider_message_id' => $event['provider_message_id'] ?? null,
        ]);
    }

    private function notification(ClinicWhatsAppMessage $message, array $attributes): void
    {
        if ($message->notification_id) {
            Notification::query()->whereKey($message->notification_id)->update($attributes);
        }
    }
}
