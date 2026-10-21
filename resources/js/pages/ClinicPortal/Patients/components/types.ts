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
  phone: string | null;
  email: string | null;
  gender: string | null;
  age: number | null;
  status: string;
  specialties: string[];
  active_care: ActiveCare[];
  last_activity_at: string | null;
  last_branch: string | null;
  critical_alerts: number;
  registered_on: string | null;
};

export type DirectoryPage = {
  data: DirectoryPatient[];
  next_cursor: string | null;
  prev_cursor: string | null;
  per_page: number;
};

export type DirectoryFilters = {
  q: string;
  status: string;
  specialty: string;
  branch_id: string;
  active_care: string;
  tab: 'mine' | 'all';
};

export type DirectoryProps = {
  patients: DirectoryPage;
  filters: DirectoryFilters;
  options: {
    specialties: { key: string; label: string }[];
    statuses: { value: string; label: string }[];
  };
  isPractitioner: boolean;
  can: { create: boolean };
  links: {
    index: string;
    /** Contains `__ID__`; null until the profile page is built. */
    profile: string | null;
    /** Null until the full registration page is built. */
    create: string | null;
  };
};
