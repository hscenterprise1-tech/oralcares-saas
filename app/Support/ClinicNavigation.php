<?php

namespace App\Support;

use App\Support\ClinicAccess\ClinicAccess;

/**
 * The one definition of the Clinic Portal's navigation: the sidebar's
 * destinations, grouped into the approved Main and Additional sections, and
 * the clinic administration entries the account menu offers.
 *
 * Each entry declares what it needs in `ClinicAccess`'s terms (permission,
 * plan module, workspace — itself derived from permissions — branch) and
 * `for()` keeps only those this user may use right now. Nothing is shown
 * because a role "usually" has it: a receptionist granted billing
 * permissions sees Billing, one without does not. Each area has exactly one
 * entry, so combined roles never duplicate an item. React renders this list
 * and declares no destination of its own.
 *
 * New Case and Repeat Case are not destinations: they are the Dashboard's
 * quick actions and open the canonical intake (`INTAKE`, shared to the
 * portal as `clinic.intake`). `available` says whether a destination's new
 * frontend exists yet (`ClinicFrontend`); one that does not is shown as
 * coming soon rather than linked to a placeholder. An entry without a route
 * has no backend yet and is never available.
 *
 * Hiding is presentation; every route still protects itself.
 */
class ClinicNavigation
{
    /** Who may open the Appointment Calendar: the same rule its route enforces. */
    public const CALENDAR = ['permissions' => ['appointment.view'], 'module' => 'appointments', 'route' => 'clinic.appointments.index'];

    /** Who may start New Case / Repeat Case: the front office, at the branch being worked (the intake's own rule). */
    public const INTAKE = ['workspace' => ['front_desk'], 'module' => 'patients', 'branch' => 'active', 'route' => 'clinic.reception.intake'];

    /** Who handles the clinic's money: the front desk, billing staff and whoever runs the clinic (the billing route's rule). */
    public const BILLING = ['permissions' => ['billing.view'], 'module' => 'billing', 'workspace' => ['command_center', 'front_desk', 'billing']];

    /** Sections, in order: `main` and `additional` are the sidebar's; `admin` is the account menu's. */
    public const SECTIONS = [
        'main' => 'Main',
        'additional' => 'Additional',
        'admin' => 'Clinic administration',
    ];

    /**
     * @var list<array{key: string, label: string, icon: string, section: string, order: int, route: string|null, query?: array<string, string>, requires?: array<string, mixed>, keywords?: list<string>}>
     */
    public const DOMAINS = [
        [
            'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'section' => 'main', 'order' => 10,
            'route' => 'clinic.dashboard',
            'keywords' => ['home', 'overview', 'command center', 'today'],
        ],
        [
            'key' => 'appointments', 'label' => 'Appointments', 'icon' => 'appointments', 'section' => 'main', 'order' => 20,
            'route' => 'clinic.appointments.index', 'requires' => self::CALENDAR,
            'keywords' => ['calendar', 'schedule', 'booking'],
        ],
        [
            'key' => 'patients', 'label' => 'Patients', 'icon' => 'patients', 'section' => 'main', 'order' => 30,
            'route' => 'clinic.patients.index',
            'requires' => ['permissions' => ['patient.view'], 'module' => 'patients'],
            'keywords' => ['directory', 'records', 'cases'],
        ],
        [
            // The front office's one queue destination (the Reception control center).
            'key' => 'front_desk', 'label' => 'Queue', 'icon' => 'waiting', 'section' => 'main', 'order' => 40,
            'route' => 'clinic.reception', 'requires' => ['workspace' => ['front_desk']],
            'keywords' => ['reception', 'front desk', 'waiting', 'check in'],
        ],
        [
            'key' => 'billing', 'label' => 'Billing', 'icon' => 'billing', 'section' => 'main', 'order' => 50,
            'route' => 'clinic.billing.index', 'requires' => self::BILLING,
            'keywords' => ['invoices', 'payments', 'collections', 'receipts'],
        ],
        [
            'key' => 'reports', 'label' => 'Reports', 'icon' => 'reports', 'section' => 'main', 'order' => 60,
            'route' => 'clinic.reports.index',
            'requires' => ['permissions' => ['reports.view'], 'workspace' => ['command_center']],
            'keywords' => ['analytics', 'insights'],
        ],
        [
            // The follow-up worklist, on its route's own rule (`Patient360Access::canManageFollowUps`, patients module).
            'key' => 'follow_ups', 'label' => 'Follow-ups', 'icon' => 'follow-ups', 'section' => 'additional', 'order' => 70,
            'route' => 'clinic.follow-ups.index',
            'requires' => ['permissions' => ['clinical.edit', 'appointment.create'], 'module' => 'patients'],
            'keywords' => ['recall', 'reminders', 'due'],
        ],
        [
            // Every member's own notifications at this clinic; the top bar's bell reads the same feed.
            'key' => 'notifications', 'label' => 'Notifications', 'icon' => 'notifications', 'section' => 'additional', 'order' => 80,
            'route' => 'clinic.notifications.index',
            'keywords' => ['alerts', 'messages', 'inbox', 'updates'],
        ],
        [
            // The clinic's requests to OralCares support (`ClinicSupportController`): every member may ask.
            'key' => 'support', 'label' => 'Support', 'icon' => 'support', 'section' => 'additional', 'order' => 90,
            'route' => 'clinic.support.index',
            'keywords' => ['help', 'ticket', 'contact', 'issue', 'problem'],
        ],
        [
            // Owner-approved deletions; the audience that decides or answers them.
            'key' => 'approvals', 'label' => 'Approvals', 'icon' => 'shield', 'section' => 'admin', 'order' => 100,
            'route' => 'clinic.approvals.index', 'requires' => ['workspace' => ['command_center']],
            'keywords' => ['deletion requests', 'void', 'merge'],
        ],
        [
            'key' => 'staff', 'label' => 'Staff', 'icon' => 'staff', 'section' => 'admin', 'order' => 110,
            'route' => 'admin.employees.index', 'requires' => ['permissions' => ['staff.manage']],
            'keywords' => ['team', 'employees', 'doctors', 'invitations', 'shifts'],
        ],
        [
            'key' => 'branches', 'label' => 'Branches', 'icon' => 'branches', 'section' => 'admin', 'order' => 120,
            'route' => 'admin.branches.index', 'requires' => ['permissions' => ['branch.manage']],
            'keywords' => ['locations', 'rooms'],
        ],
        [
            // The clinic's services and treatments: the owner switches on what the clinic offers and prices it.
            'key' => 'services', 'label' => 'Services', 'icon' => 'services', 'section' => 'admin', 'order' => 125,
            'route' => 'admin.catalog.index', 'requires' => ['permissions' => ['catalog.manage']],
            'keywords' => ['treatments', 'procedures', 'prices', 'price list', 'rate list', 'catalogue', 'rct', 'implant', 'braces'],
        ],
        [
            // Settings opens on the patient form (fields, templates, lists, safety options): the settings screen that is built.
            'key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'section' => 'admin', 'order' => 130,
            'route' => 'admin.settings.patient-form', 'requires' => ['permissions' => ['settings.manage']],
            'keywords' => ['preferences', 'configuration', 'patient form', 'registration fields', 'address', 'allergies', 'visit reasons', 'template'],
        ],
    ];

