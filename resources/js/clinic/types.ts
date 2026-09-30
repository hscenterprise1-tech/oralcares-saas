/**
 * The Clinic Portal's contract with the server.
 *
 * These mirror what `HandleInertiaRequests::clinicContext()` and the clinic
 * controllers actually send. They are not a second source of truth: when the
 * server changes a prop, this file changes with it.
 */

export type ClinicPermission =
  | 'patient.view'
  | 'patient.create'
  | 'patient.edit'
  | 'patient.delete'
  | 'patient.search'
  | 'appointment.view'
  | 'appointment.create'
  | 'appointment.manage'
  | 'queue.manage'
  | 'visit.view'
  | 'visit.create'
  | 'clinical.view'
  | 'clinical.edit'
  | 'prescription.manage'
  | 'documents.manage'
  | 'billing.view'
  | 'billing.create'
  | 'payment.collect'
  | 'discount.apply'
  | 'refund.process'
  | 'practitioner.manage'
  | 'staff.manage'
  | 'roles.manage'
  | 'branch.manage'
  | 'settings.manage'
  | 'reports.view';

export interface BranchSummary {
  id: number;
  name: string;
  slug?: string | null;
  is_main: boolean;
}

export interface ActiveBranch extends BranchSummary {
  display_name: string | null;
  logo_url: string | null;
}

export type NavSection = 'primary' | 'admin';

/** One destination, already filtered by the server to what this user may open. */
export interface NavDestination {
  key: string;
  label: string;
  section: NavSection;
  href: string;
  keywords: string[];
}

export interface ClinicContextProps {
  id: number;
  name: string;
  branding_name: string | null;
  logo_url: string | null;
  slug: string;
  organization_id: number | null;
  active_branch: ActiveBranch | null;
  /** At most `SHARED_BRANCH_LIMIT`; `accessible_branch_count` is the real total. */
  accessible_branches: BranchSummary[];
  accessible_branch_count: number;
  is_owner: boolean;
  has_all_branches_access: boolean;
  enabled_modules: string[];
  has_subscription: boolean;
  permissions: ClinicPermission[];
  roles: string[];
  navigation: NavDestination[];
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  user_type: string | null;
}

export interface FlashProps {
  success?: string | null;
  message?: string | null;
  error?: string | null;
  warning?: string | null;
}

export interface SubscriptionWindowProps {
  state: string;
  days_remaining: number | null;
  plan: string | null;
}

/** The portal's endpoints, built by the server for path or domain routing. */
export interface PortalLinks {
  signIn: string;
  signOut: string;
  forgotPassword: string;
  dashboard: string;
  branches: string;
  branchSwitch: string;
  notifications: string;
  notificationsUnreadCount: string;
  notificationsMarkAllRead: string;
}

/** Props every Clinic Portal page receives from the shared middleware. */
export interface ClinicSharedProps {
  auth: { user: AuthUser | null };
  clinic: ClinicContextProps | null;
  portalLinks: PortalLinks | null;
  flash: FlashProps;
  subscriptionWindow?: SubscriptionWindowProps;
  errors: Record<string, string>;
  [key: string]: unknown;
}

export interface NotificationItem {
  id: string;
  title: string | null;
  category: string | null;
  priority: string | null;
  read_at: string | null;
  created_at: string;
  data: { message?: string; body?: string; url?: string } | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export interface BranchPage {
  data: BranchSummary[];
  active_branch_id: number | null;
  next_page: number | null;
}
