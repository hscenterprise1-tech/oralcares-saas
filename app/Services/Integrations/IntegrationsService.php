<?php

namespace App\Services\Integrations;

use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppMessage;
use App\Models\MarketingWhatsAppWebhookEvent;
use App\Services\Marketing\WebhookSignatureVerifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What this company is connected to, and whether those connections are working.
 *
 * Read-only. Every integration here is configured where it already lives —
 * WhatsApp numbers in Marketing settings — and nothing on this surface connects,
 * disconnects, retries or replays anything, because a second way to do any of
 * those would be a second integration system.
 *
 * There was no such surface at all: a WhatsApp connection could stop receiving
 * events and the only evidence was a `last_webhook_at` column no page read, and
 * a failed event row nothing displayed.
 *
 * Nothing here returns an access token or a verify token. Marketing settings
 * shows the verify token because an operator has to paste it into Meta; this is
 * a health view and has no reason to hold a secret.
 */
class IntegrationsService
{
    /** A connection silent for longer than this is worth looking at. */
    protected const QUIET_HOURS = 24;

    public function __construct(protected WebhookSignatureVerifier $signatures) {}

    /**
     * Every configured WhatsApp connection and how it is doing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function connections(): array
    {
        return MarketingWhatsAppAccount::query()
            ->orderBy('id')
            ->get()
            ->map(function (MarketingWhatsAppAccount $account): array {
                $lastWebhook = $account->last_webhook_at;

                return [
                    'id' => $account->id,
                    'name' => $account->display_name,
                    'provider' => $account->provider,
                    'purpose' => $account->purpose?->value,
                    'number' => $account->display_phone_number,
                    'verified_name' => $account->verified_name,
                    'status' => $account->status,
                    'connection_status' => $account->connection_status,
                    'last_connected_at' => $account->last_connected_at?->toDateTimeString(),
                    'last_webhook_at' => $lastWebhook?->toDateTimeString(),
                    /* Whether the credentials are present — never what they are. */
                    'has_access_token' => $account->access_token_encrypted !== null,
                    'has_verify_token' => $account->webhook_verify_token_encrypted !== null,
                    /*
                     * A connection can read `connected` and have received
                     * nothing for a week; the status column records what was
                     * last saved, not what is arriving.
                     */
                    'quiet' => $lastWebhook === null || $lastWebhook->lt(now()->subHours(self::QUIET_HOURS)),
                    'hours_silent' => $lastWebhook !== null ? (int) $lastWebhook->diffInHours(now()) : null,
                ];
            })
            ->all();
    }

    /**
     * Inbound webhook health over a window.
     *
     * @return array<string, mixed>
     */
    public function inbound(int $days = 30): array
    {
        $since = now()->subDays($days);

        $byStatus = MarketingWhatsAppWebhookEvent::query()
            ->where('created_at', '>=', $since)
            ->select('processing_status', DB::raw('count(*) as aggregate'))
            ->groupBy('processing_status')
            ->pluck('aggregate', 'processing_status')
            ->map(fn ($count) => (int) $count)
            ->all();

        /*
         * `signature_result` is null on every event stored before verification
         * existed. That is reported as `unrecorded` rather than folded into
         * `unconfigured`: those events arrived with nothing checking them at
         * all, which is a different fact about them.
         */
        $bySignature = MarketingWhatsAppWebhookEvent::query()
            ->where('created_at', '>=', $since)
            ->select('signature_result', DB::raw('count(*) as aggregate'))
            ->groupBy('signature_result')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) ($row->signature_result ?? 'unrecorded') => (int) $row->aggregate])
            ->all();

        $failures = MarketingWhatsAppWebhookEvent::query()
            ->where('processing_status', 'failed')
            ->latest('id')
            ->limit(25)
            ->get(['id', 'event_type', 'provider', 'error_message', 'signature_result', 'created_at', 'whatsapp_account_id'])
            ->map(fn (MarketingWhatsAppWebhookEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'provider' => $event->provider,
                /* The reason, in full — a truncated error is not a reason. */
                'error' => $event->error_message,
                'signature' => $event->signature_result,
                'account_id' => $event->whatsapp_account_id,
                'at' => $event->created_at?->toDateTimeString(),
            ])
            ->all();

        $recent = MarketingWhatsAppWebhookEvent::query()
            ->latest('id')
            ->limit(30)
            ->get(['id', 'event_type', 'provider', 'processing_status', 'signature_result', 'created_at', 'whatsapp_account_id'])
            ->map(fn (MarketingWhatsAppWebhookEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'provider' => $event->provider,
                'status' => $event->processing_status,
                'signature' => $event->signature_result,
                'account_id' => $event->whatsapp_account_id,
                'at' => $event->created_at?->toDateTimeString(),
            ])
            ->all();

        $unverified = ($bySignature[WebhookSignatureVerifier::UNCONFIGURED] ?? 0)
            + ($bySignature['unrecorded'] ?? 0);

        return [
            'days' => $days,
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_signature' => $bySignature,
            'signature_enforced' => $this->signatures->isConfigured(),
            'unverified' => $unverified,
            'failures' => $failures,
            'recent' => $recent,
            'daily' => $this->dailyVolume($since),
        ];
    }

    /**
     * Outbound send health: what was actually delivered, and what failed.
     *
     * @return array<string, mixed>
     */
    public function outbound(int $days = 30): array
    {
        $since = now()->subDays($days);

        $byStatus = MarketingWhatsAppMessage::query()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', $since)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $failures = MarketingWhatsAppMessage::query()
            ->where('direction', 'outbound')
            ->where('status', 'failed')
            ->latest('id')
            ->limit(25)
            ->get(['id', 'whatsapp_account_id', 'message_type', 'template_name', 'failure_reason', 'failed_at'])
            ->map(fn (MarketingWhatsAppMessage $message): array => [
                'id' => $message->id,
                'account_id' => $message->whatsapp_account_id,
                'type' => $message->message_type,
                'template' => $message->template_name,
                'reason' => $message->failure_reason,
                'at' => $message->failed_at?->toDateTimeString(),
            ])
            ->all();

        return [
            'days' => $days,
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'failures' => $failures,
        ];
    }

    /**
     * The parts of an integration surface this application does not have.
     *
     * Counted rather than asserted, so the page states what is true of this
     * database instead of repeating a claim that could go stale.
     *
     * @return array<string, array{rows:int, scope:string}>
     */
    public function unavailable(): array
    {
        return [
            /*
             * `api_tokens.clinic_employee_id` is a non-nullable foreign key to
             * `clinic_employees`, so a Company user cannot hold one: there is no
             * row shape that represents them. Issuing company API access is an
             * architecture decision, not a screen.
             */
            'api_tokens' => [
                'rows' => (int) DB::table('api_tokens')->count(),
                'scope' => 'clinic_employee_id',
            ],
            /*
             * `webhook_subscriptions` is clinic-scoped, and the service that
             * reads it is called from nowhere.
             */
            'outbound_webhooks' => [
                'rows' => (int) DB::table('webhook_subscriptions')->count(),
                'scope' => 'clinic_id',
            ],
            'api_audit' => [
                'rows' => (int) DB::table('api_audit_logs')->count(),
                'scope' => 'clinic_employee_id',
            ],
        ];
    }

    /**
     * Daily inbound volume, so a connection that went quiet reads as a shape
     * rather than as one stale timestamp.
     *
     * @return array<int, array{date:string, count:int}>
     */
    protected function dailyVolume(Carbon $since): array
    {
        /*
         * Grouped in PHP off a date-bounded read of one column. `DATE()` is not
         * portable across this application's connections, and a query per day
         * would be one round trip for every day in the window.
         */
        $counts = MarketingWhatsAppWebhookEvent::query()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->limit(20000)
            ->pluck('created_at')
            ->countBy(fn ($at) => Carbon::parse($at)->toDateString());

        $days = [];

        for ($cursor = $since->copy()->startOfDay(); $cursor->lte(now()); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $days[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return $days;
    }
}
