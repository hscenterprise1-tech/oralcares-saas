<?php

namespace App;

/**
 * The staff role templates offered on the clinic portal.
 *
 * These are Spatie role *names* (guard `web`, team-scoped by branch via
 * `config('permission.team_foreign_key')`). Keeping them as a typed enum
 * gives the employee role-picker a single list to scope against, instead of
 * every `guard_name = web` role in the system (which also holds every
 * Company-side role).
 */
enum ClinicRole: string
{
    case ClinicAdmin = 'Clinic Admin';
    case Doctor = 'Doctor';
    case Receptionist = 'Receptionist';
    case BillingStaff = 'Billing Staff';

    /**
     * The `ClinicPermission::defaultRolePermissions()` bucket this role draws from.
     */
    public function permissionTemplateKey(): string
    {
        return match ($this) {
            self::ClinicAdmin => 'Admin',
            self::Doctor => 'Practitioner',
            self::Receptionist => 'Reception',
            self::BillingStaff => 'Billing',
        };
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        return ClinicPermission::defaultRolePermissions()[$this->permissionTemplateKey()];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
