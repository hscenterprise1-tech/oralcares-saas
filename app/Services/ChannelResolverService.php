<?php

namespace App\Services;

use App\Models\Clinic;

class ChannelResolverService
{
    public function __construct(
        protected CommunicationPreferenceService $prefService
    ) {}

    /**
     * Resolve the highest priority active channel configured by the clinic.
     *
     * @param  list<string>  $except  channels decided elsewhere (patient WhatsApp is decided by `ClinicWhatsApp`)
     */
    public function resolvePreferredChannel(Clinic $clinic, string $recipientType, int $recipientId, array $except = []): string
    {
        $channels = array_diff(['whatsapp', 'sms', 'email', 'in_app'], $except);

        foreach ($channels as $channel) {
            if ($this->prefService->isChannelEnabled($clinic, $channel)) {
                return $channel;
            }
        }

        return 'in_app'; // Ultimate fallback channel
    }
}
