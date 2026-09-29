<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignRecipient;
use App\Models\MarketingContact;
use App\Models\MarketingSettings;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class MarketingSettingsService
{
    /**
     * Resolve effective orgId, falling back to HQ orgId when null.
     */
    protected function resolveOrgId(?int $orgId): int
    {
        if ($orgId) {
            return $orgId;
        }

        // The provider, by role rather than by name — see Organization::provider().
        return (int) (Organization::provider()?->id
            ?? Organization::min('id')
            ?? 1);
    }

    /**
     * Get or create tenant marketing settings.
     */
    public function getSettings(?int $orgId = null): MarketingSettings
    {
        $effectiveOrgId = $this->resolveOrgId($orgId);

        return MarketingSettings::firstOrCreate(
            ['organization_id' => $effectiveOrgId],
            [
                'max_daily_messages' => 5000,
                'hourly_dispatch_rate' => 500,
                'quiet_hours_enabled' => false,
                'quiet_hours_start' => '21:00',
                'quiet_hours_end' => '09:00',
                'emergency_freeze_enabled' => false,
                'opt_out_keywords' => ['STOP', 'UNSUBSCRIBE', 'REMOVE', 'CANCEL'],
                'auto_suppress_on_failures' => true,
                'max_consecutive_failures' => 3,
            ]
        );
    }

    /**
     * Update tenant marketing governance settings.
     */
    public function updateSettings(?int $orgId, array $data): MarketingSettings
    {
        $effectiveOrgId = $this->resolveOrgId($orgId);
        $settings = $this->getSettings($effectiveOrgId);

        if (isset($data['opt_out_keywords']) && is_string($data['opt_out_keywords'])) {
            $keywords = array_filter(array_map('trim', explode(',', $data['opt_out_keywords'])));
            $data['opt_out_keywords'] = array_values(array_unique(array_map('strtoupper', $keywords)));
        }

        $settings->update($data);
        Cache::forget("mkt_settings:org_{$effectiveOrgId}");

        return $settings;
    }

    /**
     * Toggle emergency operational kill-switch.
     */
    public function toggleEmergencyFreeze(?int $orgId, ?bool $freeze = null): bool
    {
        $effectiveOrgId = $this->resolveOrgId($orgId);
        $settings = $this->getSettings($effectiveOrgId);
        $newStatus = $freeze !== null ? $freeze : ! $settings->emergency_freeze_enabled;

        $settings->update(['emergency_freeze_enabled' => $newStatus]);
        Cache::forget("mkt_settings:org_{$effectiveOrgId}");

        return $newStatus;
    }

    /**
     * Check if tenant emergency freeze kill-switch is active.
     */
    public function isEmergencyFreezeActive(?int $orgId): bool
    {
        $settings = $this->getSettings($orgId);

        return (bool) $settings->emergency_freeze_enabled;
    }

    /**
     * The timezone quiet hours are expressed in.
     *
     * "No messages after 21:00" means the organisation's own 21:00, not the
     * server's. Resolved through the same helper Segments and Analytics use,
     * so every date-sensitive rule in Marketing reads the clock the same way.
     */
    public function businessTimezone(?int $orgId): string
    {
        return MarketingContact::resolveBusinessTimezone(
            Organization::find($this->resolveOrgId($orgId))
        );
    }

    /**
     * Check if a given time (or now) falls inside tenant quiet hours.
     */
    public function isInQuietHours(?int $orgId, ?Carbon $dateTime = null): bool
    {
        $settings = $this->getSettings($orgId);

        if (! $settings->quiet_hours_enabled) {
            return false;
        }

        // Compared in the organisation's timezone. Read in UTC — the server
        // default — an IST tenant's 21:00–09:00 window was being applied
        // five and a half hours out from the hours they actually chose.
        $timezone = $this->businessTimezone($orgId);
        $dt = $dateTime ? $dateTime->copy()->setTimezone($timezone) : Carbon::now($timezone);
        $currentTime = $dt->format('H:i');

        $start = $settings->quiet_hours_start ?: '21:00';
        $end = $settings->quiet_hours_end ?: '09:00';

        // Overnight window (e.g. 21:00 to 09:00)
        if ($start > $end) {
            return $currentTime >= $start || $currentTime < $end;
        }

        // Same-day window (e.g. 13:00 to 15:00)
        return $currentTime >= $start && $currentTime < $end;
    }

    /**
     * When the current quiet-hours window ends, as a UTC instant.
     *
     * Lets a held message be scheduled once for the moment sending reopens,
     * instead of being woken repeatedly to re-check. Returns null when quiet
     * hours are not currently in force.
     */
    public function quietHoursEndsAt(?int $orgId, ?Carbon $from = null): ?Carbon
    {
        if (! $this->isInQuietHours($orgId, $from)) {
            return null;
        }

        $settings = $this->getSettings($orgId);
        $timezone = $this->businessTimezone($orgId);
        $now = $from ? $from->copy()->setTimezone($timezone) : Carbon::now($timezone);

        $end = $settings->quiet_hours_end ?: '09:00';
        [$endHour, $endMinute] = array_pad(explode(':', $end), 2, '0');

        $endsAt = $now->copy()->setTime((int) $endHour, (int) $endMinute, 0);

        // An end time already past today belongs to tomorrow — the overnight
        // case, where a message held at 23:00 reopens at 09:00 the next day.
        if ($endsAt->lessThanOrEqualTo($now)) {
            $endsAt->addDay();
        }

        return $endsAt->utc();
    }

    /**
     * Messages already sent for this organisation during its current local day.
     *
     * Counted from `sent_at` on the recipient rows themselves, which each
     * carry exactly one send, so a retry or a re-dispatch cannot inflate the
     * figure the way an incrementing counter could.
     */
    public function messagesSentToday(?int $orgId, ?Carbon $at = null): int
    {
        $effectiveOrgId = $this->resolveOrgId($orgId);
        $timezone = $this->businessTimezone($effectiveOrgId);
        $moment = $at ? $at->copy()->setTimezone($timezone) : Carbon::now($timezone);

        return MarketingCampaignRecipient::query()
            ->whereHas('campaign', fn ($q) => $q->where('organization_id', $effectiveOrgId))
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [
                $moment->copy()->startOfDay()->utc(),
                $moment->copy()->endOfDay()->utc(),
            ])
            ->count();
    }

    /**
     * Whether this organisation has reached its configured daily send cap.
     *
     * Independent of `hourly_dispatch_rate`, which throttles pace per sending
     * number through the queue's rate limiter. This is the ceiling on volume
     * per day for the whole organisation; a send must satisfy both.
     */
    public function hasReachedDailyCap(?int $orgId, ?Carbon $at = null): bool
    {
        $cap = $this->getSettings($orgId)->max_daily_messages;

        if (! $cap || $cap < 1) {
            return false;
        }

        return $this->messagesSentToday($orgId, $at) >= $cap;
    }

    /**
     * Start of the organisation's next local day, as a UTC instant — when a
     * message held by the daily cap becomes sendable again.
     */
    public function dailyCapResetsAt(?int $orgId, ?Carbon $from = null): Carbon
    {
        $timezone = $this->businessTimezone($orgId);
        $now = $from ? $from->copy()->setTimezone($timezone) : Carbon::now($timezone);

        return $now->copy()->addDay()->startOfDay()->utc();
    }

    /**
     * Normalize inbound message and check against tenant opt-out keywords.
     */
    public function isOptOutKeyword(?int $orgId, string $messageBody): bool
    {
        $settings = $this->getSettings($orgId);
        $keywords = $settings->opt_out_keywords ?: ['STOP', 'UNSUBSCRIBE', 'REMOVE', 'CANCEL'];

        $normalized = strtoupper(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $messageBody)));

        foreach ($keywords as $kw) {
            $kwUpper = strtoupper(trim($kw));
            if ($normalized === $kwUpper || str_starts_with($normalized, $kwUpper.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Track delivery failures and auto-suppress contact if threshold is reached.
     */
    public function recordDeliveryFailure(MarketingContact $contact): void
    {
        $settings = $this->getSettings($contact->organization_id);

        if (! $settings->auto_suppress_on_failures) {
            return;
        }

        // Fetch recent failed recipient count for contact
        $threshold = $settings->max_consecutive_failures ?: 3;

        // Check if contact has reached maximum consecutive failures
        $consecutiveFailures = $contact->suppressionLogs()
            ->where('reason', 'invalid_number')
            ->count() + 1;

        if ($consecutiveFailures >= $threshold && $contact->marketing_status === 'active') {
            app(MarketingSuppressionService::class)->suppressContact(
                contact: $contact,
                reason: 'invalid_number',
                notes: "Auto-suppressed after {$consecutiveFailures} consecutive delivery failures.",
                changedBy: null,
                source: 'whatsapp_provider'
            );
        }
    }
}
