<?php

namespace App\Services\Billing;

use App\ClinicPermission;
use App\Models\Clinic;
use App\Models\ClinicWhatsAppMessage;
use App\Models\Notification;
use App\Models\PatientBillingPayment;
use App\Models\User;
use App\Services\WhatsApp\ClinicWhatsApp;
use Illuminate\Support\Str;

/**
 * What the patient has been told about one payment: the two messages
 * `PatientMessaging::paymentRecorded` queues for it (`payment:{id}` — the
 * acknowledgement with the balance — and `receipt:{id}`), each with where
 * it actually stands. Nothing here sends or queues a message; a payment's
 * messages exist only because the payment was recorded, so an unpaid bill,
 * a failed payment or "Collect later" has none to show.
 *
 * States are reported as they are recorded, never assumed: `waiting` (queued
 * by the clinic, not yet handed to WhatsApp), WhatsApp's own `queued`,
 * `sent`, `delivered`, `read`, `failed` and `skipped` (with the reason), and
 * `not_whatsapp` when the clinic's WhatsApp does not carry it.
 */
class PaymentMessages
{
    private const KINDS = ['payment' => 'Payment confirmation', 'receipt' => 'Receipt'];

    public function __construct(protected ClinicWhatsApp $whatsApp) {}

    /**
     * @return array{whatsapp: array{state: string, label: string}, messages: list<array{kind: string, label: string, state: string, detail: ?string, at: ?string, can_retry: bool}>}
     */
    public function status(PatientBillingPayment $payment, User $user): array
    {
        $clinic = Clinic::query()->findOrFail($payment->clinic_id);
        $notifications = Notification::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('event_key', collect(self::KINDS)->keys()->map(fn (string $kind) => "{$kind}:{$payment->id}")->all())
            ->get()->keyBy('event_key');
        $deliveries = ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('clinic_id', $clinic->id)
            ->whereIn('notification_id', $notifications->pluck('id'))->get()->keyBy('notification_id');
        $connection = $this->whatsApp->status($clinic);

        $messages = [];
        foreach (self::KINDS as $kind => $label) {
            $notification = $notifications->get("{$kind}:{$payment->id}");
            if ($notification === null) {
                continue;
            }
            $delivery = $deliveries->get($notification->id);
            [$state, $detail, $at] = match (true) {
                $delivery !== null => [$delivery->status, $delivery->failure_reason, ($delivery->read_at ?? $delivery->delivered_at ?? $delivery->sent_at ?? $delivery->failed_at)?->toIso8601String()],
                $notification->channel !== 'whatsapp' => ['not_whatsapp', 'WhatsApp is not set up to send this message for the clinic, so nothing was sent on WhatsApp.', null],
                $notification->status === 'failed' => ['failed', $notification->failed_reason, null],
                default => ['waiting', null, null],
            };
            $messages[] = [
                'kind' => $kind,
                'label' => $label,
                'state' => $state,
                'detail' => $detail !== null ? Str::limit($detail, 160) : null,
                'at' => $at,
                'can_retry' => $delivery?->status === ClinicWhatsAppMessage::FAILED && $user->hasClinicPermission(ClinicPermission::PaymentCollect),
            ];
        }

        return ['whatsapp' => ['state' => $connection['state'], 'label' => $connection['label']], 'messages' => $messages];
    }

    /**
     * Send this payment's failed WhatsApp messages again (`ClinicWhatsApp::retry`).
     *
     * @return int how many were queued again
     */
    public function retry(PatientBillingPayment $payment, User $user): int
    {
        $ids = Notification::query()->withoutGlobalScopes()->where('clinic_id', $payment->clinic_id)
            ->whereIn('event_key', collect(self::KINDS)->keys()->map(fn (string $kind) => "{$kind}:{$payment->id}")->all())->pluck('id');

        return ClinicWhatsAppMessage::query()->withoutGlobalScopes()->where('clinic_id', $payment->clinic_id)
            ->whereIn('notification_id', $ids)->where('status', ClinicWhatsAppMessage::FAILED)->get()
            ->filter(fn (ClinicWhatsAppMessage $message) => $this->whatsApp->retry($message, $user))->count();
    }
}
