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
     */
    public function resolvePreferredChannel(Clinic $clinic, string $recipientType, int $recipientId): string
    {
        $channels = ['whatsapp', 'sms', 'email', 'in_app'];

        foreach ($channels as $channel) {
            if ($this->prefService->isChannelEnabled($clinic, $channel)) {
                return $channel;
            }
        }

        return 'in_app'; // Ultimate fallback channel
    }
}
