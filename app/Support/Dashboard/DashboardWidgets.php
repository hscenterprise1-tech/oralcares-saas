<?php

namespace App\Support\Dashboard;

use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicActions;
use App\Support\ClinicFrontend;
use Throwable;

/**
 * The one definition of the Clinic Portal dashboard: a registry of widgets,
 * not a page per role.
 *
 * Every widget declares what it needs in `ClinicAccess`'s terms (permission,
 * plan module, capability, specialty extension, branch scope, Owner Mode,
 * practitioner), the `DashboardData` source that fills it, and where it goes:
 * its zone and its order within the zone. For a user the dashboard is:
 *
 *   compose()  -> every widget of the user's role dashboards (`COMPOSITIONS`,
 *                 by workspace) whose requirements hold and that the owner
 *                 has not hidden for the user's roles, zone by zone, in
 *                 order, each zone cut to its limit; plus the quick actions
 *                 whose destination works (`ClinicActions::forDashboard`)
 *   data()     -> the source of each composed widget, and nothing else
 *
 * A receptionist, a doctor, an admin, an owner, or someone holding several of
 * those roles at once all get the same engine. Their dashboards differ
 * because their permissions do, and a widget appears once however many roles
 * grant it. A widget the user may not see is never queried and never sent.
 * The page draws what arrives, by zone and kind; it never decides access.
 *
 * Reporting (trends, mixes, specialty activity, spending) is not here: it
 * belongs to Reports. Neither is anything that only repeats another widget.
 */
class DashboardWidgets
{
    /**
     * Who sees the clinic's money on a dashboard: the Billing route's own
     * audience (whoever runs the clinic, the front desk, billing staff), with
     * the billing plan module. A practitioner's `billing.view` (their own
     * patients' bills) does not open collections or balances.
     */
    public const BILLING_AUDIENCE = ['permissions' => ['billing.view'], 'module' => 'billing', 'workspace' => ['command_center', 'front_desk', 'billing']];

    /** Who works through the day's follow-ups from the dashboard (the widget's own rule). */
    public const FOLLOW_UPS = ['permissions' => ['clinical.edit', 'appointment.create'], 'module' => 'patients', 'branch' => 'active', 'route' => 'clinic.follow-ups.index'];

    /**
     * The dashboard's zones, in reading order. `figures` are the compact
     * figures beside the quick actions, at most `limit` of them (the first
     * eligible by order); `work` is the day's patients and appointments side
     * by side; `payments` what today's visits still owe; `side` what needs
     * someone; `management` the clinic-wide widgets of the Owner/Admin
     * dashboard.
     */
    public const ZONES = [
        'figures' => ['limit' => 4],
        'work' => ['limit' => null],
        'payments' => ['limit' => null],
        'side' => ['limit' => null],
        'management' => ['limit' => null],
    ];

    /**
     * Each role's dashboard, by the workspace that role works in
     * (`ClinicAccess::workspaces()`, decided by permissions, never by a role
     * name): which widgets it is made of. A user with several workspaces gets
     * their union, each widget once, in the registry's zone and order. Every
     * widget then still has to pass its own requirements, so a composition
     * only ever narrows what a user's access allows. Someone with no
     * workspace gets the `general` dashboard.
     *
     *   front_desk      Reception: the day's figures, patients, bookings, dues
     *   today           Doctor: their own queue and work, no money or admin
     *   billing         Billing: collections and today's dues
     *   command_center  Owner/Admin: the front office's view plus the clinic's
     *                   management widgets (money, branches, security)
     *
     * @var array<string, list<string>>
     */
    public const COMPOSITIONS = [
        'front_desk' => [
            'patients_today', 'waiting_now', 'with_doctor_now', 'collections_today', 'appointments_today',
            'todays_patients', 'upcoming_appointments',
            'pending_payments',
            'needs_attention', 'follow_ups_due',
        ],
        'today' => [
            'my_waiting', 'my_seen', 'my_notes', 'appointments_today',
            'my_queue', 'upcoming_appointments',
            'needs_attention', 'follow_ups_due',
        ],
        'billing' => [
            'collections_today', 'patients_today',
            'pending_payments',
            'needs_attention',
        ],
        'command_center' => [
            'patients_today', 'waiting_now', 'with_doctor_now', 'collections_today', 'appointments_today',
            'todays_patients', 'upcoming_appointments',
            'pending_payments',
            'needs_attention', 'follow_ups_due',
            'owner_finance', 'branch_activity', 'owner_security',
        ],
        'general' => [
            'patients_today', 'appointments_today',
            'upcoming_appointments',
        ],
    ];

