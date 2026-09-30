import type { CrmStatusTone } from '@/pages/Company/design';
import type { AppointmentRow } from './types';

/**
 * One status → colour mapping, shared by every calendar surface (Day
 * columns, Week grid, Month chips, the mobile agenda, the event popover).
 * Previously each view hand-rolled its own copy of this map — kept in one
 * place now that there are five of them instead of two. Every hex is a
 * literal pulled from `resources/css/ovs-tokens.generated.css` (crmTheme),
 * not invented.
 */
export const APPOINTMENT_STATUS_TONE: Record<AppointmentRow['status'], { bg: string; border: string; text: string; dot: string }> = {
  scheduled: { bg: '#EAE6FD', border: '#C3BAF6', text: '#4C3DAF', dot: '#8B7FE8' }, // --ovs-crm-accent-violet-*
  arrived: { bg: '#DFF1F4', border: '#A2D4DD', text: '#1F6673', dot: '#4A9DAB' }, // --ovs-crm-accent-teal-*
  checked_in: { bg: '#DFF1F4', border: '#A2D4DD', text: '#1F6673', dot: '#4A9DAB' },
  in_consultation: { bg: '#FEF0DA', border: '#F6D9A6', text: '#8A5406', dot: '#F5A623' }, // --ovs-crm-warning-*
  completed: { bg: '#DDFBEA', border: '#93EFBE', text: '#0C7A42', dot: '#3DDC84' }, // --ovs-crm-success-*
  cancelled: { bg: '#EDEFF3', border: '#CDD2DA', text: '#6B7385', dot: '#9AA1B0' }, // --ovs-crm-surface-muted / text-muted
  // Not red: red is reserved for errors, and a missed booking is a state, not a failure.
  no_show: { bg: '#EDEFF3', border: '#9AA1B0', text: '#565F73', dot: '#565F73' }, // --ovs-crm-surface-muted / --ovs-crm-text-secondary
};

export const APPOINTMENT_STATUS_PILL_TONE: Record<AppointmentRow['status'], CrmStatusTone> = {
  scheduled: 'blue',
  arrived: 'warn',
  checked_in: 'warn',
  in_consultation: 'good',
  completed: 'neutral',
  cancelled: 'neutral',
  no_show: 'neutral',
};

export const APPOINTMENT_STATUS_OPTIONS: { value: AppointmentRow['status']; label: string }[] = [
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'arrived', label: 'Arrived' },
  { value: 'checked_in', label: 'Checked in' },
  { value: 'in_consultation', label: 'In consultation' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'no_show', label: 'No-show' },
];

export function appointmentStatusLabel(status: string): string {
  return status.replace(/_/g, ' ');
}
