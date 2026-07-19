<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchSetting;

class BranchSettingsService
{
    /**
     * Initialize default settings for a branch.
     */
    public function initializeDefaultSettings(Branch $branch): void
    {
        $defaults = [
            'working_hours' => [
                'monday' => ['start' => '09:00', 'end' => '17:00'],
                'tuesday' => ['start' => '09:00', 'end' => '17:00'],
                'wednesday' => ['start' => '09:00', 'end' => '17:00'],
                'thursday' => ['start' => '09:00', 'end' => '17:00'],
                'friday' => ['start' => '09:00', 'end' => '17:00'],
            ],
            'queue' => [
                'prefix' => 'T',
                'reset_daily' => true,
            ],
            'hardware' => [
                'default_printer' => 'Standard Label Printer',
            ],
        ];

        foreach ($defaults as $group => $settings) {
            foreach ($settings as $key => $value) {
                BranchSetting::updateOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'group' => $group,
                        'key' => $key,
                    ],
                    ['value' => $value]
                );
            }
        }
    }

    /**
     * Get a branch setting.
     */
    public function getSetting(Branch $branch, string $group, string $key, $default = null)
    {
        $setting = BranchSetting::where('branch_id', $branch->id)
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a branch setting.
     */
    public function setSetting(Branch $branch, string $group, string $key, $value): void
    {
        BranchSetting::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'group' => $group,
                'key' => $key,
            ],
            ['value' => $value]
        );
    }
}
