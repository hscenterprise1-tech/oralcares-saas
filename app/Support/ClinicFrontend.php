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
        // The Doctor Dashboard (Today): the practitioner's queue beside their appointments panel.
        'clinic.consultations.index',
        // The consultation workspace (Patient 360 → consultations, prescribing with the allergy review).
        'clinic.consultations.show',
        // Investigations: the worklist and one order (result entry, review, cancellation).
        'clinic.orders.index',
        'clinic.orders.show',
        // Follow-ups across patients (overdue, today, next 30 days, done) and the clinic's reports.
        'clinic.follow-ups.index',
        'clinic.reports.index',
        // Visits: the branch's visit log, by day.
        'clinic.visits.index',
        // The clinic's WhatsApp: connection, automatic messages and the delivery log.
        'clinic.whatsapp.index',
        // Time off and pay: leave requests and decisions, payroll months, salaries, advances and payslips.
        'clinic.leave.index',
        'clinic.payroll.index',
        // Roles & permissions: what each role may do at this clinic (owner only).
        'admin.roles.index',
        'admin.settings.index',
        'admin.consents.index',
        'admin.settings.patient-form',
        // Services & treatments: the clinic's own price list and treatment catalogue.
        'admin.catalog.index',
        // Staff: the team list, one person's profile, add/edit, and the document checklist.
        'admin.employees.index',
        'admin.employees.create',
        'admin.employees.show',
        'admin.employees.edit',
        'admin.staff-documents.settings',
        // Clinic layout: branches (with opening hours), departments, rooms and chairs.
        'admin.branches.index',
        'admin.departments.index',
        'admin.rooms.index',
        'admin.settings.automation',
        'admin.settings.physio',
        'clinic.notifications.index',
        'clinic.support.index',
        // Billing: one destination, its sections as in-page tabs (`BillingTabs`).
        'clinic.billing.index',
        // One signed consultation's checkout: bill it and take (or defer) the payment in one step.
        'clinic.billing.checkout',
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
    public const PATIENT_360_SECTIONS = ['overview', 'visits', 'clinical', 'dental', 'physio', 'cases', 'treatment', 'appointments', 'prescriptions', 'investigations', 'billing', 'documents', 'consents', 'communication', 'follow-ups', 'timeline', 'requests', 'audit'];

    public static function available(string $routeName): bool
    {
        return in_array($routeName, self::AVAILABLE, true);
    }

    public static function patient360Section(string $section): bool
    {
        return in_array($section, self::PATIENT_360_SECTIONS, true);
    }
}