    /**
     * The entries this user may use, in order, with their href, section and
     * whether their frontend exists yet.
     *
     * @param  array<string, string>  $routeParameters  e.g. the `{clinic}` host parameter in domain mode
     * @param  list<array<string, mixed>>|null  $domains  defaults to `DOMAINS`
     * @return list<array{key: string, label: string, icon: string, href: string|null, section: string, section_label: string, action: null, available: bool, keywords: list<string>, children: list<array<string, mixed>>}>
     */
    public static function for(ClinicAccess $access, array $routeParameters = [], ?array $domains = null): array
    {
        $domains ??= self::DOMAINS;
        usort($domains, fn (array $a, array $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        // One dashboard per job: the Command Center for whoever runs the clinic, the Front Desk for reception
        // (the Dashboard entry opens it, and it is not listed twice), the Doctor Dashboard (Today) for a
        // practitioner. There is no staff dashboard any more, so anyone else has no Dashboard entry: their
        // home is already in the list (the Calendar, or Billing).
        $receptionHome = ClinicWorkspaces::receptionHome($access);
        $commandCenter = ClinicWorkspaces::commandCenter($access);
        $doctorHome = ClinicWorkspaces::doctorHome($access);

        $resolved = [];
        foreach ($domains as $domain) {
            if ($receptionHome && $domain['key'] === 'front_desk') {
                continue;
            }
            if ($domain['key'] === 'dashboard' && ! $commandCenter && ! $receptionHome && ! $doctorHome) {
                continue;
            }
            // A practitioner's Dashboard is their day of patients (the Doctor Dashboard).
            if ($doctorHome && $domain['key'] === 'dashboard') {
                $domain = ['route' => 'clinic.consultations.index', 'requires' => ['workspace' => ['today']], 'keywords' => [...($domain['keywords'] ?? []), 'queue', 'waiting', 'consultation', 'my patients']] + $domain;
            }
            // A practitioner without the clinic's reports gets their own: their work, scoped to them (`ClinicReports::scopedPractitioner`).
            if ($doctorHome && ! $commandCenter && $domain['key'] === 'reports') {
                $domain = ['label' => 'My reports', 'requires' => ['workspace' => ['today']], 'keywords' => [...($domain['keywords'] ?? []), 'my work', 'my patients', 'my treatments']] + $domain;
            }
            if ($receptionHome && $domain['key'] === 'dashboard') {
                $domain = ['route' => 'clinic.reception', 'requires' => ['workspace' => ['front_desk']], 'keywords' => [...($domain['keywords'] ?? []), 'reception', 'front desk', 'queue', 'waiting', 'check in']] + $domain;
            }
            $route = $domain['route'] ?? null;
            $requires = $domain['requires'] ?? [];
            if (! $access->allows($route === null ? $requires : $requires + ['route' => $route])) {
                continue;
            }

            $section = $domain['section'] ?? 'main';
            $resolved[] = [
                'key' => $domain['key'],
                'label' => $domain['label'],
                'icon' => $domain['icon'],
                'href' => $route === null ? null : route($route, $routeParameters + ($domain['query'] ?? []), false),
                'section' => $section,
                'section_label' => self::SECTIONS[$section] ?? $section,
                // Kept for clients that read it: no entry is an action any more.
                'action' => null,
                'available' => $route !== null && ClinicFrontend::available($route),
                'keywords' => $domain['keywords'] ?? [],
                // Flat: kept for clients that read sub-areas.
                'children' => [],
            ];
        }

        return $resolved;
    }

    /**
     * Whether New Case / Repeat Case may be started here, for the portal's
     * one intake dialog. The intake's routes check again.
     *
     * @return array{available: bool, can_register: bool}
     */
    public static function intake(ClinicAccess $access): array
    {
        $available = $access->allows(self::INTAKE);

        return [
            'available' => $available,
            'can_register' => $available && $access->allows(['permissions' => ['patient.create']]),
        ];
    }
}
