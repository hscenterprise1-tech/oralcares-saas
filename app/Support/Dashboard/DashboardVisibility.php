<?php

namespace App\Support\Dashboard;

use App\ClinicRole;
use App\Models\Clinic;
use App\Models\ClinicSettingAudit;
use App\Models\User;

/**
 * The owner's choice of which widgets each staff role's dashboard shows.
 *
 * This is presentation, layered on top of access: a widget appears only if
 * `ClinicAccess` already allows it (plan, permission, capability, specialty,
 * branch) AND the user's roles do not all hide it. A setting here can hide
 * an allowed widget; it can never show one access forbids, because it is
 * consulted only after access has said yes.
 *
 * Stored on `clinics.dashboard_config` as `{"hidden": {"Receptionist": [...]}}`.
 * The owner's own dashboard ignores it.
 */
class DashboardVisibility
{
    public const AUDIT_SECTION = 'dashboard_visibility';

    /**
     * @return array<string, list<string>> hidden widget keys, by role
     */
    public function hidden(Clinic $clinic): array
    {
        $hidden = $clinic->dashboard_config['hidden'] ?? [];

        return collect(ClinicRole::cases())
            ->mapWithKeys(fn (ClinicRole $role) => [$role->value => array_values(array_intersect(self::configurableKeys(), $hidden[$role->value] ?? []))])
            ->all();
    }

    /**
     * Whether a user holding `$roles` should have `$widgetKey` hidden: only
     * when every one of their roles hides it. A person with two roles sees a
     * widget either role shows.
     *
     * @param  list<string>  $roles
     */
    public function hides(Clinic $clinic, array $roles, string $widgetKey): bool
    {
        if ($roles === []) {
            return false;
        }

        $hidden = $this->hidden($clinic);

        foreach ($roles as $role) {
            if (! in_array($widgetKey, $hidden[$role] ?? [], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $hiddenKeys
     */
    public function update(Clinic $clinic, ClinicRole $role, array $hiddenKeys, ?User $actor): void
    {
        $before = $this->hidden($clinic);
        $after = $before;
        $after[$role->value] = array_values(array_unique(array_intersect(self::configurableKeys(), $hiddenKeys)));

        if ($before === $after) {
            return;
        }

        $config = $clinic->dashboard_config ?? [];
        $config['hidden'] = array_filter($after);
        $clinic->update(['dashboard_config' => $config]);

        ClinicSettingAudit::create([
            'clinic_id' => $clinic->id,
            'user_id' => $actor?->id,
            'section' => self::AUDIT_SECTION,
            'old_values' => [$role->value => $before[$role->value]],
            'new_values' => [$role->value => $after[$role->value]],
        ]);
    }

    /**
     * Widgets the owner may switch off for staff: every widget except the
     * owner's own, which no staff role can receive anyway.
     *
     * @return list<string>
     */
    public static function configurableKeys(): array
    {
        return array_values(array_map(
            fn (array $widget) => $widget['key'],
            array_filter(DashboardWidgets::WIDGETS, fn (array $widget) => empty($widget['requires']['owner_mode'])),
        ));
    }
}