    /**
     * kind: kpi | patients | appointments | dues | follow_ups | attention | list | stats | facts
     * tone: brand | blue | green | amber | red | violet | slate | ink
     * hide_when_empty: the widget is left out once its data shows nothing to act on.
     *
     * @var list<array{key: string, title: string, description: string, icon: string, tone: string, kind: string, zone: string, order: int, requires: array<string, mixed>, source: string, action?: array{label: string, route: string, query?: array<string, string>, requires?: array<string, mixed>}, empty?: string, hide_when_empty?: bool}>
     */
    public const WIDGETS = [
        // Figures: the day in at most four numbers, the user's own work first,
        // then the front office's: patients, waiting, with a doctor, and money
        // (or appointments for whoever does not see the money).
        [
            'key' => 'my_waiting', 'title' => 'Waiting for you', 'description' => 'Your patients and walk-ins for any doctor',
            'icon' => 'waiting', 'tone' => 'amber', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 1,
            'requires' => ['practitioner' => true, 'permissions' => ['clinical.view'], 'branch' => 'active'],
            'source' => 'myWaiting',
        ],
        [
            'key' => 'my_seen', 'title' => 'Seen today', 'description' => 'Your consultations finished today',
            'icon' => 'doctor', 'tone' => 'green', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 2,
            'requires' => ['practitioner' => true, 'permissions' => ['clinical.view'], 'branch' => 'active'],
            'source' => 'mySeen',
        ],
        [
            'key' => 'my_notes', 'title' => 'Notes to finish', 'description' => "Today's consultations not yet signed",
            'icon' => 'notes', 'tone' => 'violet', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 3,
            'requires' => ['practitioner' => true, 'permissions' => ['clinical.view'], 'branch' => 'active'],
            'source' => 'myNotes',
        ],
        [
            'key' => 'patients_today', 'title' => 'Patients today', 'description' => 'Visits registered today',
            'icon' => 'patients', 'tone' => 'brand', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 10,
            'requires' => ['permissions' => ['patient.view', 'visit.view'], 'module' => 'patients'],
            'source' => 'patientsToday',
        ],
        [
            'key' => 'waiting_now', 'title' => 'Waiting', 'description' => 'In the queue now',
            'icon' => 'waiting', 'tone' => 'amber', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 20,
            'requires' => ['permissions' => ['queue.manage'], 'workspace' => ['front_desk', 'command_center']],
            'source' => 'waitingNow',
        ],
        [
            'key' => 'with_doctor_now', 'title' => 'With doctor', 'description' => 'In consultation now',
            'icon' => 'doctor', 'tone' => 'violet', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 30,
            'requires' => ['permissions' => ['queue.manage'], 'workspace' => ['front_desk', 'command_center']],
            'source' => 'withDoctorNow',
        ],
        [
            'key' => 'collections_today', 'title' => 'Collections', 'description' => 'Net collected today',
            'icon' => 'payment', 'tone' => 'green', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 40,
            'requires' => self::BILLING_AUDIENCE,
            'source' => 'collectionsToday',
        ],
        [
            'key' => 'appointments_today', 'title' => 'Appointments', 'description' => 'Booked for today',
            'icon' => 'appointments', 'tone' => 'blue', 'kind' => 'kpi', 'zone' => 'figures', 'order' => 50,
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'source' => 'appointmentsToday',
        ],

        // Work: today's patients and today's appointments, side by side.
        [
            'key' => 'my_queue', 'title' => 'Waiting for you', 'description' => 'Your patients and walk-ins for any doctor',
            'icon' => 'waiting', 'tone' => 'amber', 'kind' => 'list', 'zone' => 'work', 'order' => 5,
            'requires' => ['practitioner' => true, 'permissions' => ['clinical.view'], 'branch' => 'active'],
            'source' => 'myQueue',
            'empty' => 'No one is waiting for you.',
        ],
        [
            'key' => 'todays_patients', 'title' => "Today's patients", 'description' => 'Checked in today at this branch, as the Front Desk sees them',
            'icon' => 'patients', 'tone' => 'brand', 'kind' => 'patients', 'zone' => 'work', 'order' => 10,
            'requires' => ['workspace' => ['front_desk'], 'module' => 'patients', 'branch' => 'active'],
            'source' => 'todaysPatients',
            'action' => ['label' => 'View all', 'route' => 'clinic.reception'],
            'empty' => 'No patients checked in yet today.',
        ],
        [
            'key' => 'upcoming_appointments', 'title' => 'Appointments', 'description' => 'Booked for today, not yet arrived',
            'icon' => 'appointments', 'tone' => 'blue', 'kind' => 'appointments', 'zone' => 'work', 'order' => 20,
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'source' => 'upcomingAppointments',
            // The full list: today's day view on the Calendar, with its own search.
            'action' => ['label' => 'View all', 'route' => 'clinic.appointments.index', 'query' => ['view' => 'day']],
            'empty' => 'No one else is booked to arrive today.',
        ],

        // Payments: what today's visits still owe, for whoever handles the money.
        [
            'key' => 'pending_payments', 'title' => "Today's pending payments", 'description' => "Balances still due on today's visits",
            'icon' => 'wallet', 'tone' => 'violet', 'kind' => 'dues', 'zone' => 'payments', 'order' => 10,
            'requires' => self::BILLING_AUDIENCE,
            'source' => 'pendingPayments',
            'action' => ['label' => 'View all', 'route' => 'clinic.billing.index'],
            'empty' => "Nothing is due from today's patients.",
        ],

        // Side: what is waiting on someone, only when something is.
        [
            'key' => 'needs_attention', 'title' => 'Needs attention', 'description' => 'Waiting on someone',
            'icon' => 'attention', 'tone' => 'amber', 'kind' => 'attention', 'zone' => 'side', 'order' => 10,
            // Shown to anyone who could have an item; each item inside checks its
            // own access (see `DashboardData::needsAttention`).
            'requires' => ['permissions' => ['clinical.view', 'queue.manage', 'appointment.manage']],
            'source' => 'needsAttention',
            'empty' => 'Nothing needs attention right now.',
            'hide_when_empty' => true,
        ],
        [
            'key' => 'follow_ups_due', 'title' => 'Follow-ups due', 'description' => 'Due today or overdue, not yet booked',
            'icon' => 'follow-ups', 'tone' => 'violet', 'kind' => 'follow_ups', 'zone' => 'side', 'order' => 20,
            'requires' => self::FOLLOW_UPS,
            'source' => 'followUpsDue',
            'action' => ['label' => 'View all', 'route' => 'clinic.follow-ups.index'],
            'empty' => 'No follow-ups are due.',
            'hide_when_empty' => true,
        ],

        // Management: the clinic as a whole, on the Owner/Admin dashboard only.
        [
            'key' => 'owner_finance', 'title' => 'Money · all branches', 'description' => 'Billed, net collected, outstanding, overdue, clinic-wide',
            'icon' => 'wallet', 'tone' => 'green', 'kind' => 'stats', 'zone' => 'management', 'order' => 10,
            'requires' => ['owner_mode' => true, 'permissions' => ['billing.view'], 'module' => 'billing', 'branch' => 'clinic_wide'],
            'source' => 'ownerFinance',
            'action' => ['label' => 'Revenue report', 'route' => 'clinic.reports.index'],
        ],
        [
            'key' => 'branch_activity', 'title' => 'Branches today', 'description' => 'Activity at each branch',
            'icon' => 'branches', 'tone' => 'blue', 'kind' => 'facts', 'zone' => 'management', 'order' => 20,
            'requires' => ['permissions' => ['branch.manage'], 'branch' => 'clinic_wide', 'multi_branch' => true],
            'source' => 'branchActivity',
            'action' => ['label' => 'Manage branches', 'route' => 'admin.branches.index'],
        ],
        [
            'key' => 'owner_security', 'title' => 'Security', 'description' => 'Sign-in and Owner Mode checks, last 7 days',
            'icon' => 'shield', 'tone' => 'red', 'kind' => 'list', 'zone' => 'management', 'order' => 30,
            'requires' => ['owner_mode' => true],
            'source' => 'ownerSecurity',
            'empty' => 'No security events in the last 7 days.',
        ],
    ];

