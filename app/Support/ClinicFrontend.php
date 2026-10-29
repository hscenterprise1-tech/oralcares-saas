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
        // The Front Desk (Reception control center): queue board, New Case / Repeat Case, the day's lists.
        'clinic.reception',
        'clinic.appointments.index',
        'clinic.patients.index',
        'clinic.patients.create',
        // Patient 360: the page and the sections in `PATIENT_360_SECTIONS`.
        'clinic.patients.show',
        'clinic.patients.edit',
        // The consultation workspace (Patient 360 → consultations, prescribing with the allergy review).
        'clinic.consultations.show',
        // Investigations: the worklist and one order (result entry, review, cancellation).
        'clinic.orders.index',
        'clinic.orders.show',
        'admin.settings.patient-form',
        'clinic.notifications.index',
        'clinic.support.index',
        // Billing: one destination, its sections as in-page tabs (`BillingTabs`).
        'clinic.billing.index',
        'clinic.billing.reversals',
        'clinic.billing.summary',
        'clinic.expenses.index',
        // 'clinic.approvals.index' is held back until the aggregated decision
        // inbox (Owner Command Center Phase 1.6) is ready. Its route, page and
        // governance stay in place and authorize themselves on the server.
    ];

    /**
     * Patient 360 sections whose screen is built, by `Patient360SectionPolicy`
     * key. The policy still decides who may open a section; a section not
     * listed here is not shown or linked yet. Add a key when its screen lands.
     *
     * @var list<string>
     */
    public const PATIENT_360_SECTIONS = ['overview', 'visits', 'clinical', 'dental', 'physio', 'treatment', 'appointments', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'communication', 'follow-ups', 'timeline', 'requests', 'audit'];

    public static function available(string $routeName): bool
    {
        return in_array($routeName, self::AVAILABLE, true);
    }

    public static function patient360Section(string $section): bool
    {
        return in_array($section, self::PATIENT_360_SECTIONS, true);
    }
}
