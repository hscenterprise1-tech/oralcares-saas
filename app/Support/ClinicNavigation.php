<?php

namespace App\Support;

use App\Support\ClinicAccess\ClinicAccess;

/**
 * The one definition of the Clinic Portal's navigation: business domains,
 * not pages.
 *
 * Each domain declares what it needs (see `ClinicAccess` for the requirement
 * keys) and `for()` keeps only those this user may open right now. The top
 * navigation, the mobile menu and the command palette all render this one
 * resolved list; nothing in React declares a destination of its own.
 *
 * Reception, visits, check-in, walk-ins and the queue are workflows, not
 * domains: they surface as quick actions and dashboard widgets
 * (`ClinicActions`, `Dashboard\DashboardWidgets`), never here. There is no
 * "More" and no "Workspace": every domain the user may open is shown, and one
 * they may not is absent.
 *
 * A domain whose route is not registered yet (Orders, Billing, Reports) is
 * declared but never shown, so no link can lead to a missing page. A domain
 * without its own route (Administration) shows when any child does.
 *
 * Hiding is presentation; every route still protects itself.
 */
class ClinicNavigation
{
    /**
     * @var list<array{key: string, label: string, icon: string, order: int, route?: string, requires?: array<string, mixed>, hidden_in_owner_mode?: bool, keywords?: list<string>, children?: list<array<string, mixed>>}>
     */
    public const DOMAINS = [
        [
            'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'order' => 10,
            'route' => 'clinic.dashboard', 'requires' => [],
            'keywords' => ['home', 'today', 'overview'],
        ],
        [
            'key' => 'patients', 'label' => 'Patients', 'icon' => 'patients', 'order' => 20,
            'route' => 'clinic.patients.index',
            'requires' => ['permissions' => ['patient.view'], 'module' => 'patients'],
            'keywords' => ['directory', 'records', 'cases'],
        ],
        [
            'key' => 'appointments', 'label' => 'Appointments', 'icon' => 'appointments', 'order' => 30,
            'route' => 'clinic.appointments.index',
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'keywords' => ['calendar', 'schedule', 'booking'],
        ],
        [
            'key' => 'orders', 'label' => 'Orders', 'icon' => 'orders', 'order' => 40,
            'route' => 'clinic.orders.index',
            'requires' => ['permissions' => ['clinical.view'], 'capabilities' => ['lab', 'xray']],
            'keywords' => ['lab', 'x-ray', 'radiology', 'diagnostics'],
        ],
        [
            'key' => 'billing', 'label' => 'Billing', 'icon' => 'billing', 'order' => 50,
            'route' => 'clinic.billing.index',
            'requires' => ['permissions' => ['billing.view'], 'module' => 'billing'],
            'keywords' => ['invoices', 'payments', 'collections'],
        ],
        [
            'key' => 'reports', 'label' => 'Reports', 'icon' => 'reports', 'order' => 60,
            'route' => 'clinic.reports.index',
            'requires' => ['permissions' => ['reports.view']],
            'keywords' => ['analytics', 'insights'],
        ],
        [
            'key' => 'administration', 'label' => 'Administration', 'icon' => 'administration', 'order' => 90,
            'keywords' => ['admin', 'setup'],
            'children' => [
                ['key' => 'staff', 'label' => 'Staff', 'icon' => 'staff', 'order' => 10, 'route' => 'admin.employees.index', 'requires' => ['permissions' => ['staff.manage']], 'keywords' => ['team', 'employees', 'doctors', 'invitations']],
                ['key' => 'leave', 'label' => 'Leave', 'icon' => 'leave', 'order' => 15, 'route' => 'clinic.leave.index', 'requires' => ['permissions' => ['leave.approve']], 'keywords' => ['time off', 'holiday', 'absence', 'approval']],
                ['key' => 'branches', 'label' => 'Branches', 'icon' => 'branches', 'order' => 20, 'route' => 'admin.branches.index', 'requires' => ['permissions' => ['branch.manage']], 'keywords' => ['locations']],
                ['key' => 'departments', 'label' => 'Departments', 'icon' => 'departments', 'order' => 30, 'route' => 'admin.departments.index', 'requires' => ['permissions' => ['branch.manage']], 'keywords' => []],
                ['key' => 'rooms', 'label' => 'Rooms', 'icon' => 'rooms', 'order' => 40, 'route' => 'admin.rooms.index', 'requires' => ['permissions' => ['branch.manage']], 'keywords' => ['chairs', 'operatory']],
                ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'order' => 50, 'route' => 'admin.settings.index', 'requires' => ['permissions' => ['settings.manage']], 'keywords' => ['preferences', 'configuration']],
            ],
        ],
    ];

    /**
     * The domains this user may open, in order, each with its href and the
     * children it may open.
     *
     * @param  array<string, string>  $routeParameters  e.g. the `{clinic}` host parameter in domain mode
     * @param  list<array<string, mixed>>|null  $domains  defaults to `DOMAINS`
     * @return list<array{key: string, label: string, icon: string, href: string, keywords: list<string>, children: list<array{key: string, label: string, icon: string, href: string, keywords: list<string>}>}>
     */
    public static function for(ClinicAccess $access, array $routeParameters = [], ?array $domains = null): array
    {
        $resolved = [];

        foreach (self::sorted($domains ?? self::DOMAINS) as $domain) {
            $children = [];
            foreach (self::sorted($domain['children'] ?? []) as $child) {
                if (self::visible($access, $child)) {
                    $children[] = self::item($child, $routeParameters);
                }
            }

            $hasOwnRoute = isset($domain['route']);
            if ($hasOwnRoute ? ! self::visible($access, $domain) : ($children === [] || ! self::visible($access, $domain))) {
                continue;
            }

            $resolved[] = [
                'key' => $domain['key'],
                'label' => $domain['label'],
                'icon' => $domain['icon'],
                'href' => $hasOwnRoute ? route($domain['route'], $routeParameters, false) : $children[0]['href'],
                'keywords' => $domain['keywords'] ?? [],
                'children' => $children,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function visible(ClinicAccess $access, array $entry): bool
    {
        if (! empty($entry['hidden_in_owner_mode']) && $access->ownerModeActive()) {
            return false;
        }

        $requirement = $entry['requires'] ?? [];
        if (isset($entry['route'])) {
            $requirement['route'] = $entry['route'];
        }

        return $access->allows($requirement);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, string>  $routeParameters
     * @return array{key: string, label: string, icon: string, href: string, keywords: list<string>}
     */
    private static function item(array $entry, array $routeParameters): array
    {
        return [
            'key' => $entry['key'],
            'label' => $entry['label'],
            'icon' => $entry['icon'],
            'href' => route($entry['route'], $routeParameters, false),
            'keywords' => $entry['keywords'] ?? [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $entries): array
    {
        usort($entries, fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $entries;
    }
}
