<?php

namespace App\Shared\Notification\Repositories;

use App\Models\User;
use App\Shared\Notification\Models\NotificationPreference;

class PreferenceRepository
{
    /**
     * Get the preferences for a user in a specific context.
     */
    public function getForUser(User $user, ?int $clinicId = null): NotificationPreference
    {
        // Try to get clinic-specific preferences first if clinic_id is provided
        if ($clinicId) {
            $preference = NotificationPreference::where('user_id', $user->getKey())
                ->where('clinic_id', $clinicId)
                ->first();

            if ($preference) {
                return $preference;
            }
        }

        // Fallback to global user preferences (clinic_id is null)
        $globalPref = NotificationPreference::firstOrCreate(
            [
                'user_id' => $user->getKey(),
                'clinic_id' => null,
            ],
            [
                'disabled_channels' => [],
                'disabled_categories' => [],
                'timezone' => 'UTC', // Ideally mapped from User timezone setting
            ]
        );

        return $globalPref;
    }

    /**
     * Update preferences for a user.
     */
    public function updatePreferences(User $user, ?int $clinicId, array $data): NotificationPreference
    {
        $preference = NotificationPreference::firstOrNew([
            'user_id' => $user->getKey(),
            'clinic_id' => $clinicId,
        ]);

        $preference->fill($data);
        $preference->save();

        return $preference;
    }
}
