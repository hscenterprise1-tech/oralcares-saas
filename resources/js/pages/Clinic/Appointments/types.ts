export interface AppointmentPractitioner {
  id: number;
  name: string;
  specialty: string;
}

export interface AppointmentRow {
  id: number;
  patient_id: number;
  patient_name: string;
  patient_uhid: string;
  patient_phone: string;
  practitioner_id: number;
  room_name: string | null;
  scheduled_at: string;
  scheduled_time: string;
  duration_minutes: number;
  status: 'scheduled' | 'arrived' | 'checked_in' | 'in_consultation' | 'completed' | 'cancelled' | 'no_show';
  appointment_type: string;
  notes: string | null;
}

export interface AvailabilityWindow {
  start_time: string;
  end_time: string;
  is_available: boolean;
}

export interface OperatingHours {
  is_closed: boolean;
  open_time: string;
  close_time: string;
}

export interface AppointmentRoom {
  id: number;
  name: string;
  type: string;
}

export type CalendarView = 'day' | 'week' | 'month';

export interface AppointmentsIndexProps {
  date: string;
  dateFormatted: string;
  view: CalendarView;
  headerTitle: string;
  headerSubtitle: string | null;
  rangeStart: string;
  rangeEnd: string;
  practitioners: AppointmentPractitioner[];
  allPractitioners: AppointmentPractitioner[];
  appointments: AppointmentRow[];
  leaveEmployeeIds: number[];
  availabilityByPractitioner: Record<number, AvailabilityWindow[]>;
  operatingHours: OperatingHours | null;
  rooms: AppointmentRoom[];
  filters: {
    date: string;
    view: CalendarView;
    practitioner_ids: number[];
    room_id: number | string | null;
  };
}