    /**
     * The dashboard for this user: the widgets, zone by zone, and the quick
     * actions. Nothing here is data.
     *
     * @param  array<string, string>  $routeParameters
     * @return array{widgets: list<array<string, mixed>>, actions: list<array<string, mixed>>}
     */
    public static function compose(ClinicAccess $access, array $routeParameters = []): array
    {
        return [
            'widgets' => self::eligible($access, $routeParameters),
            'actions' => ClinicActions::forDashboard($access, $routeParameters),
        ];
    }

    /**
     * The widgets this user may receive, zone by zone, in order, each zone cut
     * to its limit, with their action link where the user may also follow it.
     * No data.
     *
     * @param  array<string, string>  $routeParameters
     * @param  list<array<string, mixed>>|null  $widgets  defaults to `WIDGETS`
     * @return list<array{key: string, title: string, description: string, icon: string, tone: string, kind: string, zone: string, empty: string|null, hide_when_empty: bool, action: array{label: string, href: string}|null, source: string}>
     */
    public static function eligible(ClinicAccess $access, array $routeParameters = [], ?array $widgets = null): array
    {
        // Only the widgets of this user's dashboards (their workspaces' compositions).
        $composed = self::composition($access);
        $widgets = array_values(array_filter($widgets ?? self::WIDGETS, fn (array $widget) => in_array($widget['key'], $composed, true)));
        $zones = array_keys(self::ZONES);
        usort($widgets, fn (array $a, array $b) => [array_search($a['zone'], $zones, true), $a['order']] <=> [array_search($b['zone'], $zones, true), $b['order']]);

        $visibility = app(DashboardVisibility::class);
        $clinic = $access->clinic();
        // The owner's choices narrow what staff see; the owner's own view is never narrowed.
        $roles = $access->isOwner() ? [] : $access->roles();

        $eligible = [];
        $perZone = [];
        foreach ($widgets as $widget) {
            $limit = self::ZONES[$widget['zone']]['limit'];
            if ($limit !== null && ($perZone[$widget['zone']] ?? 0) >= $limit) {
                continue;
            }

            // Access first: plan, permission, capability, specialty, branch, Owner Mode.
            if (! $access->allows($widget['requires'])) {
                continue;
            }

            // Then the owner's dashboard choice, which can hide but never grant.
            if ($clinic && $visibility->hides($clinic, $roles, $widget['key'])) {
                continue;
            }

            $perZone[$widget['zone']] = ($perZone[$widget['zone']] ?? 0) + 1;
            $eligible[] = [
                'key' => $widget['key'],
                'title' => $widget['title'],
                'description' => $widget['description'],
                'icon' => $widget['icon'],
                'tone' => $widget['tone'],
                'kind' => $widget['kind'],
                'zone' => $widget['zone'],
                'empty' => $widget['empty'] ?? null,
                'hide_when_empty' => $widget['hide_when_empty'] ?? false,
                'action' => isset($widget['action']) ? self::link($access, $widget['action'], $routeParameters) : null,
                'source' => $widget['source'],
            ];
        }

        return $eligible;
    }

