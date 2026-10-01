<?php

namespace App\Support\Dashboard;

use App\Support\ClinicAccess\ClinicAccess;
use App\Support\ClinicActions;
use Throwable;

/**
 * The one definition of the Clinic Portal dashboard: a registry of widgets,
 * not a page per role.
 *
 * Every widget declares what it needs in `ClinicAccess`'s terms (permission,
 * plan module, capability, specialty, branch scope, Owner Mode, practitioner)
 * and which `DashboardData` source fills it. For a user the dashboard is:
 *
 *   eligible() -> every widget whose requirements hold, in order
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
    /** Specialties that register patients as new and repeat cases. */
    private const CASE_SPECIALTIES = ClinicActions::CASE_SPECIALTIES;

    /**
     * kind: kpi | list | flow | stats | breakdown | trend | facts | actions
     * span: 1 (a quarter on wide screens) | 2 (half) | 4 (full width)
     * tone: brand | blue | green | amber | red | violet | slate | ink
     *
     * @var list<array{key: string, title: string, description: string, icon: string, tone: string, kind: string, span: int, order: int, requires: array<string, mixed>, source?: string, action?: array{label: string, route: string, requires?: array<string, mixed>}, empty?: string}>
     */
    public const WIDGETS = [
        [
            'key' => 'quick_actions', 'title' => 'Quick actions', 'description' => 'Start the most common tasks',
            'icon' => 'bolt', 'tone' => 'brand', 'kind' => 'actions', 'span' => 4, 'order' => 5,
            'requires' => [],
        ],
        [
            'key' => 'patients_today', 'title' => "Today's patients", 'description' => 'Visits registered today',
            'icon' => 'patients', 'tone' => 'brand', 'kind' => 'kpi', 'span' => 1, 'order' => 10,
            'requires' => ['permissions' => ['patient.view', 'visit.view'], 'module' => 'patients'],
            'source' => 'patientsToday',
            'action' => ['label' => 'Open visits', 'route' => 'clinic.visits.index', 'requires' => ['permissions' => ['visit.view']]],
        ],
        [
            'key' => 'new_cases', 'title' => 'New cases', 'description' => 'First visits today',
            'icon' => 'new-case', 'tone' => 'green', 'kind' => 'kpi', 'span' => 1, 'order' => 12,
            'requires' => ['permissions' => ['patient.view', 'visit.view'], 'module' => 'patients', 'specialties' => self::CASE_SPECIALTIES],
            'source' => 'newCasesToday',
        ],
        [
            'key' => 'repeat_cases', 'title' => 'Repeat cases', 'description' => 'Returning patients today',
            'icon' => 'repeat-case', 'tone' => 'violet', 'kind' => 'kpi', 'span' => 1, 'order' => 14,
            'requires' => ['permissions' => ['patient.view', 'visit.view'], 'module' => 'patients', 'specialties' => self::CASE_SPECIALTIES],
            'source' => 'repeatCasesToday',
        ],
        [
            'key' => 'appointments_today', 'title' => 'Appointments', 'description' => 'Booked for today',
            'icon' => 'appointments', 'tone' => 'blue', 'kind' => 'kpi', 'span' => 1, 'order' => 16,
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'source' => 'appointmentsToday',
            'action' => ['label' => 'Open calendar', 'route' => 'clinic.appointments.index'],
        ],
        [
            'key' => 'waiting_now', 'title' => 'Waiting', 'description' => 'In the queue right now',
            'icon' => 'waiting', 'tone' => 'amber', 'kind' => 'kpi', 'span' => 1, 'order' => 18,
            'requires' => ['permissions' => ['queue.manage']],
            'source' => 'waitingNow',
            'action' => ['label' => 'Open reception', 'route' => 'clinic.reception'],
        ],
        [
            'key' => 'collections_today', 'title' => "Today's collection", 'description' => 'Payments received today',
            'icon' => 'payment', 'tone' => 'green', 'kind' => 'kpi', 'span' => 1, 'order' => 20,
            'requires' => ['permissions' => ['billing.view', 'payment.collect'], 'module' => 'billing'],
            'source' => 'collectionsToday',
        ],
        [
            'key' => 'my_day', 'title' => 'Your day', 'description' => 'Your patients today',
            'icon' => 'doctor', 'tone' => 'violet', 'kind' => 'stats', 'span' => 2, 'order' => 25,
            'requires' => ['practitioner' => true, 'permissions' => ['patient.view']],
            'source' => 'myDay',
            'action' => ['label' => 'My patients', 'route' => 'clinic.patients.index'],
        ],
        [
            'key' => 'my_queue', 'title' => 'Your queue', 'description' => 'Patients waiting for you',
            'icon' => 'waiting', 'tone' => 'violet', 'kind' => 'list', 'span' => 2, 'order' => 26,
            'requires' => ['practitioner' => true, 'permissions' => ['clinical.view', 'queue.manage']],
            'source' => 'myQueue',
            'empty' => 'No one is waiting for you right now.',
        ],
        [
            'key' => 'patient_flow', 'title' => "Today's patient flow", 'description' => 'Where today’s patients are',
            'icon' => 'flow', 'tone' => 'amber', 'kind' => 'flow', 'span' => 2, 'order' => 30,
            'requires' => ['permissions' => ['queue.manage']],
            'source' => 'patientFlow',
            'action' => ['label' => 'Open reception', 'route' => 'clinic.reception'],
        ],
        [
            'key' => 'todays_appointments', 'title' => "Today's appointments", 'description' => 'Next on the calendar',
            'icon' => 'appointments', 'tone' => 'blue', 'kind' => 'list', 'span' => 2, 'order' => 32,
            'requires' => ['permissions' => ['appointment.view'], 'module' => 'appointments'],
            'source' => 'todaysAppointments',
            'action' => ['label' => 'View all', 'route' => 'clinic.appointments.index'],
            'empty' => 'No appointments booked for today.',
        ],
        [
            'key' => 'payment_breakdown', 'title' => 'Collection by mode', 'description' => 'Cash, UPI and card today',
            'icon' => 'wallet', 'tone' => 'green', 'kind' => 'breakdown', 'span' => 2, 'order' => 40,
            'requires' => ['permissions' => ['billing.view', 'payment.collect'], 'module' => 'billing'],
            'source' => 'paymentBreakdown',
            'empty' => 'No payments recorded today.',
        ],
        [
            'key' => 'pending_payments', 'title' => 'Pending payments', 'description' => 'Bills with a balance due',
            'icon' => 'pending', 'tone' => 'amber', 'kind' => 'list', 'span' => 2, 'order' => 42,
            'requires' => ['permissions' => ['billing.view'], 'module' => 'billing'],
            'source' => 'pendingPayments',
            'empty' => 'Nothing outstanding.',
        ],
        [
            'key' => 'pending_lab_orders', 'title' => 'Lab work in progress', 'description' => 'Open dental lab cases',
            'icon' => 'lab', 'tone' => 'violet', 'kind' => 'list', 'span' => 2, 'order' => 50,
            'requires' => ['permissions' => ['clinical.view'], 'capabilities' => ['lab']],
            'source' => 'pendingLabOrders',
            'empty' => 'No lab cases open.',
        ],
        [
            'key' => 'pending_xrays', 'title' => 'X-rays to complete', 'description' => 'Requested, scheduled or awaiting review',
            'icon' => 'xray', 'tone' => 'violet', 'kind' => 'list', 'span' => 2, 'order' => 52,
            // X-ray records have no branch, so only clinic-wide users may see them.
            'requires' => ['permissions' => ['clinical.view'], 'capabilities' => ['xray'], 'branch' => 'clinic_wide'],
            'source' => 'pendingXrays',
            'empty' => 'No X-rays waiting.',
        ],
        [
            'key' => 'staff_overview', 'title' => 'Team', 'description' => 'Staff across the clinic',
            'icon' => 'staff', 'tone' => 'brand', 'kind' => 'stats', 'span' => 2, 'order' => 60,
            'requires' => ['permissions' => ['staff.manage'], 'branch' => 'clinic_wide'],
            'source' => 'staffOverview',
            'action' => ['label' => 'Manage staff', 'route' => 'admin.employees.index'],
        ],
        [
            'key' => 'branch_activity', 'title' => 'Branches today', 'description' => 'Activity at each branch',
            'icon' => 'branches', 'tone' => 'blue', 'kind' => 'facts', 'span' => 2, 'order' => 62,
            'requires' => ['permissions' => ['branch.manage'], 'branch' => 'clinic_wide', 'multi_branch' => true],
            'source' => 'branchActivity',
            'action' => ['label' => 'Manage branches', 'route' => 'admin.branches.index'],
        ],
        [
            'key' => 'owner_performance', 'title' => 'Clinic performance', 'description' => 'Last 7 days, all branches',
            'icon' => 'trend', 'tone' => 'ink', 'kind' => 'trend', 'span' => 2, 'order' => 70,
            'requires' => ['owner_mode' => true, 'permissions' => ['patient.view', 'appointment.view'], 'branch' => 'clinic_wide'],
            'source' => 'ownerPerformance',
        ],
        [
            'key' => 'owner_revenue', 'title' => 'Revenue', 'description' => 'All branches',
            'icon' => 'payment', 'tone' => 'green', 'kind' => 'stats', 'span' => 2, 'order' => 72,
            'requires' => ['owner_mode' => true, 'permissions' => ['billing.view'], 'module' => 'billing', 'branch' => 'clinic_wide'],
            'source' => 'ownerRevenue',
        ],
        [
            'key' => 'owner_plan', 'title' => 'Plan & usage', 'description' => 'Your OralCares subscription',
            'icon' => 'plan', 'tone' => 'ink', 'kind' => 'facts', 'span' => 2, 'order' => 74,
            'requires' => ['owner_mode' => true],
            'source' => 'ownerPlan',
        ],
        [
            'key' => 'owner_security', 'title' => 'Security', 'description' => 'Sign-in and Owner Mode checks',
            'icon' => 'shield', 'tone' => 'red', 'kind' => 'list', 'span' => 2, 'order' => 76,
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
     * @return list<array{key: string, title: string, description: string, icon: string, tone: string, kind: string, span: int, empty: string|null, action: array{label: string, href: string}|null, source: string|null}>
     */
    public static function eligible(ClinicAccess $access, array $actions, array $routeParameters = [], ?array $widgets = null): array
    {
        $widgets ??= self::WIDGETS;
        usort($widgets, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $eligible = [];
        foreach ($widgets as $widget) {
            if ($widget['kind'] === 'actions' && $actions === []) {
                continue;
            }

            if (! $access->allows($widget['requires'])) {
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
                'span' => $widget['span'],
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
