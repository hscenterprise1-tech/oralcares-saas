<?php

namespace App\Jobs;

use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Services\Marketing\MarketingSettingsService;
use App\Services\Marketing\WhatsAppMessageService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class SendCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 180, 300];

    public function __construct(
        public int $recipientId
    ) {}

    /**
     * Proactive per-account throttling, ahead of dispatch rather than only
     * reacting to a 429 after the fact. Limit is read from the org's own
     * `hourly_dispatch_rate` setting — see the `campaign-dispatch` limiter
     * registered in AppServiceProvider.
     */
    public function middleware(): array
    {
        return [new RateLimited('campaign-dispatch')];
    }

    public function handle(WhatsAppMessageService $messageService): void
    {
        $recipient = MarketingCampaignRecipient::with(['campaign.whatsappAccount', 'campaign.template', 'contact'])->find($this->recipientId);

        if (! $recipient) {
            return;
        }

        // A recipient is only usefully processed while it is still waiting
        // to go out. This is what makes it safe to dispatch a second job for
        // the same recipient without knowing whether an earlier one already
        // ran — resuming a paused campaign redispatches any recipient still
        // 'queued' precisely because that ambiguity can't be resolved any
        // other way, and this guard turns a redundant run into a no-op
        // instead of a duplicate send.
        if (! in_array($recipient->status, ['pending', 'queued'])) {
            return;
        }

        $campaign = $recipient->campaign;
        if (! $campaign) {
            return;
        }

        // 1. Check Campaign State (Stop sending if campaign is paused, cancelled, or completed)
        if (in_array($campaign->status, ['paused', 'cancelled', 'completed', 'failed'])) {
            return;
        }

        // 2. Check Centralized Marketing Governance Settings (Emergency Freeze & Quiet Hours)
        $settingsService = app(MarketingSettingsService::class);
        $orgId = $campaign->organization_id;

        if ($orgId && $settingsService->isEmergencyFreezeActive($orgId)) {
            $recipient->update([
                'status' => 'skipped',
                'failure_reason' => 'Emergency freeze active for tenant organization',
            ]);
            $this->dispatchCompletionCheck($campaign->id);

            return;
        }

        if ($orgId && $settingsService->isInQuietHours($orgId)) {
            // Held until the window reopens, as a freshly dispatched job
            // rather than `release()`.
            //
            // A released job keeps its attempt count, and `$tries = 3` is
            // sized for genuine send failures — so a message meeting a
            // realistic 10-12 hour quiet window was exhausting its attempts
            // within minutes and failing permanently, which is the opposite
            // of holding it. Re-dispatching starts a fresh attempt budget and
            // schedules it once for the moment sending actually reopens,
            // instead of waking it repeatedly to re-check the clock.
            //
            // Safe to dispatch anew because `handle()` only proceeds while the
            // recipient is still 'pending'/'queued' — the same guard that lets
            // a paused campaign redispatch its queued recipients without
            // risking a duplicate send.
            $this->redispatchAt($settingsService->quietHoursEndsAt($orgId));

            return;
        }

        // Organisation-wide ceiling on volume for the local day. Distinct
        // from `hourly_dispatch_rate`, which paces sends per number through
        // the queue's rate limiter — a send has to satisfy both. Held rather
        // than skipped: the cap is a "not today" instruction, not a reason to
        // throw the message away.
        if ($orgId && $settingsService->hasReachedDailyCap($orgId)) {
            $this->redispatchAt($settingsService->dailyCapResetsAt($orgId));

            return;
        }

        // 3. Re-check Contact Eligibility Safety right before dispatch
        $contact = $recipient->contact;
        if ($contact && in_array($contact->marketing_status, MarketingContact::COMPLIANCE_STATUSES, true)) {
            $recipient->update([
                'status' => 'skipped',
                'failure_reason' => 'Contact no longer marketing eligible (opted out or suppressed)',
            ]);
            $this->dispatchCompletionCheck($campaign->id);

            return;
        }

        $account = $campaign->whatsappAccount;
        if (! $account || $account->status !== 'connected') {
            $recipient->markFailed('Connected WhatsApp account unavailable');
            $this->dispatchCompletionCheck($campaign->id);

            return;
        }

        // 4. Dispatch via the approved-template API — the only method Meta
        // allows for business-initiated sends. The idempotency key is stable
        // per recipient row for its whole lifetime, so a queue retry, a
        // worker restart, or a duplicate dispatch of this same job can never
        // produce a second outbound message.
        $payload = $recipient->personalized_payload ?? [];
        $templateName = $payload['template_name'] ?? $campaign->template?->name;
        $templateLanguage = $payload['template_language'] ?? $campaign->template?->language ?? 'en';
        $components = $payload['template_components'] ?? [];
        // Stable for the recipient's normal lifetime — retrying a failed
        // recipient explicitly overrides this to a fresh value (see
        // MarketingCampaignController::retryFailed()), because a genuinely
        // new attempt must not be short-circuited by the idempotency guard
        // that protects the original one.
        $idempotencyKey = $payload['idempotency_key'] ?? "campaign:{$campaign->id}:recipient:{$recipient->id}";

        if (! $templateName) {
            $recipient->markFailed('No template resolved for this recipient');
            $this->dispatchCompletionCheck($campaign->id);

            return;
        }

        $result = $messageService->sendOutboundTemplateMessage(
            $account,
            $recipient->phone,
            $templateName,
            $templateLanguage,
            $components,
            $idempotencyKey
        );

        if ($result['success']) {
            $providerMsgId = $result['message']->provider_message_id ?? ($result['provider_message_id'] ?? 'wamid.sent');
            $recipient->markSent($providerMsgId);
        } elseif (! empty($result['rate_limited'])) {
            $delay = $result['retry_after'] ?? 60;
            $this->release($delay);

            return;
        } else {
            $recipient->markFailed($result['error'] ?? 'WhatsApp API dispatch failed');
            if ($contact) {
                $settingsService->recordDeliveryFailure($contact);
            }
        }

        $this->dispatchCompletionCheck($campaign->id);
    }

    /**
     * Re-queue this recipient as a new job due at a given moment.
     *
     * Used for the two "not now, but not a failure either" holds — quiet
     * hours and the daily cap. A fresh dispatch rather than `release()` so a
     * long hold cannot consume the retry budget reserved for real failures.
     * The delay is floored at a minute so a clock already at the boundary
     * still yields forward progress rather than an immediate busy loop.
     */
    protected function redispatchAt(?Carbon $runAt): void
    {
        $delay = $runAt ? max(60, now()->diffInSeconds($runAt, false)) : 300;

        self::dispatch($this->recipientId)->delay($delay);
    }

    /**
     * Completion is checked in its own debounced job rather than inline here
     * — see CheckCampaignCompletionJob for why a direct per-message COUNT
     * doesn't hold up once thousands of these are firing close together.
     */
    protected function dispatchCompletionCheck(int $campaignId): void
    {
        CheckCampaignCompletionJob::dispatch($campaignId);
    }
}
