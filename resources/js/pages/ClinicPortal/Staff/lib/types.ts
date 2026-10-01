/** Staff pages' props: the server's contract, mirrored exactly. */

export type StaffTab = 'active' | 'invited' | 'inactive' | 'suspended' | 'archived';

export interface StaffTypeOption {
  value: string;
  label: string;
  clinical: boolean;
}

export interface StaffRow {
  id: number;
  name: string;
  photo_url: string | null;
  roles: string[];
  staff_type: string | null;
  staff_type_label: string | null;
  primary_branch: string | null;
  branch_count: number;
  status: string | null;
  tab: StaffTab;
  is_practitioner: boolean;
  specialty: string | null;
  headline: string | null;
  has_login: boolean;
  invitation_pending: boolean;
  last_activity: string | null;
}

export type DocumentState = 'missing' | 'pending_verification' | 'verified' | 'rejected' | 'expired' | 'expiring_soon';

export interface StaffDocumentVersion {
  id: number;
  title: string;
  file_name: string;
  size: number;
  issued_on: string | null;
  expires_on: string | null;
  expiry_state: 'expired' | 'expiring_soon' | 'valid' | null;
  status: string;
  state: DocumentState;
  notes: string | null;
  review_note: string | null;
  replacement_requested: boolean;
  uploaded_by: string | null;
  uploaded_at: string | null;
  reviewed_by: string | null;
  reviewed_at: string | null;
}

export interface ChecklistItem {
  type: { id: number; key: string; name: string; has_expiry: boolean };
  requirement: 'required' | 'optional' | 'not_applicable';
  state: DocumentState;
  current: StaffDocumentVersion | null;
  history: StaffDocumentVersion[];
}

export interface Qualification {
  id: number;
  label: string;
  qualification: string;
  degree_type: string;
  specialization: string | null;
  institution: string | null;
  year: number | null;
  country: string | null;
  state: string | null;
  staff_document_id: number | null;
  verification_status: 'unverified' | 'verified' | 'rejected';
}

export interface SpecialtyRef {
  id: number | null;
  name: string;
  key?: string | null;
}

export interface PractitionerProfile {
  id: number;
  profession: string | null;
  headline: string | null;
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

export interface LeaveRow {
  id: number;
  type: string;
  type_label: string;
  status: 'requested' | 'approved' | 'rejected' | 'cancelled';
  start_date: string;
  end_date: string;
  reason: string | null;
  note: string | null;
  decision_note: string | null;
  decided_by: string | null;
  affected: number;
}

export interface ProfileProps {
  tab: string;
  employee: {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    status: string;
    status_tab: StaffTab;
    status_changed_at: string | null;
    staff_type: string | null;
    staff_type_label: string | null;
    photo_url: string | null;
    date_of_birth: string | null;
    gender: string | null;
    joining_date: string | null;
    notes: string | null;
    primary_branch: { id: number; name: string } | null;
    branches: { id: number; name: string; is_active: boolean; is_primary: boolean; editable: boolean }[];
    departments: string[];
    roles: string[];
    has_login: boolean;
    login_email: string | null;
    invitation: { email: string; expires_at: string | null } | null;
    created_at: string | null;
    last_activity: string | null;
    is_self: boolean;
  };
  practitioner: PractitionerProfile | null;
  services: {
    catalogue: { id: number; name: string; category: string | null; duration_minutes: number | null }[];
    selected: number[];
  } | null;
  schedule: { weekly: Record<string, Record<string, { start: string; end: string }[]>> } | null;
  leave: LeaveRow[];
  appointments: { id: number; start: string; patient: string; service: string | null; branch: string | null; status: string }[];
  documents: {
    items: ChecklistItem[];
    summary: { required: number; complete: number; missing: string[]; attention: number };
  };
  documentTypes: { id: number; name: string; audience: string; has_expiry: boolean }[];
  activity: { event: string; at: string | null; actor: string | null }[];
  options: {
    branches: { id: number; name: string }[];
    staffTypes: StaffTypeOption[];
    degreeTypes: Record<string, string>;
    registrationTypes: Record<string, string>;
    leaveTypes: Record<string, string>;
    specialties: SpecialtyRef[];
    upload: { mimes: string[]; max_kb: number };
  };
  can: {
    edit: boolean;
    practitioner: boolean;
    verify: boolean;
    roles: boolean;
    approveLeave: boolean;
    appointments: boolean;
  };
  links: {
    index: string;
    edit: string;
    self: string;
    invite: string;
    leave: string;
    appointments: string;
    documentSettings: string;
  };
}
