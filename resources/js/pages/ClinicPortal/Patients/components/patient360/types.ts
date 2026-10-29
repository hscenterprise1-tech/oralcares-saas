import type { PhysioCare } from '@/clinic/patients/PhysioCare';

/**
 * Patient 360's contract, as `Patient360Controller@show` and
 * `Patient360Summary` send it. The server is the contract: a field the user
 * may not see arrives empty or null, never hidden here.
 */

export type Patient360Identity = {
  id: number;
  name: string;
  first_name: string | null;
  uhid: string | null;
  age: number | null;
  gender: string | null;
  blood_group: string | null;
  /** The full number only for members whose work is contacting patients. */
  phone: string | null;
  phone_last_digits: string | null;
  email: string | null;
  /** The stored lifecycle value; only deceased and merged are shown. */
  status: string;
  registered_on: string | null;
  /** The branch of the last visit, among the member's branches. */
  branch: string | null;
  last_visit_on: string | null;
  /** Where the patient was registered, when it is one of the member's branches. */
  registered_branch: string | null;
  /** The death record (governed `patient.record_death`), when one exists. */
  deceased: { on: string | null; recorded_at: string | null; recorded_by: string | null } | null;
  /** The assigned doctor (`current_practitioner_id`). */
  practitioner: string | null;
};

export type NextAppointment = {
  id: number;
  date_label: string;
  time_label: string;
  is_today: boolean;
  practitioner: string | null;
  branch: string | null;
  status: string | null;
};

export type Patient360State = {
  last_visit: string | null;
  next_appointment: NextAppointment | null;
  /** Null when the member may not see money. */
  outstanding: number | null;
  episode: { kind: string; title: string; done: number; total: number | null; id: number; status: string } | null;
};

export type Patient360Context = {
  allergies: string[];
  /** Staff-recorded precautions (`manual`, `flag`), most severe first; who recorded each where that was stored. */
  alerts: { id: number; text: string; severity: string | null; type: string; recorded_on: string | null; recorded_by: string | null }[];
  conditions: { diagnosed: { description: string; code: string | null; status: string; noted_on: string | null }[]; reported: string[]; flags: string[] };
  medications: {
    prescribed: { name: string; dosage: string | null; frequency: string | null; duration: string | null; prescribed_on: string | null }[];
    reported: string[];
  };
  /** Medical-history conditions (clinical entries of kind `condition`), most severe first; clinical roles only. */
  medical_conditions: MedicalCondition[];
  /** The medical history's notes (clinical roles only). */
  medical_notes: string | null;
  medical_notes_updated_on: string | null;
  medical_notes_updated_by: string | null;
  /** Whether conditions and medicines were sent at all (clinical roles). */
  clinical: boolean;
};

export type MedicalCondition = {
  key: string | null;
  label: string;
  severity: string | null;
  /** registration (reported at the desk) · clinical (recorded by clinical staff) · backfill (older records) */
  source: string;
  recorded_on: string | null;
  recorded_by: string | null;
};

/** The condition editor (`PUT …/clinical-entries/condition`), for members who may write this patient's clinical record. */
export type ConditionEditor = {
  url: string;
  options: { key: string; name: string; severity: string | null }[];
  severities: string[];
  /** False where the clinic records conditions only from its list: no typed names (the endpoint refuses them too). */
  custom_allowed: boolean;
};

export type SafetyEditor = { alerts: string; resolve: string; notes: string; severities: string[] };

/** Address, preferred channel and emergency contact; addresses and full numbers follow the contact rule. */
export type Patient360Profile = {
  address: string | null;
  preferred_channel: string | null;
  alternate_phone: string | null;
  emergency_contact: { name: string | null; phone: string | null; phone_last_digits: string | null } | null;
  contact_visible: boolean;
};

export type Patient360Document = {
  id: number;
  name: string;
  category: string;
  date: string | null;
  uploaded_by: string | null;
  source: string;
  file_type: string;
  size: number | null;
  status: string;
  preview_url: string | null;
  download_url: string | null;
};

export type ActiveCareItem = {
  id: number;
  title: string;
  specialty: string | null;
  status: string;
  practitioner: { id: number; name: string } | null;
  progress: { done: number; total: number | null; unit: 'sessions' | 'stages' };
  next_stage: { id: number; name: string; status: string } | null;
  started_at: string | null;
};

