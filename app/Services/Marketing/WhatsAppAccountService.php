<?php

namespace App\Services\Marketing;

use App\Models\MarketingWhatsAppAccount;
use App\Models\User;

class WhatsAppAccountService
{
    public function __construct(
        protected WhatsAppManager $whatsappManager
    ) {}

    /**
     * Create or update WhatsApp account connection for tenant organization.
     *
     * Identified by `phone_number_id` — the number's own identity at Meta,
     * and the value campaigns, templates and conversations are all keyed
     * against. Matching on provider alone meant an organisation only ever had
     * one row per provider: connecting a second number silently overwrote the
     * first, even though the schema, `marketing_campaigns.whatsapp_account_id`
     * and the template account picker all expect several. An explicit `id`
     * still takes precedence, so editing an existing connection — including
     * correcting the number on it — updates that row rather than forking a
     * new one.
     */
    public function saveAccount(?int $orgId, array $data, ?User $user = null): MarketingWhatsAppAccount
    {
        $provider = $data['provider'] ?? 'meta';
        $phoneNumberId = trim($data['phone_number_id'] ?? '');

        $account = MarketingWhatsAppAccount::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('provider', $provider)
            ->when(
                ! empty($data['id']),
                fn ($q) => $q->whereKey($data['id']),
                fn ($q) => $q->where('phone_number_id', $phoneNumberId)
            )
            ->first();

        if (! $account) {
            $account = new MarketingWhatsAppAccount([
                'organization_id' => $orgId,
                'provider' => $provider,
                'created_by_user_id' => $user?->id,
            ]);
        }

        $account->fill([
            'business_account_id' => trim($data['business_account_id'] ?? ''),
            'phone_number_id' => trim($data['phone_number_id'] ?? ''),
            'display_phone_number' => trim($data['display_phone_number'] ?? ''),
            'verified_name' => trim($data['verified_name'] ?? 'OralCares WhatsApp'),
            'updated_by_user_id' => $user?->id,
            'status' => 'connected',
            'connection_status' => 'active',
            'last_connected_at' => now(),
        ]);

        if (! empty($data['purpose'])) {
            $account->purpose = $data['purpose'];
        }

        if (array_key_exists('label', $data)) {
            $account->label = $data['label'] !== null ? trim($data['label']) : null;
        }

        if (! empty($data['access_token'])) {
            $account->access_token = trim($data['access_token']);
        }

        if (! empty($data['webhook_verify_token'])) {
            $account->webhook_verify_token = trim($data['webhook_verify_token']);
        } elseif (empty($account->webhook_verify_token)) {
            $account->webhook_verify_token = 'oralcares_webhook_token';
        }

        $account->save();

        return $account;
    }

    /**
     * Disconnect WhatsApp account connection.
     */
    public function disconnectAccount(MarketingWhatsAppAccount $account): void
    {
        $account->update([
            'status' => 'disconnected',
            'connection_status' => 'inactive',
        ]);
    }

    /**
     * Test connection credentials against provider.
     */
    public function testConnection(MarketingWhatsAppAccount $account): bool
    {
        $provider = $this->whatsappManager->driver($account->provider);
        $isOk = $provider->testConnection($account);

        if ($isOk) {
            $account->update([
                'status' => 'connected',
                'last_connected_at' => now(),
            ]);
        } else {
            $account->update([
                'status' => 'error',
            ]);
        }

        return $isOk;
    }
}
