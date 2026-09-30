<?php

namespace App\Support;

use App\ClinicPermission;
use Illuminate\Support\Facades\Route;

/**
 * The one definition of the Clinic Portal's navigation.
 *
 * Every destination the portal can offer is declared here once, with the
 * route it opens, the permission a user needs and the plan module the clinic
 * needs. `for()` resolves that list against what the server itself would
 * allow, so the top navigation, the mobile menu and the command palette all
 * read one resolved list and can never offer a page the route would refuse.
 *
 * Hiding a destination is presentation only. Each route keeps its own
 * `clinic_permission:*` / `clinic_entitled:*` middleware or policy, and that
 * is what actually protects it.
 *
 * A destination whose route does not exist yet (Queue, Clinical, Billing,
 * Inventory, Reports) is declared but never shown. It appears the moment its
 * route is registered, and not before, so no link can lead to a 404.
 */
class ClinicNavigation
{
    /**
     * @var list<array{key: string, label: string, section: 'primary'|'admin', route: string, permissions: list<ClinicPermission>, module: string|null, keywords: list<string>}>
     */
    private const DESTINATIONS = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'section' => 'primary', 'route' => 'clinic.dashboard', 'permissions' => [], 'module' => null, 'keywords' => ['home', 'today', 'overview']],
        ['key' => 'reception', 'label' => 'Reception', 'section' => 'primary', 'route' => 'clinic.reception', 'permissions' => [ClinicPermission::QueueManage], 'module' => null, 'keywords' => ['front desk', 'check-in', 'walk-in']],
        ['key' => 'queue', 'label' => 'Queue', 'section' => 'primary', 'route' => 'clinic.queue.index', 'permissions' => [ClinicPermission::QueueManage], 'module' => null, 'keywords' => ['waiting', 'tokens']],
        ['key' => 'appointments', 'label' => 'Appointments', 'section' => 'primary', 'route' => 'clinic.appointments.index', 'permissions' => [ClinicPermission::AppointmentView], 'module' => 'appointments', 'keywords' => ['calendar', 'schedule', 'booking']],
        ['key' => 'patients', 'label' => 'Patients', 'section' => 'primary', 'route' => 'clinic.patients.index', 'permissions' => [ClinicPermission::PatientView], 'module' => 'patients', 'keywords' => ['directory', 'records']],
        ['key' => 'visits', 'label' => 'Visits', 'section' => 'primary', 'route' => 'clinic.visits.index', 'permissions' => [ClinicPermission::VisitView], 'module' => null, 'keywords' => ['encounters', 'consultations']],
        ['key' => 'clinical', 'label' => 'Clinical', 'section' => 'primary', 'route' => 'clinic.clinical.index', 'permissions' => [ClinicPermission::ClinicalView], 'module' => null, 'keywords' => ['notes', 'treatment']],
        ['key' => 'billing', 'label' => 'Billing', 'section' => 'primary', 'route' => 'clinic.billing.index', 'permissions' => [ClinicPermission::BillingView], 'module' => 'billing', 'keywords' => ['invoices', 'payments']],
        ['key' => 'inventory', 'label' => 'Inventory', 'section' => 'primary', 'route' => 'clinic.inventory.index', 'permissions' => [], 'module' => 'inventory', 'keywords' => ['stock', 'supplies']],
        ['key' => 'reports', 'label' => 'Reports', 'section' => 'primary', 'route' => 'clinic.reports.index', 'permissions' => [ClinicPermission::ReportsView], 'module' => null, 'keywords' => ['analytics']],
        ['key' => 'staff', 'label' => 'Staff', 'section' => 'admin', 'route' => 'admin.employees.index', 'permissions' => [ClinicPermission::StaffManage], 'module' => null, 'keywords' => ['team', 'employees', 'doctors', 'invitations']],
        ['key' => 'branches', 'label' => 'Branches', 'section' => 'admin', 'route' => 'admin.branches.index', 'permissions' => [ClinicPermission::BranchManage], 'module' => null, 'keywords' => ['locations']],
        ['key' => 'departments', 'label' => 'Departments', 'section' => 'admin', 'route' => 'admin.departments.index', 'permissions' => [ClinicPermission::BranchManage], 'module' => null, 'keywords' => []],
        ['key' => 'rooms', 'label' => 'Rooms', 'section' => 'admin', 'route' => 'admin.rooms.index', 'permissions' => [ClinicPermission::BranchManage], 'module' => null, 'keywords' => ['chairs']],
        ['key' => 'settings', 'label' => 'Settings', 'section' => 'admin', 'route' => 'admin.settings.index', 'permissions' => [ClinicPermission::SettingsManage], 'module' => null, 'keywords' => ['preferences', 'configuration']],
    ];

    /**
     * The destinations this user may open, in display order.
     *
     * @param  list<string>  $grantedPermissions  the user's effective clinic permissions at the active branch
     * @param  array{has_subscription: bool, modules: list<string>}  $entitlements
     * @param  array<string, string>  $routeParameters  e.g. the `{clinic}` host parameter in domain mode
     * @return list<array{key: string, label: string, section: 'primary'|'admin', href: string, keywords: list<string>}>
     */
    public static function for(array $grantedPermissions, array $entitlements, array $routeParameters = []): array
    {
        $resolved = [];

        foreach (self::DESTINATIONS as $destination) {
            if (! Route::has($destination['route'])) {
                continue;
            }

            if (! self::grants($destination['permissions'], $grantedPermissions)) {
                continue;
            }

            if (! self::entitled($destination['module'], $entitlements)) {
                continue;
            }

            $resolved[] = [
                'key' => $destination['key'],
                'label' => $destination['label'],
                'section' => $destination['section'],
                'href' => route($destination['route'], $routeParameters, false),
                'keywords' => $destination['keywords'],
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<ClinicPermission>  $required  any one of them is enough
     * @param  list<string>  $granted
     */
    private static function grants(array $required, array $granted): bool
    {
        if ($required === []) {
            return true;
        }

        foreach ($required as $permission) {
            if (in_array($permission->value, $granted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors `EnsureClinicEntitled`: a clinic with no subscription predates
     * plans and is not held to one; every other clinic gets only its modules.
     *
     * @param  array{has_subscription: bool, modules: list<string>}  $entitlements
     */
    private static function entitled(?string $module, array $entitlements): bool
    {
        if ($module === null || ! $entitlements['has_subscription']) {
            return true;
        }

        return in_array($module, $entitlements['modules'], true);
    }
}
