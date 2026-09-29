export type BranchStatus = 'active' | 'inactive' | 'suspended';

export interface OperatingHour {
  /** 0 = Sunday … 6 = Saturday — matches PHP's `date('w')`, same as `ScheduleValidationService`. */
  day_of_week: number;
  open_time: string;
  close_time: string;
  is_closed: boolean;
}

export interface Branch {
  id: number;
  clinic_id: number;
  organization_id: number;
  name: string;
  slug: string;
  status: BranchStatus;
  is_main: boolean;

  address_line1: string | null;
  address_line2: string | null;
  city: string | null;
  state: string | null;
  pincode: string | null;

  phone: string | null;
  whatsapp_number: string | null;

  /** A planning target, not a live count of `resources` rows. */
  resource_capacity: number | null;

  gstin: string | null;
  clinical_establishment_registration_number: string | null;

  /** Override the clinic's own name/logo when this branch is active; null falls back to the clinic default. */
  display_name: string | null;
  logo_url: string | null;

  operating_hours: OperatingHour[];
  /** Server-computed, e.g. "9:00 AM – 6:00 PM", "Closed today", "Hours not set". */
  today_hours_label: string;

  created_at: string;
  updated_at: string;
}

export interface BranchLimit {
  current: number;
  /** Null when the clinic has no active subscription — the cap can't be known. */
  allowed: number | null;
}

export interface BranchesPageProps {
  branches: Branch[];
  branchStatuses: BranchStatus[];
  branchLimit: BranchLimit;
}
