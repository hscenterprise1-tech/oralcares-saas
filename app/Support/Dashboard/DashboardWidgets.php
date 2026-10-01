<?php

namespace App\Support\Dashboard;

use App\Support\ClinicAccess\ClinicAccess;
use Throwable;

/**
 * The one definition of the Clinic Portal dashboard: a registry of widgets,
 * not a page per role.
 *
 * Every widget declares what it needs in `ClinicAccess`'s terms (permission,
 * plan module, capability, specialty, branch scope, Owner Mode, practitioner)
 * and which `DashboardData` source fills it. For a user the dashboard is:
 *
 *   eligible() -> every widget whose requirements hold and that the owner
 *                 has not hidden for the user's roles, in order
 *   data()     -> the source of each eligible widget, and nothing else
 *
 * A receptionist, a doctor, an admin, an owner, or someone holding several of
 * those roles at once all get the same engine. Their widgets differ because
 * their permissions do, and a widget appears once however many roles grant
 * it. A widget the user may not see is never queried and never sent.
 *
 * Owner widgets need Owner Mode; they still obey the plan and every other
 * requirement.
 */
class DashboardWidgets
{
    /**
     * kind: kpi | list | stats | facts | trend | actions
     * zone: summary (compact figures across the top) | main (operational
     *       lists) | side (actions, attention, status). Within a zone,
     *       widgets follow `order`; a zone the user has nothing in is not drawn.
     * tone: brand | blue | green | amber | red | violet | slate | ink
     *
     * @var list<array{key: string, title: string, description: string, icon: string, tone: string, kind: string, zone: string, order: int, requires: array<string, mixed>, source?: string, action?: array{label: string, route: string, requires?: array<string, mixed>}, empty?: string}>
     */
    public const WIDGETS = [
        // Summary: the day in four figures.
        [
            'key' => 'patients_today', 'title' => "Today's patients", 'description' => 'Visits registered today',
            'icon' => 'patients', 'tone' => 'brand', 'kind' => 'kpi', 'zone' => 'summary', 'order' => 10,
            'requires' => ['permissions' => ['patient.view', 'visit.view'], 'module' => 'patients'],
            'source' => 'patientsToday',
        ],
        [
            'key' => 'appointments_today', 'title' => 'Appointments', 'description' => 'Booked for today',
            'icon' => 'appointments', 'tone' => 'blue', 'kind' => 'kpi', 'zone' => 'summary', 'order' => 20,
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'source' => 'appointmentsToday',
        ],
        [
            'key' => 'waiting_now', 'title' => 'Waiting', 'description' => 'In the queue now',
            'icon' => 'waiting', 'tone' => 'amber', 'kind' => 'kpi', 'zone' => 'summary', 'order' => 30,
            'requires' => ['permissions' => ['queue.manage']],
            'source' => 'waitingNow',
        ],
        [
            'key' => 'collections_today', 'title' => "Today's collection", 'description' => 'Payments received today',
            'icon' => 'payment', 'tone' => 'green', 'kind' => 'kpi', 'zone' => 'summary', 'order' => 40,
            'requires' => ['permissions' => ['billing.view', 'payment.collect'], 'module' => 'billing'],
            'source' => 'collectionsToday',
        ],

        // Main: compact operational lists.
        [
            'key' => 'todays_patients', 'title' => "Today's patients", 'description' => 'Registered today, latest first',
            'icon' => 'patients', 'tone' => 'brand', 'kind' => 'list', 'zone' => 'main', 'order' => 10,
            'requires' => ['permissions' => ['patient.view', 'visit.view'], 'module' => 'patients'],
            'source' => 'todaysPatients',
            'action' => ['label' => 'View all', 'route' => 'clinic.visits.index', 'requires' => ['permissions' => ['visit.view']]],
            'empty' => 'No patients registered yet today.',
        ],
        [
            'key' => 'todays_appointments', 'title' => "Today's appointments", 'description' => 'Next on the calendar',
            'icon' => 'appointments', 'tone' => 'blue', 'kind' => 'list', 'zone' => 'main', 'order' => 20,
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'source' => 'todaysAppointments',
            'action' => ['label' => 'View all', 'route' => 'clinic.appointments.index'],
            'empty' => 'No appointments booked for today.',
        ],
        [
            'key' => 'my_day', 'title' => 'Your day', 'description' => 'Your patients today',
            'icon' => 'doctor', 'tone' => 'violet', 'kind' => 'stats', 'zone' => 'main', 'order' => 30,
            'requires' => ['practitioner' => true, 'permissions' => ['patient.view']],
            'source' => 'myDay',
            'action' => ['label' => 'My patients', 'route' => 'clinic.patients.index'],
        ],
        [
            'key' => 'my_queue', 'title' => 'Your queue', 'description' => 'Patients waiting for you',
            'icon' => 'waiting', 'tone' => 'violet', 'kind' => 'list', 'zone' => 'main', 'order' => 40,
            'requires' => ['practitioner' => true, 'permissions' => ['clinical.view', 'queue.manage']],
            'source' => 'myQueue',
            'empty' => 'No one is waiting for you right now.',
        ],
        [
            'key' => 'branch_activity', 'title' => 'Branches today', 'description' => 'Activity at each branch',
            'icon' => 'branches', 'tone' => 'blue', 'kind' => 'facts', 'zone' => 'main', 'order' => 50,
            'requires' => ['permissions' => ['branch.manage'], 'branch' => 'clinic_wide', 'multi_branch' => true],
            'source' => 'branchActivity',
            'action' => ['label' => 'Manage branches', 'route' => 'admin.branches.index'],
        ],
        [
            'key' => 'owner_performance', 'title' => 'Clinic performance', 'description' => 'Last 7 days, all branches',
            'icon' => 'trend', 'tone' => 'ink', 'kind' => 'trend', 'zone' => 'main', 'order' => 60,
            'requires' => ['owner_mode' => true, 'permissions' => ['patient.view', 'appointment.view'], 'branch' => 'clinic_wide'],
            'source' => 'ownerPerformance',
        ],

        // Side: what to start, what needs attention, where the cash stands.
        [
            'key' => 'quick_actions', 'title' => 'Quick actions', 'description' => 'Start a task',
            'icon' => 'bolt', 'tone' => 'brand', 'kind' => 'actions', 'zone' => 'side', 'order' => 10,
            'requires' => [],
        ],
        [
            'key' => 'needs_attention', 'title' => 'Needs attention', 'description' => 'Waiting on someone',
            'icon' => 'pending', 'tone' => 'amber', 'kind' => 'list', 'zone' => 'side', 'order' => 20,
            // Shown to anyone who can see at least one kind of item; each item
            // inside checks its own access (see `DashboardData::needsAttention`).
            'requires' => ['permissions' => ['billing.view', 'appointment.view', 'clinical.view', 'queue.manage']],
            'source' => 'needsAttention',
            'empty' => 'Nothing needs attention right now.',
        ],
        [
            'key' => 'investigations', 'title' => 'Investigations', 'description' => 'Lab and imaging work',
            'icon' => 'lab', 'tone' => 'violet', 'kind' => 'stats', 'zone' => 'side', 'order' => 30,
            'requires' => ['permissions' => ['clinical.view', 'queue.manage'], 'capabilities' => ['lab', 'xray']],
            'source' => 'investigations',
        ],
        [
            'key' => 'cash_status', 'title' => 'Cash today', 'description' => 'For daily closing',
            'icon' => 'wallet', 'tone' => 'green', 'kind' => 'facts', 'zone' => 'side', 'order' => 40,
            'requires' => ['permissions' => ['billing.view', 'payment.collect'], 'module' => 'billing'],
            'source' => 'cashStatus',
        ],
        [
            'key' => 'staff_overview', 'title' => 'Team', 'description' => 'Staff across the clinic',
            'icon' => 'staff', 'tone' => 'brand', 'kind' => 'stats', 'zone' => 'side', 'order' => 50,
            'requires' => ['permissions' => ['staff.manage'], 'branch' => 'clinic_wide'],
            'source' => 'staffOverview',
            'action' => ['label' => 'Manage staff', 'route' => 'admin.employees.index'],
        ],
        [
            'key' => 'owner_revenue', 'title' => 'Revenue', 'description' => 'All branches',
            'icon' => 'payment', 'tone' => 'green', 'kind' => 'stats', 'zone' => 'side', 'order' => 60,
            'requires' => ['owner_mode' => true, 'permissions' => ['billing.view'], 'module' => 'billing', 'branch' => 'clinic_wide'],
            'source' => 'ownerRevenue',
        ],
        [
            'key' => 'owner_plan', 'title' => 'Plan & usage', 'description' => 'Your OralCares subscription',
            'icon' => 'plan', 'tone' => 'ink', 'kind' => 'facts', 'zone' => 'side', 'order' => 70,
            'requires' => ['owner_mode' => true],
            'source' => 'ownerPlan',
        ],
        [
            'key' => 'owner_security', 'title' => 'Security', 'description' => 'Sign-in and Owner Mode checks',
            'icon' => 'shield', 'tone' => 'red', 'kind' => 'list', 'zone' => 'side', 'order' => 80,
            'requires' => ['owner_mode' => true],
            'source' => 'ownerSecurity',
            'empty' => 'No security events in the last 7 days.',
        ],
    ];

