<?php

namespace App\Services;

use App\Events\SettingsInitialized;
use App\Models\Clinic;
use App\Models\ClinicSetting;

class ClinicSettingsService
{
    /**
     * Initialize default settings for a clinic.
     */
    public function initializeDefaultSettings(Clinic $clinic): void
    {
        $defaults = [
            'general' => [
                'name' => $clinic->name,
                'email' => '',
                'phone' => '',
            ],
            'localization' => [
                'timezone' => 'UTC',
                'currency' => 'USD',
                'language' => 'en',
            ],
            'working_hours' => [
                'monday' => ['start' => '09:00', 'end' => '17:00'],
                'tuesday' => ['start' => '09:00', 'end' => '17:00'],
                'wednesday' => ['start' => '09:00', 'end' => '17:00'],
                'thursday' => ['start' => '09:00', 'end' => '17:00'],
                'friday' => ['start' => '09:00', 'end' => '17:00'],
            ],
            'preferences' => [
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i',
                'number_format' => '2',
                'theme' => 'light',
            ],
        ];

        foreach ($defaults as $group => $settings) {
            foreach ($settings as $key => $value) {
                ClinicSetting::updateOrCreate(
                    [
                        'clinic_id' => $clinic->id,
                        'group' => $group,
                        'key' => $key,
                    ],
                    ['value' => $value]
                );
            }
        }

        event(new SettingsInitialized($clinic));
    }

    /**
     * Get a setting value.
     */
    public function getSetting(Clinic $clinic, string $group, string $key, $default = null)
    {
        $setting = ClinicSetting::where('clinic_id', $clinic->id)
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value.
     */
    public function setSetting(Clinic $clinic, string $group, string $key, $value): void
    {
        ClinicSetting::updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'group' => $group,
                'key' => $key,
            ],
            ['value' => $value]
        );
    }
}
