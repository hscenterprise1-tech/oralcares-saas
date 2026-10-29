<?php

namespace App\Services\Reception;

use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;
use App\Services\PatientFieldConfigService;
use App\Support\ClinicAccess\ClinicModules;
use App\VisitPriority;
use Illuminate\Validation\ValidationException;

/**
 * Per-clinic patient-flow switches (Settings > Patient Flow), stored on
 * `clinics.patient_flow_config`. Same pattern as `PatientFieldConfigService`.
 *
 * `rooms_enabled` (default off): with it off, rooms appear nowhere — not on
 * the Front Desk, the Now/Next card, the check-in modal, or appointment
 * booking — and any `room_id` a request sends is ignored. Rooms already
 * stored on appointments are kept, never cleared.
 */
class PatientFlowConfigService
{
    public const AUDIT_SECTION = 'patient_flow';

    public const CHECK_IN_AUDIT_SECTION = 'check_in';

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

    public const QUICK_CARE_AUDIT_SECTION = 'quick_care';

    /** The consultation fee is billed at checkout after the visit (the default). */
    public const PAY_AFTER_CONSULTATION = 'after_consultation';

    /** The consultation fee is charged (and may be paid) when the patient checks in. */
    public const PAY_AT_CHECK_IN = 'at_check_in';

    /**
     * What check-in asks for besides the patient: whether the reason for the
     * visit is required, optional or not asked; whether priority is offered
     * and which priorities (normal is always one); whether the desk shows
     * queue positions; whether it shows the doctor's consultation fee.
     * Stored under `check_in` on the same column; its own audit section.
     *
     * @return array{visit_reason: string, priority: bool, priorities: list<string>, queue: bool, show_fee: bool, payment: string}
     */
    public function checkIn(?Clinic $clinic): array
    {
        $stored = ($clinic?->patient_flow_config ?? [])['check_in'] ?? [];
        $all = array_map(fn (VisitPriority $priority) => $priority->value, VisitPriority::cases());
        $priorities = isset($stored['priorities']) && is_array($stored['priorities']) ? array_values(array_intersect($all, $stored['priorities'])) : $all;

        return [
            'visit_reason' => in_array($stored['visit_reason'] ?? null, PatientFieldConfigService::STATES, true) ? $stored['visit_reason'] : PatientFieldConfigService::OPTIONAL,
            'priority' => (bool) ($stored['priority'] ?? true),
            'priorities' => array_values(array_unique([VisitPriority::Normal->value, ...$priorities])),
            'queue' => (bool) ($stored['queue'] ?? true),
            'show_fee' => (bool) ($stored['show_fee'] ?? true),
            'payment' => in_array($stored['payment'] ?? null, [self::PAY_AFTER_CONSULTATION, self::PAY_AT_CHECK_IN], true) ? $stored['payment'] : self::PAY_AFTER_CONSULTATION,
        ];
    }

    /**
     * Whether the desk shows queue positions: the clinic has the queue module
     * (`ClinicModules` — override, plan, add-on, else default) and has not
     * switched positions off. Either way check-in still lists the patient
     * for the doctor.
     */
    public function queueShown(Clinic $clinic): bool
    {
        return $this->checkIn($clinic)['queue'] && app(ClinicModules::class)->enabled($clinic, 'queue');
    }

    /**
     * The priorities the desk may set, in queue order.
     *
     * @return list<VisitPriority>
     */
    public function priorities(?Clinic $clinic): array
    {
        $settings = $this->checkIn($clinic);
        if (! $settings['priority']) {
            return [VisitPriority::Normal];
        }

        return collect($settings['priorities'])->map(fn (string $value) => VisitPriority::from($value))
            ->sortBy(fn (VisitPriority $priority) => [$priority === VisitPriority::Normal ? 0 : 1, $priority->rank()])->values()->all();
    }

    /** The priority a request asked for, if this clinic offers it; normal when none was asked. */
    public function resolvePriority(?Clinic $clinic, ?string $value): VisitPriority
    {
        if ($value === null || $value === '') {
            return VisitPriority::Normal;
        }
        $priority = VisitPriority::tryFrom($value);
        if (! $priority || ! in_array($priority, $this->priorities($clinic), true)) {
            throw ValidationException::withMessages(['priority' => 'That priority is not used at this clinic.']);
        }

        return $priority;
    }