    /**
     * The widgets this user may receive, in order, with their action link
     * where the user may also follow it. No data.
     *
     * @param  list<array<string, mixed>>  $actions  the user's resolved quick actions
     * @param  array<string, string>  $routeParameters
     * @param  list<array<string, mixed>>|null  $widgets  defaults to `WIDGETS`
     * @return list<array{key: string, title: string, description: string, icon: string, tone: string, kind: string, zone: string, empty: string|null, action: array{label: string, href: string}|null, source: string|null}>
     */
    public static function eligible(ClinicAccess $access, array $actions, array $routeParameters = [], ?array $widgets = null): array
    {
        $widgets ??= self::WIDGETS;
        usort($widgets, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $visibility = app(DashboardVisibility::class);
        $clinic = $access->clinic();
        // The owner's choices narrow what staff see; the owner's own view is never narrowed.
        $roles = $access->isOwner() ? [] : $access->roles();

        $eligible = [];
        foreach ($widgets as $widget) {
            if ($widget['kind'] === 'actions' && $actions === []) {
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

            $action = null;
            if (isset($widget['action']) && $access->allows(['route' => $widget['action']['route']] + ($widget['action']['requires'] ?? []))) {
                $action = ['label' => $widget['action']['label'], 'href' => route($widget['action']['route'], $routeParameters, false)];
            }

            $eligible[$widget['key']] = [
                'key' => $widget['key'],
                'title' => $widget['title'],
                'description' => $widget['description'],
                'icon' => $widget['icon'],
                'tone' => $widget['tone'],
                'kind' => $widget['kind'],
                'zone' => $widget['zone'],
                'empty' => $widget['empty'] ?? null,
                'action' => $action,
                'source' => $widget['source'] ?? null,
            ];
        }

        return array_values($eligible);
    }

    /**
     * Each eligible widget's data, keyed by widget. Only eligible widgets are
     * asked, and a source that fails marks its own widget as failed without
     * taking the rest of the dashboard down.
     *
     * @param  list<array{key: string, source: string|null}>  $eligible
     * @return array<string, array<string, mixed>>
     */
    public static function data(array $eligible, DashboardData $data): array
    {
        $result = [];

        foreach ($eligible as $widget) {
            if ($widget['source'] === null) {
                continue;
            }

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