export type Patient360Overview = {
  summary: {
    /** Null for members who may not see appointments. */
    appointments: { upcoming: number; attended: number; missed: number } | null;
    /** Null for members who may not see money. */
    finance: { billed: number; paid: number; outstanding: number; bills: number } | null;
  };
  /** The latest documents this member may open; null when they may not open Documents. */
  documents: Patient360Document[] | null;
  /** Physiotherapy at a glance (`PhysioCareSummary`); null outside physiotherapy clinics. */
  physio_care: PhysioCare | null;
  current: {
    care_streams: unknown[];
    active_care: ActiveCareItem[];
    open_consultation: { id: number; encounter_number: string | null } | null;
  };
  next: {
    appointment: NextAppointment | null;
    follow_up: { id: number; due_on: string; reason: string | null; practitioner: string | null; status: string; overdue: boolean } | null;
    investigations: { name: string; status: string; category: string; url: string | null }[];
    payments: { id: number; number: string | null; outstanding: number }[];
  };
  recent: {
    consultation: { id: number; number: string | null; date: string | null; practitioner: string | null; complaint: string | null; diagnosis: string | null; signed: boolean; url: string | null } | null;
    prescription: { number: string | null; date: string | null; practitioner: string | null; status: string; medicines: string[]; print_url: string | null } | null;
    investigation: { name: string; status: string; date: string | null; summary: string | null; is_abnormal: boolean; url: string | null } | null;
    procedure: { name: string; tooth: number | string | null; date: string | null } | null;
  };
};

export type Patient360Section = { key: string; label: string; group: string; built: boolean };

/** A visit (`patient_visits`) or a booking the patient never came for. */
export type HistoryRow =
  | {
      kind: 'visit';
      id: number;
      date: string | null;
      time_label: string | null;
      practitioner: string | null;
      branch: string | null;
      status: string;
      /** Whether the patient came: registered-only and cancelled visits are not attendance. */
      attended: boolean;
      case_number: string | null;
      registration_type: 'new' | 'repeat' | null;
      visit_type: string | null;
      reason: string | null;
      /** The booking's time when the visit came from one. */
      booked_for: string | null;
      /** Clinical roles only. */
      consultation: { number: string | null; status: string; url: string | null } | null;
      calendar_url: string | null;
    }
  | {
      kind: 'booking';
      id: number;
      date: string;
      time_label: string | null;
      practitioner: string | null;
      branch: string | null;
      /** missed · cancelled · not_attended · no_visit_recorded */
      status: string;
      attended: false;
      type: string | null;
      calendar_url: string | null;
    };

export type VisitsPage = {
  data: HistoryRow[];
  meta: { page: number; last_page: number; total: number };
  counts: { visits: number; not_attended: number };
};

export type Patient360Action = {
  key: string;
  label: string;
  kind: 'primary' | 'desk' | 'more';
  /** Whether its page or section is built; only these are offered. */
  available: boolean;
  url?: string;
  method?: string;
  section?: string;
};

export type Patient360Props = {
  patient: Patient360Identity;
  state: Patient360State;
  context: Patient360Context;
  profile: Patient360Profile;
  practitioners: { id: number; name: string; specialty: string | null }[];
  /** Deferred: undefined until it arrives. */
  overview?: Patient360Overview;
  lens: {
    persona: string;
    default_section: string;
    owner: boolean;
    owner_mode: boolean;
    /** How this member may take each governed action: `direct` (owner), `request`, or null. */
    governance: Record<string, 'direct' | 'request' | null>;
    /** Requests already waiting on the owner, as "action:target". */
    open_requests: string[];
    fields: { clinical: boolean; clinical_write: boolean; financial: boolean; refunds: boolean; investigation_results: boolean; dental: boolean; physio: boolean };
  };
  sections: Patient360Section[];
  open_section: string;
  actions: Patient360Action[];
  /** Section endpoints, by section key (`visits`, ...), and the governance endpoints. */
  endpoints: Record<string, string | null>;
  /** `bills`: the Billing register filtered to this patient, for members it admits. */
  links: { index: string; bills: string | null };
  condition_editor: ConditionEditor | null;
  /** Managing alerts and notes (`PatientSafetyController`); `resolve` holds `__ALERT__` for the alert id. */
  safety_editor: SafetyEditor | null;
};
