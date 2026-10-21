<?php

namespace App\Support;

/**
 * Which Clinic Portal destinations have a frontend.
 *
 * The old Clinic frontend was removed for a rebuild (2026-10-11). Every route
 * still answers on the server, but a destination is only linked as a working
 * page once its new screen exists; until then the navigation shows it as
 * coming soon and dashboard widgets leave out links to it. Add a route name
 * here when its new page lands.
 */
class ClinicFrontend
{
    /** @var list<string> */
    public const AVAILABLE = [
        'clinic.dashboard',
        'clinic.appointments.index',
        'clinic.patients.index',
        'clinic.patients.create',
        'admin.settings.patient-form',
        'clinic.notifications.index',
        'clinic.support.index',
    ];

    public static function available(string $routeName): bool
    {
        return in_array($routeName, self::AVAILABLE, true);
    }
}
