/*
 * The Front Desk's payload, as `App\Services\Reception\FrontDesk` and
 * `ReceptionController` send it. The server is the contract: change these
 * only to follow it, never to add a field it does not send.
 */

export type ListKey = 'waiting' | 'with_doctor' | 'done' | 'not_present';

export interface SafetyFlag {
  kind: string;
  label: string;
  severity: string;
}

export interface QueueRow {
  id: number;
  status: string;
  priority: string;
  patient: { id: number; name: string; uhid: string | null; age: number | null; gender: string | null } | null;
  source: 'appointment' | 'walk_in';
  case: string | null;
  visit: { specialty_key: string | null; specialty: string | null; type_key: string | null; type: string | null; reason: string | null } | null;
  doctor: { id: number; name: string } | null;
  /** Clinic wall-clock, `Y-m-d\TH:i:s`, no zone. */
  checked_in_at: string | null;
  called_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  wait_minutes: number | null;
  safety: SafetyFlag[];
  safety_more: number;
  can_correct: boolean;
  /** Only on "done" rows: null without billing rights. `none`: nothing was charged (e.g. paid from a package). */
  payment?: 'paid' | 'due' | 'not_billed' | 'none' | null;
  /** Only on "done" rows, for a member who may take it: the payment to collect from the row. */
  collect?: DeskCollect | null;
  links: {
    status: string;
    priority: string;
    doctor: string;
    visit: string;
    patient: string | null;
    /** The desk opens the physiotherapy visit for a therapist without a login (POST). */
    desk_open?: string;
    /** That visit's workspace, once opened. */
    desk_workspace?: string;
  };
}

/** A payment the desk takes in one step: a bill's balance, or (`bills`) the visit billed at the clinic's prices and paid. */
export interface DeskCollect {
  amount: number;
  url: string;
  bills: boolean;
  encounter_id: number;
  bill_id: number | null;
}

export interface BookingRow {
  id: number;
  patient: { id: number; name: string; uhid: string | null } | null;
  prospect: { name: string | null; phone: string | null } | null;
  doctor: string | null;
  doctor_id: number | null;
  type: string | null;
  scheduled_at: string | null;
  late_minutes: number | null;
  /** `check_in`: only for a registered patient's booking (Reception's check-in, by the booking). */
  links: { status: string; check_in?: string };
}

export interface DoctorRow {
  id: number;
  name: string;
  specialties: string[];
  practice: string | null;
  shift: string;
  shift_label: string;
  room: string | null;
  waiting: number;
  with_patient: boolean;
  done: number;
}

export interface Desk {
  now: string;
  counts: { today: number; walk_ins: number; waiting: number; with_doctor: number; done: number; not_present: number; appointments: number; booked_not_arrived: number };
  waiting: QueueRow[];
  with_doctor: QueueRow[];
  done: QueueRow[];
  upcoming: BookingRow[];
  attention: {
    long_wait: { count: number; minutes: number; rows: QueueRow[] };
    unassigned: { count: number; rows: QueueRow[] };
    not_present: { rows: QueueRow[] };
    late_bookings: { count: number; rows: BookingRow[] };
  };
  doctors: DoctorRow[];
}

export interface DeskPatientRef {
  id: number;
  name: string;
  uhid: string | null;
}

export interface DeskBilling {
  currency: string;
  can: { collect: boolean; bill: boolean };
  methods: { value: string; label: string }[];
  ready: { encounter_id: number; number: string | null; patient: DeskPatientRef | null; doctor: string | null; lines: { description: string; quantity: number; amount: number }[]; total: number; links: { collect: string; checkout: string } }[];
  ready_more: boolean;
  unpaid: { id: number; number: string | null; patient: DeskPatientRef | null; total: number; outstanding: number; plan: { due_on: string; remaining: number; overdue: boolean; left: number } | null; links: { pay: string } }[];
  unpaid_count: number;
  /** The day's takings: only for members who read the ledger (`billing.view`). */
  collected_today: { total: number; payments: number } | null;
  /** The Billing work center: only for members who read the ledger. */
  links: { all: string | null; closing: string | null };
}

/** `FrontDesk::labCases`: dental lab work the desk moves: to send, out with the lab, or arrived and waiting for the patient. */
export interface DeskLab {
  count: { to_send: number; with_lab: number; late: number; arrived: number };
  rows: { id: number; number: string | null; patient: DeskPatientRef | null; what: string; lab: string | null; state: 'to_send' | 'with_lab' | 'arrived'; due_on: string | null; late: boolean; steps: { send?: string; receive?: string }; links: { patient: string } }[];
}

export interface DeskFollowUps {
  count: { overdue: number; today: number };
  rows: { id: number; patient: DeskPatientRef | null; practitioner: string | null; reason: string | null; priority: string | null; days_overdue: number; links: { patient: string } }[];
  links: { all: string };
}

export const LIST_LABELS: Record<ListKey, string> = {
  waiting: 'Waiting',
  with_doctor: 'With doctor',
  done: 'Done',
  not_present: 'Not present',
};
