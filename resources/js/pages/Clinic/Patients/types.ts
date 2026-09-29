import type { AllergyOption } from './components/AllergyPicker';
import type { PatientFieldConfig } from './components/PatientFields';

export type PatientStatus = 'active' | 'inactive' | 'deceased';

export interface PatientListRow {
  id: number;
  uhid: string;
  name: string;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  email: string | null;
  age: number | null;
  gender: string | null;
  blood_group: string | null;
  status: PatientStatus;
  created_at: string;
  last_visit_date: string;
  last_visit_is_recent: boolean;
  city: string;
  has_critical_alert: boolean;
}

export interface PaginatorLink {
  url: string | null;
  label: string;
  active: boolean;
}

export interface Paginated<T> {
  data: T[];
  links: PaginatorLink[];
  from: number | null;
  to: number | null;
  total: number;
}

export type PatientDirectoryTab = 'mine' | 'all';

export interface PatientsIndexProps {
  patients: Paginated<PatientListRow>;
  filters: {
    q: string;
    status: string | null;
    gender: string | null;
    tab: PatientDirectoryTab;
  };
  /** Whether the acting user has their own clinical profile — gates whether the "My Patients" tab appears at all. */
  isPractitioner: boolean;
  metrics: {
    total: number;
    registeredToday: number;
    mine: number;
  };
  /** For the "Add patient" quick-add modal — see `PatientCreateProps`. */
  practitioners: PractitionerOption[];
  allergyCatalogue: AllergyOption[];
  fieldConfig: PatientFieldConfig;
}

/* ────────────────────────────────────────────────────────────── Show page */

export interface PatientProfile {
  address: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
}

export interface PatientAlert {
  id: number;
  type: string;
  description: string;
  severity: string;
}

export interface PatientDetail {
  id: number;
  uhid: string;
  name: string;
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  alternate_phone: string | null;
  email: string | null;
  age: number | null;
  gender: string | null;
  blood_group: string | null;
  status: PatientStatus;
  created_at: string;
  profile: PatientProfile | null;
  alerts: PatientAlert[];
  allergies: string[];
  current_practitioner: { id: number; name: string } | null;
}

export interface TodayAppointment {
  id: number;
  scheduled_time: string;
  practitioner_name: string;
  status: string;
}

export interface TodayVisit {
  id: number;
  queue_status: string;
  visit_status: string;
  doctor_name: string;
  queue_position: number | null;
}

export interface TodayStatus {
  has_active_today: boolean;
  appointment: TodayAppointment | null;
  visit: TodayVisit | null;
}

export interface UpcomingAppointment {
  id: number;
  date: string;
  time: string;
  practitioner_name: string;
  branch_name: string;
  status: string;
}

export type TimelineCategory = 'system' | 'reception' | 'document' | 'appointment' | 'clinical' | 'billing' | 'record';

export interface TimelineEvent {
  id: string;
  type: string;
  category: TimelineCategory;
  title: string;
  description: string;
  timestamp: string;
  formatted_time: string;
  actor_name: string;
}

export interface AppointmentRow {
  id: number;
  scheduled_at: string | null;
  status: string;
  appointment_type: string | null;
  practitioner: { id: number; name: string } | null;
  branch: { id: number; name: string } | null;
}

export interface VisitRow {
  id: number;
  visit_date: string | null;
  visit_status: string | null;
  queue_status: string | null;
  doctor: { id: number; name: string } | null;
  branch: { id: number; name: string } | null;
}

export interface InvoiceRow {
  id: number;
  invoice_number: string;
  status: string;
  total_amount: number;
  amount_paid: number;
  balance_due: number;
  issued_at: string;
}

export interface PrescriptionItem {
  id: number;
  name: string;
  dosage: string;
  frequency: string;
  duration: string;
}

export interface PrescriptionRow {
  id: number;
  prescription_number: string;
  status: string;
  prescribed_by: string;
  created_at: string;
  items_count: number;
  items: PrescriptionItem[];
}

export interface DocumentRow {
  id: number;
  file_name: string;
  file_size_bytes: number;
  document_type: string;
  uploaded_by: string;
  created_at: string;
  /** Null until the file has passed a malware scan; such a file is never served. */
  download_url: string | null;
  scan_status: string;
}

export interface PractitionerOption {
  id: number;
  name: string;
  specialty: string;
}

export interface PatientShowProps {
  patient: PatientDetail;
  todayStatus: TodayStatus;
  upcomingAppointment: UpcomingAppointment | null;
  timeline: TimelineEvent[];
  appointments: Paginated<AppointmentRow>;
  visits: Paginated<VisitRow>;
  billing: {
    is_accessible: boolean;
    summary: { total_billed: number; total_paid: number; total_balance: number };
    invoices: Paginated<InvoiceRow>;
  };
  prescriptions: {
    is_accessible: boolean;
    items: Paginated<PrescriptionRow>;
  };
  documents: Paginated<DocumentRow>;
  practitioners: PractitionerOption[];
  permissions: {
    can_view_billing: boolean;
    can_view_clinical: boolean;
    can_manage_docs: boolean;
    can_edit_patient: boolean;
    can_transfer_patient: boolean;
  };
}

/* ──────────────────────────────────────────────────────────── Create page */

export interface PatientCreateProps {
  practitioners: PractitionerOption[];
  allergyCatalogue: AllergyOption[];
  fieldConfig: PatientFieldConfig;
}

/* ────────────────────────────────────────────────────────────── Edit page */

export interface PatientEditData {
  id: number;
  uhid: string;
  first_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  age: string;
  blood_group: string;
  phone: string;
  alternate_phone: string;
  email: string;
  address: string;
  city: string;
  state: string;
  pincode: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  allergies: string[];
  medical_alerts: string[];
  current_practitioner_name: string | null;
}

export interface PatientEditProps {
  patient: PatientEditData;
  practitioners: PractitionerOption[];
  allergyCatalogue: AllergyOption[];
  fieldConfig: PatientFieldConfig;
}

export interface DuplicateMatch {
  id: number;
  uhid: string;
  name: string;
  phone: string | null;
  email: string | null;
  gender: string | null;
  age: number | null;
  date_of_birth: string | null;
  created_at: string;
}
