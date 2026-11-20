/**
 * The patient directory's contract, as `PatientController@index` and
 * `PatientDirectory::page()` send it. The server is the contract.
 */

export type ActiveCare = {
  id: number;
  title: string;
  specialty: string | null;
  status: string;
  progress: { done: number; total: number | null; unit: 'sessions' | 'stages' };
};

export type DirectoryPatient = {
  id: number;
  uhid: string | null;
  name: string;
  /** The full number only for members whose work is contacting patients; null otherwise. */
  phone: string | null;
  /** The last four digits, sent to everyone who may see the patient. */
  phone_last_digits: string | null;
  email: string | null;
  gender: string | null;
  age: number | null;
  /** The stored lifecycle value. Only merged is shown. */
  status: string;
  /** The assigned doctor (`current_practitioner_id`); null when nobody is assigned. */
  assigned_doctor: { id: number; name: string; at_clinic: boolean } | null;
  specialties: string[];
  active_care: ActiveCare[];
  last_activity_at: string | null;
  /** The branch of the last activity, among the member's own branches. */
  last_branch: string | null;
  /** How many more of the member's branches the patient has been seen at. */
  other_branch_count: number;
  /** In the Today view: today's visit, at one of the member's branches. */
  today_visit: { branch: string | null; status: string } | null;
  /** The latest attended visit's case (`patient_visits.registration_type`): new on the first visit, repeat after; null before any visit. */
  case_type: 'new' | 'repeat' | null;
  /** Active clinical case from patient_cases */
  active_case: { case_number: string; title: string; specialty: string } | null;
  /** Outstanding balance; only sent when user holds billing.view permission, null otherwise */
  balance: number | null;
  /** From `patient_treatments`: ongoing while any treatment is open, complete once one is done and none open; null otherwise. */
  treatment_progress: 'ongoing' | 'complete' | null;
  critical_alerts: number;
  registered_on: string | null;
};

export type DirectoryPage = {
  data: DirectoryPatient[];
  next_cursor: string | null;
  prev_cursor: string | null;
  per_page: number;
};

export type DirectoryView = 'mine' | 'today' | 'all';

export type DirectoryAudience = 'owner' | 'doctor' | 'reception' | 'staff';

export type DirectoryFilters = {
  q: string;
  status: string;
  specialty: string;
  branch_id: string;
  /** A doctor's id, or `none` for patients nobody is assigned to. */
  practitioner_id: string;
  gender: string;
  treatment_status: string;
  active_care: string;
  patient_type: string;
  age_min: string;
  age_max: string;
  registered_from: string;
  registered_to: string;
  last_visit_from: string;
  last_visit_to: string;
  view: DirectoryView;
};

export type DirectoryProps = {
  patients: DirectoryPage;
  filters: DirectoryFilters;
  options: {
    specialties: { key: string; label: string }[];
    /** Lifecycle choices besides the default "Current patients". */
    statuses: { value: string; label: string }[];
    /** The views offered to this member, the default first. */
    views: DirectoryView[];
    doctors: { id: number; name: string }[];
  };
  audience: DirectoryAudience;
  /** The clinic's local date (YYYY-MM-DD), what "Today" means. */
  today: string;
  isPractitioner: boolean;
  can: { create: boolean; billing?: boolean };
  links: {
    index: string;
    /** Contains `__ID__`; null until the profile page is built. */
    profile: string | null;
    /** Null until the full registration page is built. */
    create: string | null;
  };
};
