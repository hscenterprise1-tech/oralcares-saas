import type { LucideIcon } from 'lucide-react';
import { LayoutDashboard, Users, CalendarDays, ReceiptIndianRupee, UserCog } from 'lucide-react';

/**
 * The clinic portal's single navigation registry.
 *
 * Mirrors the pattern `CRM_DESTINATIONS` sets on the Company side: one list,
 * projected into both the rail and (later) any mobile sheet, rather than a
 * second copy hand-written in each place a destination might appear.
 *
 * Each entry is gated by up to two independent things, combined with AND:
 *
 *   - `module` — does the clinic's plan grant this at all? Checked against
 *     `clinic.enabled_modules`, which `EntitlementResolver` computes from the
 *     subscription (see `HandleInertiaRequests::clinicContext()`).
 *   - `permission` — can *this* user do it, in the branch they're currently
 *     working in? Checked against `auth.user.permissions`, the same
 *     branch-team-scoped Spatie grant `hasClinicPermission()` reads on the
 *     server. A destination with no `permission` is one every active clinic
 *     user may see (Dashboard); a plan with no `module` gate is a management
 *     capability rather than a purchasable feature (Staff, Settings).
 *
 * Settings is deliberately not listed here — like the CRM rail, it is
 * appended separately via `ClinicShell`'s `settingsHref`.
 */
export interface ClinicNavItem {
  id: string;
  label: string;
  href: string;
  icon: LucideIcon;
  hue: string;
  module?: string;
  permission?: string;
}

export const CLINIC_HUE = {
  dashboard: '#4A9DAB',
  patients: '#2563EB',
  appointments: '#8B7FE8',
  billing: '#2FA96B',
  staff: '#F5A623',
  settings: '#6B7488',
} as const;

export const CLINIC_NAV_ITEMS: ClinicNavItem[] = [
  {
    id: 'dashboard',
    label: 'Dashboard',
    href: '/clinic/dashboard',
    icon: LayoutDashboard,
    hue: CLINIC_HUE.dashboard,
  },
  {
    id: 'patients',
    label: 'Patients',
    href: '/clinic/patients',
    icon: Users,
    hue: CLINIC_HUE.patients,
    module: 'patients',
    permission: 'patient.view',
  },
  {
    id: 'appointments',
    label: 'Appointments',
    href: '/clinic/appointments',
    icon: CalendarDays,
    hue: CLINIC_HUE.appointments,
    module: 'appointments',
    permission: 'appointment.view',
  },
  {
    id: 'billing',
    label: 'Billing',
    href: '/clinic/billing',
    icon: ReceiptIndianRupee,
    hue: CLINIC_HUE.billing,
    module: 'billing',
    permission: 'billing.view',
  },
  {
    id: 'staff',
    label: 'Staff',
    href: '/clinic/admin/employees',
    icon: UserCog,
    hue: CLINIC_HUE.staff,
    permission: 'staff.manage',
  },
];

/**
 * Applies both gates. The one place a destination's visibility is decided.
 *
 * `hasPermission` is expected to be `useClinicPermission()`'s return value,
 * not the raw Company-side `usePermissions().hasPermission` — the clinic
 * primary-owner bypass (`clinic.is_owner`) is runtime logic on the server,
 * never a Spatie grant, so only the clinic-aware hook accounts for it. An
 * owner with no role assigned would otherwise see an emptied-out nav despite
 * every route behind it letting them straight in.
 */
export function visibleClinicNav(
  enabledModules: string[],
  hasPermission: (permission?: string) => boolean
): ClinicNavItem[] {
  return CLINIC_NAV_ITEMS.filter(
    (item) => (!item.module || enabledModules.includes(item.module)) && hasPermission(item.permission)
  );
}
