<?php

namespace App\Services\Marketing;

use App\Models\MarketingWhatsAppAccount;
use App\Models\MarketingWhatsAppTemplate;

class WhatsAppTemplateService
{
    public function __construct(
        protected WhatsAppManager $whatsappManager
    ) {}

    /**
     * Fetch templates from provider and synchronize locally for the given WhatsApp account.
     */
    public function syncTemplates(MarketingWhatsAppAccount $account): array
    {
        $driver = $this->whatsappManager->driver($account->provider);

        // Fetch templates via provider API
        $remoteTemplates = $driver->getTemplates($account);

        $created = 0;
        $updated = 0;

        foreach ($remoteTemplates as $tmplData) {
            $template = MarketingWhatsAppTemplate::updateOrCreate(
                [
                    'whatsapp_account_id' => $account->id,
                    'name' => $tmplData['name'],
                    'language' => $tmplData['language'] ?? 'en',
                ],
                [
                    'organization_id' => $account->organization_id,
                    'provider_template_id' => $tmplData['id'] ?? null,
                    'category' => strtoupper($tmplData['category'] ?? 'MARKETING'),
                    'status' => strtolower($tmplData['status'] ?? 'approved'),
                    'body' => $tmplData['body'] ?? '',
                    'header_type' => $tmplData['header_type'] ?? null,
                    'header_content' => $tmplData['header_content'] ?? null,
                    'footer' => $tmplData['footer'] ?? null,
                    'buttons' => $tmplData['buttons'] ?? [],
                    'variables' => $tmplData['variables'] ?? [],
                    'provider_payload' => $tmplData['raw'] ?? $tmplData,
                ]
            );

            if ($template->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return [
            'total' => count($remoteTemplates),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Get sendable approved templates for an account.
     */
    public function getApprovedTemplates(MarketingWhatsAppAccount $account)
    {
        return MarketingWhatsAppTemplate::query()
            ->where('whatsapp_account_id', $account->id)
            ->approved()
            ->orderBy('name')
            ->get();
    }
}