    /**
     * Quick Care at the desk: whether allergy status is asked, whether
     * operational alerts are offered and which roles may set them (null: no
     * limit). Stored under `quick_care`.
     *
     * Vitals are no longer part of the product: settings saved before
     * (`vitals`, `temperature_unit`, `thresholds`) are left as stored and
     * ignored — never read, offered or enforced.
     *
     * @return array{allergy_status: string, alerts: bool, alert_roles: ?list<string>, conditions_from_catalogue: bool}
     */
    public function quickCare(?Clinic $clinic): array
    {
        $stored = ($clinic?->patient_flow_config ?? [])['quick_care'] ?? [];

        return [
            'allergy_status' => in_array($stored['allergy_status'] ?? null, PatientFieldConfigService::STATES, true) ? $stored['allergy_status'] : PatientFieldConfigService::OPTIONAL,
            'alerts' => (bool) ($stored['alerts'] ?? true),
            'alert_roles' => self::list($stored['alert_roles'] ?? null),
            // Off unless the clinic turns it on: then a condition must be one of the clinic's own (`ClinicalCatalogue`).
            'conditions_from_catalogue' => (bool) ($stored['conditions_from_catalogue'] ?? false),
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function list(mixed $value): ?array
    {
        return is_array($value) && $value !== [] ? array_values(array_unique(array_filter($value, 'is_string'))) : null;
    }

    /**
     * @param  array{allergy_status?: string, alerts?: bool, alert_roles?: ?list<string>, conditions_from_catalogue?: bool}  $input
     */
    public function updateQuickCare(Clinic $clinic, array $input, ?User $actor): void
    {
        $before = $this->quickCare($clinic);
        // Keys saved before (old vitals settings) stay as stored; only the current ones change.
        $merged = [
            ...((array) (($clinic->patient_flow_config ?? [])['quick_care'] ?? [])),
            'allergy_status' => $input['allergy_status'] ?? $before['allergy_status'],
            'alerts' => array_key_exists('alerts', $input) ? (bool) $input['alerts'] : $before['alerts'],
            'alert_roles' => array_key_exists('alert_roles', $input) ? $input['alert_roles'] : $before['alert_roles'],
            'conditions_from_catalogue' => array_key_exists('conditions_from_catalogue', $input) ? (bool) $input['conditions_from_catalogue'] : $before['conditions_from_catalogue'],
        ];
        $clinic->update(['patient_flow_config' => [...($clinic->patient_flow_config ?? []), 'quick_care' => $merged]]);
        $after = $this->quickCare($clinic->refresh());

        if ($before !== $after) {
            ClinicSettingAudit::create([
                'clinic_id' => $clinic->id,
                'user_id' => $actor?->id,
                'section' => self::QUICK_CARE_AUDIT_SECTION,
                'old_values' => $before,
                'new_values' => $after,
            ]);
        }
    }

    /**
     * @param  array{visit_reason?: string, priority?: bool, priorities?: list<string>, queue?: bool, show_fee?: bool, payment?: string}  $input
     */
    public function updateCheckIn(Clinic $clinic, array $input, ?User $actor): void
    {
        $before = $this->checkIn($clinic);
        $after = [
            'visit_reason' => in_array($input['visit_reason'] ?? null, PatientFieldConfigService::STATES, true) ? $input['visit_reason'] : $before['visit_reason'],
            'priority' => array_key_exists('priority', $input) ? (bool) $input['priority'] : $before['priority'],
            'priorities' => array_key_exists('priorities', $input) ? array_values(array_unique([VisitPriority::Normal->value, ...array_intersect(array_map(fn (VisitPriority $p) => $p->value, VisitPriority::cases()), (array) $input['priorities'])])) : $before['priorities'],
            'queue' => array_key_exists('queue', $input) ? (bool) $input['queue'] : $before['queue'],
            'show_fee' => array_key_exists('show_fee', $input) ? (bool) $input['show_fee'] : $before['show_fee'],
            'payment' => in_array($input['payment'] ?? null, [self::PAY_AFTER_CONSULTATION, self::PAY_AT_CHECK_IN], true) ? $input['payment'] : $before['payment'],
        ];

        if ($before === $after) {
            return;
        }

        $clinic->update(['patient_flow_config' => [...($clinic->patient_flow_config ?? []), 'check_in' => $after]]);

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::CHECK_IN_AUDIT_SECTION,
            'old_values' => $before,
            'new_values' => $after,
        ]);
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

        // Keep the other sections stored on the same column.
        $clinic->update(['patient_flow_config' => [...($clinic->patient_flow_config ?? []), ...$after]]);

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::AUDIT_SECTION,
            'old_values' => $before,
            'new_values' => $after,
        ]);
    }
}
