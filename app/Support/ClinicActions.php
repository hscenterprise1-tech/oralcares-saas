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
    /** Specialties that register patients as new and repeat cases. Physiotherapy works in assessments and sessions instead. */
    public const CASE_SPECIALTIES = ['dental', 'general', 'general_hospital', 'multi_specialty', 'ophthalmology', 'ent', 'dermatology'];

    /**
     * @var list<array{key: string, label: string, description: string, icon: string, tone: string, order: int, route: string, requires: array<string, mixed>, keywords?: list<string>}>
     */
    public const ACTIONS = [
        [
            'key' => 'new_case', 'label' => 'New Case', 'description' => 'Register a first-time patient',
            'icon' => 'new-case', 'tone' => 'brand', 'order' => 10, 'route' => 'clinic.patients.create',
            'requires' => ['permissions' => ['patient.create'], 'module' => 'patients', 'specialties' => self::CASE_SPECIALTIES],
            'keywords' => ['register', 'new patient'],
        ],
        [
            'key' => 'repeat_case', 'label' => 'Repeat Case', 'description' => 'Check in a returning patient',
            'icon' => 'repeat-case', 'tone' => 'brand', 'order' => 20, 'route' => 'clinic.reception',
            'requires' => ['permissions_all' => ['queue.manage', 'patient.search'], 'module' => 'patients', 'specialties' => self::CASE_SPECIALTIES],
            'keywords' => ['check in', 'returning', 'walk-in'],
        ],
        [
            'key' => 'new_assessment', 'label' => 'New Assessment', 'description' => 'Start a physiotherapy assessment',
            'icon' => 'assessment', 'tone' => 'violet', 'order' => 25, 'route' => 'clinic.physio.assessments.create',
            'requires' => ['permissions' => ['clinical.edit'], 'specialties' => ['physiotherapy']],
            'keywords' => ['physio', 'evaluation'],
        ],
        [
            'key' => 'new_appointment', 'label' => 'New Appointment', 'description' => 'Book a visit',
            'icon' => 'appointments', 'tone' => 'blue', 'order' => 30, 'route' => 'clinic.appointments.index',
            'requires' => ['permissions' => ['appointment.create'], 'module' => 'appointments'],
            'keywords' => ['book', 'schedule'],
        ],
        [
            'key' => 'collect_payment', 'label' => 'Collect Payment', 'description' => 'Record a payment against a bill',
            'icon' => 'payment', 'tone' => 'green', 'order' => 40, 'route' => 'clinic.billing.payments.create',
            'requires' => ['permissions' => ['payment.collect'], 'module' => 'billing'],
            'keywords' => ['pay', 'receipt', 'cash', 'upi'],
        ],
        [
            'key' => 'new_lab_order', 'label' => 'New Lab Order', 'description' => 'Send work to the dental lab',
            'icon' => 'lab', 'tone' => 'violet', 'order' => 50, 'route' => 'clinic.orders.lab.create',
            'requires' => ['permissions' => ['clinical.edit'], 'capabilities' => ['lab']],
            'keywords' => ['crown', 'prosthetic'],
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
                'href' => route($action['route'], $routeParameters, false),
                'keywords' => $action['keywords'] ?? [],
            ];
        }

        return $resolved;
    }
}
