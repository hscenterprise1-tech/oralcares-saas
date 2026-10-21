<?php

namespace App\Support;

use App\Support\ClinicAccess\ClinicAccess;

/**
 * The one definition of the Clinic Portal's quick actions: things a user
 * starts (a new case, an appointment, a payment), as opposed to the domains
 * they go to (`ClinicNavigation`).
 *
 * Each action declares its requirements in `ClinicAccess`'s terms, so the
 * dashboard's quick-action card and the command palette show exactly the
 * same set, and only what the server would let this user do. An action
 * whose page does not exist yet (collect payment, lab order, physio
 * assessment) is declared but never shown.
 *
 * No action carries a fee or price. Where a workflow has one, its own page
 * reads it from the clinic's configuration.
 */
class ClinicActions
{
    /**
     * @var list<array{key: string, label: string, description: string, icon: string, tone: string, order: int, route: string, requires: array<string, mixed>, query?: array<string, mixed>, intake?: 'new'|'repeat', tile?: 'large'|'row', dashboard_label?: string, keywords?: list<string>}>
     *
     * `tile` is the action's size in the dashboard's Quick Actions card: the
     * day's main actions are `large` and sit side by side; the rest are
     * compact full-width rows. `dashboard_label` names the tile when the card
     * calls it something shorter than the command list does.
     */
    public const ACTIONS = [
        [
            'key' => 'new_case', 'label' => 'New Case', 'description' => 'Register a first-time patient',
            'icon' => 'new-case', 'tone' => 'brand', 'tile' => 'large', 'order' => 10, 'route' => 'clinic.reception',
            // Opens the Front Desk's New Case dialog (register and check in), never the full registration page.
            'query' => ['intake' => 'new'],
            // On the dashboard it opens the portal's one intake in place.
            'intake' => 'new',
            // Registration is common to every specialty; what follows depends on the doctor chosen.
            'requires' => ['permissions_all' => ['patient.create', 'front_office.operate'], 'module' => 'patients', 'workspace' => ['front_desk']],
            'keywords' => ['register', 'new patient'],
        ],
        [
            'key' => 'repeat_case', 'label' => 'Repeat Case', 'description' => 'Check in a returning patient',
            'icon' => 'repeat-case', 'tone' => 'blue', 'tile' => 'large', 'order' => 20, 'route' => 'clinic.reception',
            'query' => ['intake' => 'repeat'],
            'intake' => 'repeat',
            'requires' => ['permissions_all' => ['front_office.operate', 'patient.search'], 'module' => 'patients', 'workspace' => ['front_desk']],
            'keywords' => ['check in', 'returning', 'walk-in'],
        ],
        [
            'key' => 'new_assessment', 'label' => 'New Assessment', 'description' => 'Start a physiotherapy assessment',
            'icon' => 'assessment', 'tone' => 'violet', 'order' => 25, 'route' => 'clinic.physio.assessments.create',
            'requires' => ['permissions' => ['clinical.edit'], 'extensions' => ['physio_assessment']],
            'keywords' => ['physio', 'evaluation'],
        ],
        [
            'key' => 'new_appointment', 'label' => 'Book Appointment', 'description' => 'Schedule a visit',
            'icon' => 'appointments', 'tone' => 'amber', 'tile' => 'row', 'order' => 30, 'route' => 'clinic.appointments.index',
            // Opens the calendar with the booking dialog already open.
            'query' => ['book' => 1],
            'requires' => ['permissions' => ['appointment.create'], 'module' => 'appointments'],
            'keywords' => ['book', 'schedule'],
        ],
        [
            'key' => 'collect_payment', 'label' => 'Collect Payment', 'description' => 'Record a payment against a bill',
            'icon' => 'payment', 'tone' => 'violet', 'tile' => 'row', 'dashboard_label' => 'Billing', 'order' => 40, 'route' => 'clinic.billing.index',
            // Reception collects on the Front Desk itself; this opens the Billing work center.
            'requires' => ['permissions' => ['payment.collect'], 'module' => 'billing', 'workspace' => ['command_center', 'billing']],
            'keywords' => ['pay', 'receipt', 'cash', 'upi'],
        ],
        [
            'key' => 'new_lab_order', 'label' => 'New Lab Order', 'description' => 'Send work to the dental lab',
            'icon' => 'lab', 'tone' => 'violet', 'order' => 50, 'route' => 'clinic.orders.lab.create',
            'requires' => ['permissions' => ['clinical.edit'], 'capabilities' => ['lab']],
            'keywords' => ['crown', 'prosthetic'],
        ],
        [
            'key' => 'request_leave', 'label' => 'Request Leave', 'description' => 'Ask for time off',
            'icon' => 'leave', 'tone' => 'amber', 'order' => 70, 'route' => 'clinic.leave.index',
            'requires' => ['permissions' => ['leave.request']],
            'keywords' => ['time off', 'holiday', 'absence'],
        ],
        [
            'key' => 'owner_settings', 'label' => 'Clinic Settings', 'description' => 'Owner-level clinic configuration',
            'icon' => 'owner', 'tone' => 'ink', 'order' => 80, 'route' => 'admin.settings.index',
            'requires' => ['permissions' => ['settings.manage'], 'owner_mode' => true],
            'keywords' => ['owner', 'configuration'],
        ],
    ];

    /**
     * The actions this user may start, in order.
     *
     * @param  array<string, string>  $routeParameters
     * @return list<array{key: string, label: string, description: string, icon: string, tone: string, href: string, keywords: list<string>}>
     */
    public static function for(ClinicAccess $access, array $routeParameters = []): array
    {
        $actions = self::ACTIONS;
        usort($actions, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $resolved = [];
        foreach ($actions as $action) {
            if (! $access->allows(['route' => $action['route']] + $action['requires'])) {
                continue;
            }

            $resolved[] = [
                'key' => $action['key'],
                'label' => $action['label'],
                'description' => $action['description'],
                'icon' => $action['icon'],
                'tone' => $action['tone'],
                'href' => route($action['route'], $routeParameters + ($action['query'] ?? []), false),
                'keywords' => $action['keywords'] ?? [],
            ];
        }

        return $resolved;
    }

    /**
     * The dashboard's quick actions: the user's actions whose destination
     * works today. New Case and Repeat Case open the intake in place, so they
     * also need the intake's own rule (front office, at the branch being
     * worked); every other action needs its page to exist (`ClinicFrontend`).
     * An action that would lead to a placeholder is left out, never shown
     * disabled.
     *
     * @param  array<string, string>  $routeParameters
     * @return list<array{key: string, label: string, icon: string, tone: string, tile: 'large'|'row', intake: 'new'|'repeat'|null, href: string|null}>
     */
    public static function forDashboard(ClinicAccess $access, array $routeParameters = []): array
    {
        $definitions = array_column(self::ACTIONS, null, 'key');

        $actions = [];
        foreach (self::for($access, $routeParameters) as $action) {
            $intake = $definitions[$action['key']]['intake'] ?? null;
            $works = $intake !== null
                ? $access->allows(ClinicNavigation::INTAKE)
                : ClinicFrontend::available($definitions[$action['key']]['route']);
            if (! $works) {
                continue;
            }

            // A tile is its icon and its name only; how big it is, the action says.
            $actions[] = [
                'key' => $action['key'],
                'label' => $definitions[$action['key']]['dashboard_label'] ?? $action['label'],
                'icon' => $action['icon'],
                'tone' => $action['tone'],
                'tile' => $definitions[$action['key']]['tile'] ?? 'row',
                'intake' => $intake,
                'href' => $intake === null ? $action['href'] : null,
            ];
        }

        return $actions;
    }
}
