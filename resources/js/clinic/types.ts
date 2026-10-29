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
  | 'patient.search'
  | 'appointment.view'
  | 'appointment.create'
  | 'appointment.manage'
  | 'queue.manage'
  | 'front_office.operate'
  | 'visit.view'
  | 'visit.create'
  | 'clinical.view'
  | 'clinical.edit'
  | 'prescription.manage'
  | 'treatment.start'
  | 'treatment.execute'
  | 'physio.session.confirm'
  | 'catalog.manage'
  | 'specialty.configure'
  | 'consent.manage'
  | 'patient.merge'
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
  | 'reports.view'
  | 'staff.documents.verify'
  | 'leave.request'
  | 'leave.approve'
  | 'expense.manage'
  | 'expense.approve'
  | 'payroll.view'
  | 'payroll.manage';

export interface BranchSummary {
  id: number;
  name: string;
  slug?: string | null;
  is_main: boolean;
}

export interface ActiveBranch extends BranchSummary {
  display_name: string | null;
  logo_url: string | null;
  /** The name is provisioning's fallback ("Main Branch", a trial's company name), not one somebody chose. */
  name_is_default: boolean;
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

/** One destination this user may open (`ClinicNavigation::for`). */
export interface NavDestination extends Omit<NavLink, 'href'> {
  /** Null when the destination has no backend yet. */
  href: string | null;
  /** `main` / `additional` are the sidebar's; `admin` is the account menu's. */
  section: 'main' | 'additional' | 'admin';
  section_label: string;
  /** Whether its new frontend exists yet (`ClinicFrontend`); otherwise it is shown as coming soon. */
  available: boolean;
  /** No entry is an action any more; New Case / Repeat Case are the Dashboard's quick actions. */
  action: null;
  children: NavLink[];
}

/** Colour roles. Each means something: brand = primary, blue = scheduling, green = done/paid, amber = pending, red = urgent, violet = clinical. */
export type Tone = 'brand' | 'blue' | 'green' | 'amber' | 'red' | 'violet' | 'slate' | 'ink';

/** A quick action this user may start (`ClinicActions`). */
/** A role workspace (Command Center, Front Desk, Today, Billing). */
export interface Workspace {
  key: 'command_center' | 'front_desk' | 'today' | 'billing';
  label: string;
  icon: string;
  href: string;
  primary: boolean;
}

export interface QuickAction extends NavLink {
  description: string;
  tone: Tone;
}

export type WidgetKind = 'kpi' | 'patients' | 'appointments' | 'dues' | 'follow_ups' | 'attention' | 'list' | 'stats' | 'facts';

/**
 * Where a widget sits (`DashboardWidgets::ZONES`): the compact figures beside
 * the quick actions, today's patients and appointments, today's pending
 * payments, what needs attention, and the Owner/Admin dashboard's clinic-wide
 * management widgets.
 */
export type WidgetZone = 'figures' | 'work' | 'payments' | 'side' | 'management';

/** A dashboard widget the server composed for this user (`DashboardWidgets`), in order. Data arrives separately. */
export interface DashboardWidget {
  key: string;
  title: string;
  description: string;
  icon: string;
  tone: Tone;
  kind: WidgetKind;
  zone: WidgetZone;
  empty: string | null;
  /** Left out once its data shows nothing to act on (Needs attention, Follow-ups). */
  hide_when_empty: boolean;
  /** Only to a page that exists and that the user may open. */
  action: { label: string; href: string } | null;
}

/** A quick action whose destination works (`ClinicActions::forDashboard`): it opens the intake, or links. A tile is its icon and name only. */
export interface DashboardAction {
  key: string;
  label: string;
  icon: string;
  tone: Tone;
  /** `large` tiles sit side by side; `row` tiles are compact full-width rows beneath them. */
  tile: 'large' | 'row';
  intake: 'new' | 'repeat' | null;
  href: string | null;
}

export interface KpiData {
  value: number;
  /** One short line under the figure, when there is something worth saying. */
  detail?: string | null;
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
}

export interface StatsData {
  currency?: string;
  stats: { key: string; label: string; value: number; tone: Tone; format?: 'number' | 'currency' }[];
}

export interface FactsData {
  currency?: string;
  rows: {
    id?: number;
    label: string;
    value?: string;
    amount?: number;
    currency?: string;
    tone?: Tone;
    values?: { label: string; value: number; tone: Tone; format?: 'number' | 'currency' }[];
  }[];
}

export type WidgetData = KpiData | ListData | StatsData | FactsData | { error: true };

/**
 * Owner Mode as the server sees it on this request. Presentation only: the
 * server checks Owner Mode itself on every owner-only action.
 */
export interface OwnerModeStatus {
  /**
   * Owner Mode can be entered here: the signed-in user owns this clinic and
   * also works in it as a doctor. Any other owner holds the owner's
   * privileges from sign-in, with no mode to enter.
   */
  available: boolean;
  /** A doctor-owner is in Owner Mode (Doctor Mode otherwise). */
  active: boolean;
  /** The signed-in user owns this clinic. */
  owner: boolean;
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
  /** Separately sold modules (queue, …) and whether each is on here. Presentation only. */
  clinic_modules: Record<string, boolean>;
  has_subscription: boolean;
  permissions: ClinicPermission[];
  roles: string[];
  /** Staff roles held at this branch, whether or not the user is the owner. */
  staff_roles: string[];
  owner_mode: OwnerModeStatus;
  navigation: NavDestination[];
  actions: QuickAction[];
  /** Whether the one New Case / Repeat Case intake may open here (`ClinicNavigation::intake`). */
  intake: { available: boolean; can_register: boolean };
  /** What the top bar's search may look in for this user, in order (`ClinicSearch::searchable`). */
  searchable: string[];
  /** The plan, sent only to the owner with Owner Mode verified on the server. */
  owner_subscription: OwnerSubscription | null;
  /** The role workspaces this user switches between, primary first. */
  workspaces: Workspace[];
  specialties: string[];
  capabilities: string[];
  /** Specialty extensions available here (odontogram, physio_assessment, …). */
  extensions: string[];
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

/** Every member's banner data: the access state and days left, never the plan (that is the owner's, in Owner Mode). */
export interface SubscriptionWindowProps {
  state: string;
  days_remaining: number | null;
}

/** The clinic's plan for the owner in Owner Mode (`ClinicOwnerSubscription`); null for everyone else. */
export interface OwnerSubscription {
  state: 'active' | 'trial' | 'grace' | 'lapsed' | 'none';
  plan: string | null;
  /** The day the current state ends, at the clinic; null when open-ended or unknown. */
  ends_on: string | null;
  days_remaining: number | null;
}

/** The portal's endpoints, built by the server for path or domain routing. */
export interface PortalLinks {
  signIn: string;
  signOut: string;
  forgotPassword: string;
  dashboard: string;
  branches: string;
  branchSwitch: string;
  /** GET ?q=: patients by name, phone or ID (each result carries its own `url`). */
  patientSearch: string;
  /** GET ?q=: the top bar's search, grouped, decided on the server (`ClinicSearch`). */
  search: string;
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