    /**
     * The widget keys this user's dashboards are made of: the union of their
     * workspaces' compositions, or the general one when they have none.
     *
     * @return list<string>
     */
    public static function composition(ClinicAccess $access): array
    {
        $workspaces = array_values(array_intersect($access->workspaces(), array_keys(self::COMPOSITIONS)));

        return array_values(array_unique(array_merge(...array_map(
            fn (string $workspace) => self::COMPOSITIONS[$workspace],
            $workspaces !== [] ? $workspaces : ['general'],
        ))));
    }

    /**
     * A link only to a page that exists (`ClinicFrontend`) and that the user may also open.
     *
     * @param  array{label: string, route: string, query?: array<string, string>, requires?: array<string, mixed>}  $action
     * @param  array<string, string>  $routeParameters
     * @return array{label: string, href: string}|null
     */
    private static function link(ClinicAccess $access, array $action, array $routeParameters): ?array
    {
        if (! ClinicFrontend::available($action['route']) || ! $access->allows(['route' => $action['route']] + ($action['requires'] ?? []))) {
            return null;
        }

        return ['label' => $action['label'], 'href' => route($action['route'], $routeParameters + ($action['query'] ?? []), false)];
    }

    /**
     * Each composed widget's data, keyed by widget. Only composed widgets are
     * asked, and a source that fails marks its own widget as failed without
     * taking the rest of the dashboard down.
     *
     * @param  list<array{key: string, source: string}>  $eligible
     * @return array<string, array<string, mixed>>
     */
    public static function data(array $eligible, DashboardData $data): array
    {
        $result = [];

        foreach ($eligible as $widget) {
            try {
                $result[$widget['key']] = $data->source($widget['source']);
            } catch (Throwable $exception) {
                report($exception);
                $result[$widget['key']] = ['error' => true];
            }
        }

        return $result;
    }

    /**
     * The definitions as the browser receives them: without the server-side
     * source name.
     *
     * @param  list<array<string, mixed>>  $eligible
     * @return list<array<string, mixed>>
     */
    public static function forBrowser(array $eligible): array
    {
        return array_map(function (array $widget): array {
            unset($widget['source']);

            return $widget;
        }, $eligible);
    }
}
