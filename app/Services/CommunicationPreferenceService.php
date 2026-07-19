<?php

namespace App\Services;

use App\Models\Clinic;
use App\Models\CommunicationPreference;

class CommunicationPreferenceService
{
    /**
     * Set communication channel options.
     */
    public function setPreference(
        Clinic $clinic,
        string $channel,
        bool $isEnabled,
        ?string $quietStart = null,
        ?string $quietEnd = null
    ): CommunicationPreference {
        return CommunicationPreference::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'channel' => $channel,
            ],
            [
                'is_enabled' => $isEnabled,
                'quiet_hours_start' => $quietStart,
                'quiet_hours_end' => $quietEnd,
            ]
        );
    }

    /**
     * Check if a notification channel is enabled, respecting quiet hours limit.
     */
    public function isChannelEnabled(Clinic $clinic, string $channel, ?string $time = null): bool
    {
        $pref = CommunicationPreference::where('clinic_id', $clinic->id)
            ->where('channel', $channel)
            ->first();

        // Enabled by default if no preference is registered
        if (! $pref) {
            return true;
        }

        if (! $pref->is_enabled) {
            return false;
        }

        // Check quiet hours
        if ($time && $pref->quiet_hours_start && $pref->quiet_hours_end) {
            $t = date('H:i:s', strtotime($time));
            $start = $pref->quiet_hours_start;
            $end = $pref->quiet_hours_end;

            if ($start < $end) {
                if ($t >= $start && $t <= $end) {
                    return false; // falls within quiet hours
                }
            } else {
                if ($t >= $start || $t <= $end) {
                    return false; // spans overnight
                }
            }
        }

        return true;
    }
}
