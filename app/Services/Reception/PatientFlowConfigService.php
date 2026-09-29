<?php

namespace App\Services\Reception;

use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;

/**
 * Per-clinic patient-flow switches (Settings > Patient Flow), stored on
 * `clinics.patient_flow_config`. Same pattern as `PatientFieldConfigService`.
 *
 * `rooms_enabled` (default off): with it off, rooms appear nowhere — not on
 * the Live Queue, the Now/Next card, the check-in modal, or appointment
 * booking — and any `room_id` a request sends is ignored. Rooms already
 * stored on appointments are kept, never cleared.
 */
class PatientFlowConfigService
{
    public const AUDIT_SECTION = 'patient_flow';

    /**
     * @var array{rooms_enabled: bool}
     */
    public const DEFAULTS = ['rooms_enabled' => false];

    /**
     * @return array{rooms_enabled: bool}
     */
    public function resolve(?Clinic $clinic): array
    {
        $stored = $clinic?->patient_flow_config ?? [];

        return ['rooms_enabled' => (bool) ($stored['rooms_enabled'] ?? self::DEFAULTS['rooms_enabled'])];
    }

    public function roomsEnabled(?Clinic $clinic): bool
    {
        return $this->resolve($clinic)['rooms_enabled'];
    }

    /**
     * @param  array{rooms_enabled: bool}  $input
     */
    public function update(Clinic $clinic, array $input, ?User $actor): void
    {
        $before = $this->resolve($clinic);
        $after = ['rooms_enabled' => (bool) $input['rooms_enabled']];

        if ($before === $after) {
            return;
        }

        $clinic->update(['patient_flow_config' => $after]);

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::AUDIT_SECTION,
            'old_values' => $before,
            'new_values' => $after,
        ]);
    }
}
