/** The calendar's page props: the server's contract, mirrored exactly. */

export type CalendarView = 'day' | 'week' | 'month';

export type StatusValue =
  | 'scheduled'
  | 'confirmed'
  | 'arrived'
  | 'checked_in'
  | 'waiting'
  | 'in_consultation'
  | 'completed'
  | 'cancelled'
  | 'no_show';

export type EditScope = 'this' | 'following' | 'series';

export interface PatientRef {
  id: number;
  name: string;
  uhid: string | null;
  phone: string | null;
}

export interface AppointmentItem {
  id: number;
  reference: string;
  /** Null for a new case booked before registration. */
  patient: PatientRef | null;
  new_case: { name: string; phone: string | null } | null;
  provider_id: number | null;
  provider_name: string | null;
  /** The provider has approved leave that day: the booking needs moving. */
  provider_on_leave: boolean;
  service: { id: number; name: string } | null;
  /** Assigned by the system, shown only after booking. */
  resource: { name: string; type: string | null } | null;
  /** Clinic wall-clock time, no offset: `YYYY-MM-DDTHH:mm:ss`. */
  start: string;
  end: string;
  duration_minutes: number;
  status: Exclude<StatusValue, 'waiting'>;
  /** The status shown: a checked-in patient still in the queue reads as waiting. */
  display_status: StatusValue;
  /** Read-only metadata. */
  source: string;
  notes: string | null;
  series_id: number | null;
  rescheduled: boolean;
  cancellation_reason: string | null;
  allowed_statuses: StatusValue[];
  upcoming: boolean;
}

export interface Provider {
  id: number;
  name: string;
  specialty: string | null;
  is_me: boolean;
  /** The services they provide; empty means every service. */
  service_ids: number[];
}

/** Whether the provider offers the service (no service: everyone). */
export function offers(provider: Provider, serviceId: number | null): boolean {
  return serviceId === null || provider.service_ids.length === 0 || provider.service_ids.includes(serviceId);
}

export interface Service {
  id: number;
  name: string;
  category: string | null;
  duration_minutes: number | null;
}

export interface BranchDay {
  open: boolean;
  reason: string | null;
  start: string;
  end: string;
  enforced: boolean;
}

/** Minutes from midnight. */
export type Window = [number, number];

export interface AppointmentSettings {
  slot_minutes: number;
  default_duration_minutes: number;
  buffer_minutes: number;
  day_start: string;
  day_end: string;
  working_days: number[];
  break_start: string | null;
  break_end: string | null;
  holidays: string[];
  cancellation_notice_hours: number;
  no_show_after_minutes: number;
  recurring_enabled: boolean;
  max_recurring_occurrences: number;
  online_booking_enabled: boolean;
  configured: boolean;
}

export interface Filters {
  providers: number[];
  service_id: number | null;
  statuses: StatusValue[];
  q: string;
}

export interface Kpis {
  today: number;
  confirmed: number;
  arrived: number;
  waiting: number;
  in_consultation: number;
  completed: number;
  cancelled: number;
  no_show: number;
}

export interface ProviderStatus {
  id: number;
  name: string;
  specialty: string | null;
  state: 'available' | 'busy' | 'off_shift' | 'on_leave' | 'closed';
  label: string;
  booked_today: number;
  next_slot: string | null;
}

export interface CalendarContext {
  providers: ProviderStatus[];
  /** Upcoming bookings whose provider has since gone on approved leave. */
  onLeave: AppointmentItem[];
}

export interface FreeTime {
  start: string;
  /** How many providers are free then. */
  providers: number;
}

export interface Option {
  value: string;
  label: string;
}

export interface CalendarProps {
  branch: { id: number; name: string };
  view: CalendarView;
  date: string;
  today: string;
  now: string;
  range: { start: string; end: string };
  filters: Filters;
  appointments: AppointmentItem[];
  truncated: boolean;
  kpis: Kpis;
  days: Record<string, BranchDay>;
  dayWindows: Record<string, Window[]>;
  providers: Provider[];
  services: Service[];
  settings: AppointmentSettings;
  options: {
    statuses: Option[];
    frequencies: string[];
    periods: string[];
  };
  can: {
    create: boolean;
    manage: boolean;
    check_in: boolean;
    settings: boolean;
    view_patient: boolean;
    register_patient: boolean;
  };
  links: {
    index: string;
    store: string;
    availability: string;
    patientSearch: string;
    patient: string;
    registerPatient: string;
    settings: string;
  };
  /** Deferred: undefined until they arrive. */
  context?: CalendarContext;
  freeTimes?: FreeTime[];
}

export type { PatientHit } from '@/clinic/patients/types';

export interface Slot {
  provider_id: number;
  provider_name: string | null;
  start: string;
  end: string;
}

/** What opens the booking form already filled in. */
export interface BookingDraft {
  start?: string;
  providerId?: number | null;
  serviceId?: number | null;
  patient?: PatientRef | null;
}

/** Who an appointment is for: the patient, or the new case's name. */
export function personName(appointment: Pick<AppointmentItem, 'patient' | 'new_case'>): string {
  return appointment.patient?.name ?? appointment.new_case?.name ?? 'Patient';
}
