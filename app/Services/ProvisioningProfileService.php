<?php

namespace App\Services;

class ProvisioningProfileService
{
    /**
     * Resolve profile parameters.
     */
    public function getProfileConfig(string $profile): array
    {
        return match ($profile) {
            'solo_dentist' => [
                'branches_count' => 1,
                'chairs_count' => 1,
                'rooms_count' => 1,
                'resource_types' => ['doctor', 'chair'],
            ],
            'small_clinic' => [
                'branches_count' => 1,
                'chairs_count' => 2,
                'rooms_count' => 2,
                'resource_types' => ['doctor', 'chair', 'assistant', 'receptionist'],
            ],
            'multi_doctor_clinic' => [
                'branches_count' => 1,
                'chairs_count' => 4,
                'rooms_count' => 4,
                'resource_types' => ['doctor', 'chair', 'assistant', 'receptionist', 'xray_room'],
            ],
            'multi_branch_clinic' => [
                'branches_count' => 2,
                'chairs_count' => 4,
                'rooms_count' => 4,
                'resource_types' => ['doctor', 'chair', 'assistant', 'receptionist', 'xray_room', 'lab_room'],
            ],
            'enterprise' => [
                'branches_count' => 3,
                'chairs_count' => 8,
                'rooms_count' => 8,
                'resource_types' => ['doctor', 'chair', 'assistant', 'receptionist', 'lab_technician', 'xray_technician', 'xray_room', 'lab_room', 'operatory'],
            ],
            default => [
                'branches_count' => 1,
                'chairs_count' => 1,
                'rooms_count' => 1,
                'resource_types' => ['doctor', 'chair'],
            ]
        };
    }
}
