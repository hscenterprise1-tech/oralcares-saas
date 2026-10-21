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
     * The plan seat a login with this role takes: a doctor seat or a staff
     * seat. Seats are counted from this mapping, never from a role's name.
     */
    public function seat(): string
    {
        return match ($this) {
            self::Doctor => self::DOCTOR_SEAT,
            self::ClinicAdmin, self::Receptionist, self::BillingStaff => self::STAFF_SEAT,
        };
    }

    public const DOCTOR_SEAT = 'doctor';

    public const STAFF_SEAT = 'staff';

    /**
     * The role names whose logins take a doctor seat.
     *
     * @return list<string>
     */
    public static function doctorSeatRoles(): array
    {
        return array_values(array_map(fn (self $role) => $role->value, array_filter(self::cases(), fn (self $role) => $role->seat() === self::DOCTOR_SEAT)));
    }

    /**
     * Whether any of these role names takes a doctor seat.
     *
     * @param  iterable<string>  $roleNames
     */
    public static function takesDoctorSeat(iterable $roleNames): bool
    {
        $doctorRoles = self::doctorSeatRoles();
        foreach ($roleNames as $name) {
            if (in_array($name, $doctorRoles, true)) {
                return true;
            }
        }

        return false;
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
