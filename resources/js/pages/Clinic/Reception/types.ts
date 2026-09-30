/**
 * The Live Queue payload — mirrors `App\Services\Reception\QueueBoard::forBranch()`
 * exactly. The server is the contract: change both together.
 */

export type QueueStatus = 'waiting' | 'called' | 'in_consultation' | 'skipped';

export type QueueAction = 'call' | 'recall' | 'start' | 'complete' | 'skip' | 'requeue' | 'cancel';

export interface QueueRow {
  id: number;
  status: QueueStatus;
  priority: 'normal' | 'emergency' | 'senior_citizen' | 'child' | 'vip';
  patient: { id: number | null; short_name: string };
  case_short: string | null;
  doctor: { id: number; name: string } | null;
  room: string | null;
  booked_for: string | null;
  checked_in_at: string | null;
  called_at: string | null;
  started_at: string | null;
  wait_minutes: number | null;
}

export interface AttentionEntry {
  id: string;
  kind: 'emergency' | 'called_not_seen' | 'long_wait' | 'doctor_on_leave' | 'late';
  title: string;
  detail: string;
  minutes: number;
  item_id?: number;
  appointment_id?: number;
}

export interface DoctorRow {
  id: number;
  name: string;
  state: 'available' | 'busy' | 'off_shift' | 'on_leave';
  shift_label: string;
  room: string | null;
  waiting: number;
  with_patient: string | null;
  seen_today: number;
}

export interface ActivityRow {
  id: number;
  at: string | null;
  action: string;
  patient: string;
  case_short: string | null;
  doctor: string | null;
  by: string | null;
  notes: string | null;
}

export interface QueueBoardData {
  now: string;
  rooms_enabled: boolean;
  thresholds: { long_wait_minutes: number; called_not_seen_minutes: number; late_arrival_minutes: number };
  summary: { booked: number; checked_in: number; waiting: number; in_consultation: number; completed: number };
  attention: AttentionEntry[];
  queue: QueueRow[];
  doctors: DoctorRow[];
  booking_status: { total: number; segments: { status: string; count: number }[] };
  wait_times: {
    average: number | null;
    longest: number | null;
    seen: number;
    by_hour: { hour: number; average: number; longest: number; count: number }[];
  };
  activity: ActivityRow[];
}

export interface ReceptionPageProps {
  branch: { id: number; name: string };
  board: QueueBoardData;
}
