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

/** A navigation entry the server resolved for this user (`ClinicNavigation`). */
export interface NavLink {
  key: string;
  label: string;
  /** A key into the portal's icon map, never a component name from the server. */
  icon: string;
  href: string;
  keywords: string[];
}

/** One business domain this user may open, with the children they may open. */
export interface NavDestination extends NavLink {
  children: NavLink[];
}

/** Colour roles. Each means something: brand = primary, blue = scheduling, green = done/paid, amber = pending, red = urgent, violet = clinical. */
export type Tone = 'brand' | 'blue' | 'green' | 'amber' | 'red' | 'violet' | 'slate' | 'ink';

/** A quick action this user may start (`ClinicActions`). */
export interface QuickAction extends NavLink {
  description: string;
  tone: Tone;
}

export type WidgetKind = 'kpi' | 'list' | 'stats' | 'trend' | 'facts' | 'actions';

/** Where a widget sits: compact figures across the top, operational lists, or the side column. */
export type WidgetZone = 'summary' | 'main' | 'side';

/** A dashboard widget the server resolved for this user (`DashboardWidgets`). Data arrives separately. */
export interface DashboardWidget {
  key: string;
  title: string;
  description: string;
  icon: string;
  tone: Tone;
  kind: WidgetKind;
  zone: WidgetZone;
  empty: string | null;
  action: { label: string; href: string } | null;
}

export interface KpiData {
  value: number;
  detail?: string;
  format?: 'number' | 'currency';
  currency?: string;
}

export interface ListItem {
  id: number | string;
  time?: string | null;
  primary: string;
  secondary?: string | null;
  status?: string | null;
  amount?: number;
  currency?: string;
  /** How many, for a summarised item (e.g. "Pending payments · 3"). */
  count?: number;
  /** Where the row opens, when the user may go there. */
  href?: string | null;
}

export interface ListData {
  total: number;
  items: ListItem[];
  summary?: { value: number; label: string; format: 'number' | 'currency'; currency?: string; tone?: Tone };
}

export interface StatsData {
  currency?: string;
  stats: { key: string; label: string; value: number; tone: Tone; format?: 'number' | 'currency' }[];
}

export interface TrendData {
  series: { a: string; b: string };
  points: { label: string; date: string; a: number; b: number }[];
}

export interface FactsData {
  rows: {
    id?: number;
    label: string;
    value?: string;
    amount?: number;
    currency?: string;
    tone?: Tone;
    values?: { label: string; value: number; tone: Tone }[];
  }[];
}

export type WidgetData = KpiData | ListData | StatsData | TrendData | FactsData | { error: true };

/** The owner's choice of widgets per staff role (Owner Mode only). */
export interface DashboardVisibilitySettings {
  action: string;
  roles: string[];
  widgets: { key: string; title: string }[];
  hidden: Record<string, string[]>;
}

/**
 * Owner Mode as the server sees it on this request. Presentation only: the
 * server checks Owner Mode itself on every owner-only action.
 */
export interface OwnerModeStatus {
  /** The signed-in user is the owner of this clinic. */
  available: boolean;
  active: boolean;
  expires_at: string | null;
  idle_timeout_minutes: number;
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
  /** Staff roles held at this branch, whether or not the user is the owner. */
  staff_roles: string[];
  owner_mode: OwnerModeStatus;
  navigation: NavDestination[];
  actions: QuickAction[];
  specialties: string[];
  capabilities: string[];
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
  /** POST to enter Owner Mode, DELETE to leave it. */
  ownerMode: string;
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
