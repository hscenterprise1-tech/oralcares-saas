/** `StaffProfileView::profile`: the server is the contract. */
export interface Branch {
  id: number;
  name: string;
  is_active: boolean;
  is_primary: boolean;
  editable: boolean;
}

export interface Employee {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  status: string | null;
  status_tab: string;
  staff_type: string | null;
  staff_type_label: string | null;
  photo_url: string | null;
  date_of_birth: string | null;
  gender: string | null;
  joining_date: string | null;
  notes: string | null;
  primary_branch: { id: number; name: string } | null;
  branches: Branch[];
  departments: string[];
  roles: string[];
  has_login: boolean;
  login_email: string | null;
  invitation: { email: string; expires_at: string | null } | null;
  last_activity: string | null;
  is_self: boolean;
}

export interface Qualification {
  id: number;
  label: string;
  qualification: string;
  degree_type: string | null;
  specialization: string | null;
  institution: string | null;
  year: number | null;
  verification_status: string | null;
}

export interface SpecialtyRef {
  id: number | null;
  name: string;
  area: string | null;
}

export interface Practitioner {
  id: number;
  profession: string | null;
  headline: string | null;
  practice: { key: string; label: string; module: string }[];
  primary_specialty: SpecialtyRef | null;
  additional_specialties: SpecialtyRef[];
  experience_years: number | null;
  bio: string | null;
  is_active: boolean;
  registration: {
    number: string | null;
    authority: string | null;
    state: string | null;
    type: string | null;
    date: string | null;
    expires_at: string | null;
    expiry_state: 'expired' | 'expiring_soon' | 'valid' | null;
  };
  qualifications: Qualification[];
}

export interface DocumentItem {
  type: { id: number; key: string; name: string; has_expiry: boolean };
  requirement: string;
  state: string;
  current: { id: number; title: string | null; file_name: string; expires_on: string | null; state: string; status: string } | null;
  history: { id: number }[];
}

export interface LeaveRow {
  id: number;
  type_label: string;
  status: string;
  start_date: string;
  end_date: string;
  reason: string | null;
  affected: number;
}

export interface Profile {
  tab: string;
  employee: Employee;
  practitioner: Practitioner | null;
  services: { catalogue: { id: number; name: string; category: string | null; duration_minutes: number | null }[]; selected: number[] } | null;
  schedule: { weekly: Record<string, Record<string, { start: string; end: string }[]>> } | null;
  leave: LeaveRow[];
  appointments: { id: number; start: string; patient: string; service: string | null; branch: string | null; status: string }[];
  documents: { items: DocumentItem[]; summary: { required: number; complete: number; missing: string[]; attention: number } };
  documentTypes: { id: number; name: string; audience: string; has_expiry: boolean }[];
  activity: { event: string; at: string | null; actor: string | null }[];
  options: {
    branches: { id: number; name: string }[];
    staffTypes: { value: string; label: string; clinical: boolean }[];
    degreeTypes: Record<string, string>;
    registrationTypes: Record<string, string>;
    leaveTypes: Record<string, string>;
    specialties: { id: number | null; key: string | null; area: string | null; name: string; group: string }[];
    upload: { mimes: string[]; max_kb: number };
  };
  can: { edit: boolean; practitioner: boolean; verify: boolean; roles: boolean; approveLeave: boolean; appointments: boolean };
  links: { index: string; edit: string; self: string; invite: string; leave: string; appointments: string; documentSettings: string };
}
