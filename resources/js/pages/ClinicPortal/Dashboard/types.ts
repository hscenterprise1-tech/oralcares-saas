/**
 * The dashboard's operational widgets as the server sends them
 * (`DashboardData`). The server is the contract: these mirror it.
 */

export interface QueueAction {
  key: 'call' | 'recall' | 'skip';
  label: string;
  url: string;
}

export interface PatientRow {
  id: number;
  patient: { name: string; age: number | null; gender: string | null } | null;
  kind: 'new' | 'repeat' | null;
  /** The specialty's word for it: "New case" where the clinic registers cases, "First visit" elsewhere. */
  kind_label: string | null;
  doctor: string | null;
  /** The Front Desk's queue status: waiting, called, in_consultation or completed. */
  status: string;
  priority: string;
  wait_minutes: number | null;
  actions: QueueAction[];
}

/** Today's patients as a preview: `rows` are the first few of `total`; the counts are the whole day's. */
export interface PatientsData {
  counts: { waiting: number; in_room: number; completed: number };
  total: number;
  /** How many rows the preview holds (its fixed size). */
  limit: number;
  rows: PatientRow[];
}

export interface AppointmentRow {
  id: number;
  time: string;
  primary: string;
  secondary: string;
  /** `late` when the booked time has passed and the patient has not arrived. */
  status: string;
  /** That booking on the Calendar, for whoever may open it. */
  href: string | null;
}

export interface AppointmentsData {
  total: number;
  limit: number;
  items: AppointmentRow[];
}

export interface DueRow {
  id: number;
  patient: string;
  reference: string | null;
  amount: number;
  paid: number;
  balance: number;
  status: 'unpaid' | 'partially_paid';
}

/** Today's pending payments: `items` are the first few of `total` bills; `due` is the balance over all of them. */
export interface DuesData {
  currency: string;
  total: number;
  limit: number;
  due: number;
  items: DueRow[];
}

export interface FollowUpRow {
  id: number;
  primary: string;
  secondary: string;
  status: 'due' | 'overdue';
  time: string;
}

export interface FollowUpsData {
  total: number;
  items: FollowUpRow[];
}

export interface AttentionItem {
  id: string;
  primary: string;
  secondary: string;
  count: number;
  amount?: number;
  currency?: string;
  status: string;
  /** Only to a page that exists and that the user may open. */
  href: string | null;
}

export interface AttentionData {
  total: number;
  items: AttentionItem[];
}
