<?php

namespace App\Support;

use App\Support\ClinicAccess\ClinicAccess;
use Illuminate\Support\Facades\Route;

/**
 * The role workspaces: one primary daily surface per responsibility.
 *
 * - Command Center: the owner's or administrator's view of the clinic.
 * - Reception: the front office runs the day's patients from it (the Reception Control Center).
 * - Today: a practitioner's patients and clinical work.
 * - Billing: the work center of someone whose job is billing alone.
 *
 * Which ones a user has is `ClinicAccess::workspaces()` (the one rule
 * engine); this registry only names them and says where each one lives.
 * `clinic.dashboard` opens the first (primary) one; a user with more than one
 * switches between them from the shell.
 */
class ClinicWorkspaces
{
    public const WORKSPACES = [
        'command_center' => ['label' => 'Command Center', 'icon' => 'command-center', 'route' => 'clinic.dashboard'],
        'front_desk' => ['label' => 'Reception', 'icon' => 'front-desk', 'route' => 'clinic.reception'],
        'today' => ['label' => 'Today', 'icon' => 'doctor', 'route' => 'clinic.consultations.index'],
        'billing' => ['label' => 'Billing', 'icon' => 'billing', 'route' => 'clinic.billing.index'],
    ];

    /**
     * The user's workspaces, primary first.
     *
     * @param  array<string, string>  $routeParameters
     * @return list<array{key: string, label: string, icon: string, href: string, primary: bool}>
     */
    public static function for(ClinicAccess $access, array $routeParameters = []): array
    {
        $resolved = [];
        foreach ($access->workspaces() as $index => $key) {
            $workspace = self::WORKSPACES[$key];
            if (! Route::has($workspace['route'])) {
                continue;
            }
            $resolved[] = [
                'key' => $key,
                'label' => $workspace['label'],
                'icon' => $workspace['icon'],
                'href' => route($workspace['route'], $routeParameters, false),
                'primary' => $index === 0,
            ];
        }

        return $resolved;
    }

    /**
     * Where a signed-in user lands (after login, onboarding, an invitation, or
     * the logo). There is no separate staff dashboard any more:
     *
     * - whoever runs the clinic: the Command Center (`clinic.dashboard`);
     * - the front office: the Front Desk, reception's one dashboard;
     * - billing staff: the Billing page;
     * - a practitioner, and anyone else: the Appointment Calendar, else Patients, else Notifications.
     *
     * @param  array<string, string>  $routeParameters
     */
    public static function landing(ClinicAccess $access, array $routeParameters = []): string
    {
        return route(self::home($access), $routeParameters);
    }

    /** The route name of this user's home (see `landing`); never `clinic.dashboard` unless the Command Center applies. */
    public static function home(ClinicAccess $access): string
    {
        $built = fn (string $route): bool => Route::has($route) && ClinicFrontend::available($route);

        return match (true) {
            self::commandCenter($access) => 'clinic.dashboard',
            self::receptionHome($access) => 'clinic.reception',
            self::doctorHome($access) => 'clinic.consultations.index',
            $access->primaryWorkspace() === 'billing' && $built('clinic.billing.index') => 'clinic.billing.index',
            $built('clinic.appointments.index') && $access->allows(ClinicNavigation::CALENDAR) => 'clinic.appointments.index',
            $built('clinic.patients.index') && $access->allows(['permissions' => ['patient.view'], 'module' => 'patients']) => 'clinic.patients.index',
            default => 'clinic.notifications.index',
        };
    }

    /** Whether this user has the Command Center, the one page `clinic.dashboard` still shows (`OwnerCommandCenter::appliesTo`). */
    public static function commandCenter(ClinicAccess $access): bool
    {
        return in_array('command_center', $access->workspaces(), true);
    }

    /**
     * Whether this user's dashboard is the Front Desk: their primary
     * workspace is the front office (whoever runs the clinic has the Command
     * Center first). The sidebar's Dashboard is then the Front Desk.
     */
    public static function receptionHome(ClinicAccess $access): bool
    {
        return ! self::commandCenter($access) && $access->primaryWorkspace() === 'front_desk' && Route::has('clinic.reception') && ClinicFrontend::available('clinic.reception');
    }

    /**
     * Whether this user's dashboard is the Doctor Dashboard (Today): a
     * practitioner whose primary workspace is their day of patients. The
     * sidebar's Dashboard is then that page; Appointments stays the calendar.
     */
    public static function doctorHome(ClinicAccess $access): bool
    {
        return ! self::commandCenter($access) && ! self::receptionHome($access) && $access->primaryWorkspace() === 'today'
            && Route::has('clinic.consultations.index') && ClinicFrontend::available('clinic.consultations.index');
    }
}
