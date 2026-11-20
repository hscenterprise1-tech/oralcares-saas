import type { DeskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import { ageSex, lastSeen, shortDate } from '@/pages/ClinicPortal/Patients/components/format';
import type { DirectoryAudience, DirectoryPatient, DirectoryProps, DirectoryView } from '@/pages/ClinicPortal/Patients/components/types';
import type { DsTone } from '../../../ds';

/**
 * The adapter between the server's directory contract (`PatientDirectory`,
 * unchanged) and the register screen. The screen reads only this model, so
 * its design is not tied to the shape the old page used. Nothing here is
 * invented: every value is the server's, reworded or regrouped.
 */
export interface RegisterRow {
  id: number;
  name: string;
  uhid: string | null;
  facts: string | null;
  /** The full number only when the server sent it (contact rights); otherwise null. */
  phone: string | null;
  phoneTail: string | null;
  email: string | null;
  lifecycle: 'merged' | null;
  alerts: number;
  doctor: { name: string; atClinic: boolean } | null;
  specialties: { key: string; label: string }[];
  care: { id: number; title: string; specialty: string | null; done: number; total: number | null; unit: string }[];
  lastActivity: string | null;
  lastBranch: string | null;
  otherBranches: number;
  today: { label: string; tone: DsTone; branch: string | null } | null;
  registered: string | null;
  /** Patient 360, when the server gave its address. */
  href: string | null;
}

export interface RegisterLens {
  key: DirectoryView;
  label: string;
}

const LENS_LABEL: Record<DirectoryView, string> = { today: 'Today', mine: 'My patients', all: 'All patients' };

/** Where today's visit stands (`patient_visits.visit_status`), in the clinic's own words and the front desk's colours. */
function todayStatus(status: string | undefined, mode: DeskMode): { label: string; tone: DsTone } {
  switch (status) {
    case 'with_doctor':
    case 'in_progress':
      return { label: mode.active, tone: 'violet' };
    case 'completed':
      return { label: mode.physio && !mode.dental ? 'Session done' : 'Seen today', tone: 'green' };
    default:
      return { label: 'Checked in', tone: 'amber' };
  }
}

export function toRow(patient: DirectoryPatient, props: Pick<DirectoryProps, 'links' | 'options'>, mode: DeskMode): RegisterRow {
  const labels = Object.fromEntries(props.options.specialties.map((s) => [s.key, s.label]));

  return {
    id: patient.id,
    name: patient.name,
    uhid: patient.uhid,
    facts: ageSex(patient.age, patient.gender),
    phone: patient.phone,
    phoneTail: patient.phone_last_digits,
    email: patient.email,
    lifecycle: patient.status === 'merged' ? 'merged' : null,
    alerts: patient.critical_alerts,
    doctor: patient.assigned_doctor ? { name: patient.assigned_doctor.name, atClinic: patient.assigned_doctor.at_clinic } : null,
    specialties: patient.specialties.filter((key) => labels[key]).map((key) => ({ key, label: labels[key] })),
    care: patient.active_care.map((care) => ({ id: care.id, title: care.title, specialty: care.specialty, done: care.progress.done, total: care.progress.total, unit: care.progress.unit })),
    lastActivity: lastSeen(patient.last_activity_at),
    lastBranch: patient.last_branch,
    otherBranches: patient.other_branch_count,
    today: patient.today_visit ? { ...todayStatus(patient.today_visit.status, mode), branch: patient.today_visit.branch } : null,
    registered: shortDate(patient.registered_on),
    href: props.links.profile ? props.links.profile.replace('__ID__', String(patient.id)) : null,
  };
}

export function lensesFor(views: DirectoryView[]): RegisterLens[] {
  return views.map((key) => ({ key, label: LENS_LABEL[key] }));
}

/** The page's title and the one line under it, for the lens and for who is looking. */
export function headingFor(audience: DirectoryAudience, view: DirectoryView, doctorWord: string): { title: string; line: string } {
  if (view === 'today') {
    return { title: 'Today’s patients', line: audience === 'doctor' ? `Checked in today to see you.` : 'Everyone checked in today, in arrival order.' };
  }
  if (view === 'mine') {
    return { title: 'My patients', line: `Patients you are the assigned ${doctorWord.toLowerCase()} for.` };
  }

  return { title: 'Patients', line: audience === 'owner' ? 'Everyone registered at this clinic, newest first.' : 'Everyone you can open at your branches, newest first.' };
}
