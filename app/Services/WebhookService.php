<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\WebhookSubscription;

class WebhookService
{
    /**
     * Subscribe to a webhook event.
     */
    public function subscribe(
        Clinic $clinic,
        string $eventType,
        string $targetUrl,
        ?string $secretToken = null
    ): WebhookSubscription {
        return WebhookSubscription::create([
            'clinic_id' => $clinic->id,
            'event_type' => $eventType,
            'target_url' => $targetUrl,
            'secret_token' => $secretToken ?? bin2hex(random_bytes(16)),
            'is_active' => true,
        ]);
    }

    /**
     * Unsubscribe from webhook events.
     */
    public function unsubscribe(WebhookSubscription $sub): void
    {
        $sub->delete();
    }

    /**
     * Prepare webhook event delivery records (Simulation payload and signature preparation).
     */
    public function triggerEvent(Clinic $clinic, string $eventType, array $payload): array
    {
        $subs = WebhookSubscription::where('clinic_id', $clinic->id)
            ->where('event_type', $eventType)
            ->where('is_active', true)
            ->get();

        $deliveries = [];
        foreach ($subs as $sub) {
            $jsonPayload = json_encode($payload);
            $signature = hash_hmac('sha256', $jsonPayload, $sub->secret_token);

            $deliveries[] = [
                'target_url' => $sub->target_url,
                'payload' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-OralCares-Signature' => $signature,
                    'X-OralCares-Event' => $eventType,
                ],
            ];
        }

        return $deliveries;
    }
}
