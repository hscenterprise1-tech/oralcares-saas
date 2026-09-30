import React from 'react';
import {
  LayoutDashboard,
  Briefcase,
  Building2,
  TrendingUp,
  RefreshCw,
  CreditCard,
  Megaphone,
  FileText,
  BarChart3,
  CheckSquare,
  Bell,
  DollarSign,
  Plug,
  Settings,
  Zap,
  FileSpreadsheet,
  Users,
  ShieldCheck,
  Receipt,
  MessageSquare,
  Target,
  Send,
  Share2,
  Workflow,
  Activity,
  Banknote,
  Wallet,
  Award,
  Clock,
  CalendarCheck,
  LifeBuoy,
  ScrollText,
  GaugeCircle,
  Lock,
  UserPlus,
  LucideIcon,
} from 'lucide-react';
import { InstagramIcon } from '@/components/icons/InstagramIcon';

// ─────────────────────────────────────────────────────────────────────────────
// SIDEBAR TYPES & INTERFACES (CANONICAL OVS CONTRACT)
// ─────────────────────────────────────────────────────────────────────────────

export interface SidebarNavItem {
  id: string;
  label: string;
  href: string;
  icon?: LucideIcon | React.ElementType;
  badge?: string | number;
  badgeVariant?: 'neutral' | 'urgent' | 'live';
  permission?: string;
  /** Hide this item for master admins (e.g. personal "assigned to me" views that are only meaningful for individual reps). */
  hideForMasterAdmin?: boolean;
  children?: SidebarNavItem[];
}

export interface SidebarNavSection {
  id: string;
  title: string;
  icon?: LucideIcon | React.ElementType;
  permission?: string;
  items: SidebarNavItem[];
}

// ─────────────────────────────────────────────────────────────────────────────
// THE CANONICAL COMPANY REGISTRY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * One destination in the product.
 *
 * `hue`, `hint`, `keywords` and `group` are presentation hints each consumer
 * picks from: the rail paints with `hue`, the palette shows `hint` and searches
 * `keywords`, the pillar dropdown draws a divider when `group` changes.
 */
export interface ModuleNavItem extends SidebarNavItem {
  /** Rail colour key. Items without one are not offered to a rail. */
  hue?: string;
  /** Palette subtitle. */
  hint?: string;
  /** Extra palette search terms. */
  keywords?: string;
  /**
   * Sub-heading this item sits under inside its pillar's dropdown.
   *
   * Only Acquisition uses one today: Marketing is nine destinations that belong
   * to the same pillar as the CRM but are not the CRM, and a flat list of
   * fifteen reads as one undifferentiated wall. Items with no group render
   * first, ungrouped.
   */
  group?: string;
  /**
   * Kept out of a workspace rail while remaining in the pillar dropdown.
   *
   * The rail answers "what do I reach for constantly here", not "what exists" —
   * duplicating the whole dropdown into it is how a workspace ends up
   * presenting the same menu twice in two shapes.
   */
  rail?: false;
}

/**
 * A pillar: one entry in the header, one dropdown.
 *
 * Pillars answer "where does this live in the product". They are the reader's
 * map, and there are deliberately only five of them.
 */
export interface CompanyPillar {
  id: string;
  /** Header label. */
  label: string;
  /** Dropdown section heading. */
  title: string;
  icon: LucideIcon | React.ElementType;
  /**
   * Gate for the whole pillar. Omitted on every pillar below, and that is not
   * an oversight: a pillar now spans what used to be several modules — the
   * Acquisition dropdown holds both CRM and Marketing destinations — so a
   * single gate over it would hide one team's work behind another team's
   * permission. Every item carries the permission its own route enforces, and
   * a pillar whose items all filter out is dropped by the consumer.
   */
  permission?: string;
  items: ModuleNavItem[];
}

/**
 * A workspace: one floating rail, for the pages of one working area.
 *
 * Workspaces answer a different question from pillars — "what is near me while
 * I am working here" — and they are deliberately not the same shape. Revenue &
 * Subscriptions is one pillar holding eight destinations drawn from Finance and
 * Customer Success; a rail of eight is not scannable, and the person reconciling
 * invoices does not want renewals in their peripheral vision.
 *
 * So a rail names item ids and the items themselves are declared once, in the
 * pillars above. A destination therefore still has exactly one declaration: its
 * label, href, icon and permission cannot drift between the menu and the rail
 * because there is only one copy of them.
 */
export interface CompanyWorkspace {
  id: string;
  /** Item ids from COMPANY_MODULES, in rail order. Each must carry a `hue`. */
  rail: string[];
  /** What this workspace's "+ New" offers. */
  create?: SidebarNavItem[];
  /** Where the rail's last slot goes. */
  settingsHref?: string;
  /** Fallback gate for create actions that declare none. */
  permission?: string;
}

/**
 * Every Company/HQ destination, declared once, under five pillars.
 *
 * The header dropdown, each workspace's floating rail, the command palette and
 * the mobile sheet are all projections of this list. They used to be nine
 * hand-maintained copies, which had already drifted; consolidating them is what
 * the registry is for, and that property survives this reorganisation.
 *
 * Adding a destination is one edit here. There is no second place to update,
 * which is the only durable version of "the menus agree".
 *
 * Rules encoded below:
 *
 *   - A concept has exactly one navigation location.
 *   - Every href is a real page, never a redirect.
 *   - `permission` is the permission the route behind it actually enforces, so
 *     the menu can never offer a surface the server would refuse — and, just as
 *     importantly, never hides one the server would allow.
 *
 * Why five pillars rather than the previous seven modules: Subscriptions sat
 * under Customer Success, Invoices and Payments under Finance, and Packages
 * under Administration — three parts of one revenue story in three menus.
 * Pillar 02 is that story reassembled. Nothing moved on the server; every href
 * below is unchanged.
 */
export const COMPANY_MODULES: CompanyPillar[] = [
  // ───────────────────────────────────────────────────────────────────────────
  // 01 · OPERATIONS & MONITORING — what needs attention today
  // ───────────────────────────────────────────────────────────────────────────
  {
    id: 'operations',
    label: 'Operations',
    title: 'OPERATIONS & MONITORING',
    icon: GaugeCircle,
    items: [
      /*
       * Platform Overview was a standalone pill beside the module menu, which
       * made it the one destination with no stated home. It summarises this
       * pillar, so it opens it.
       */
      { id: 'ops-dashboard',     label: 'Platform Overview', href: '/company/dashboard',         icon: LayoutDashboard, permission: 'view executive dashboard', hue: '#565F73', hint: 'The platform at a glance',                       keywords: 'dashboard home executive summary' },
      /*
       * Customer Health, not "Customer Success". The page reads
       * `customer_success_snapshots` — health score, churn risk, renewal
       * probability — which is a monitoring surface. Managing the account
       * itself is Tenant Accounts, under Platform Governance.
       */
      { id: 'cs-overview',       label: 'Customer Health',   href: '/company/customer-success',  icon: Building2,       permission: 'view-organizations',       hue: '#2FA96B', hint: 'Health, churn risk and what the book needs',     keywords: 'dashboard summary at risk churn health score' },
      { id: 'ops-support',       label: 'Support',           href: '/company/support',           icon: LifeBuoy,        permission: 'view-support',             hue: '#EF5B39', hint: 'Customer tickets',                               keywords: 'ticket helpdesk issue sla' },
      /*
       * The platform's *sending* — what went out, what failed, what is queued.
       * Not the reader's inbox, which is a personal utility on the avatar.
       */
      { id: 'ops-notifications', label: 'Notifications',     href: '/company/notifications-ops', icon: Bell,            permission: 'view-notifications',       hue: '#E0B21E', hint: 'What the platform sent, and what failed',        keywords: 'delivery queue failed bounce sending health' },
      { id: 'ops-reports',       label: 'Reports',           href: '/company/reports',           icon: BarChart3,       permission: 'view-reports',             hue: '#2F6BE4', hint: 'Revenue, pipeline and customers over a window',  keywords: 'report export period' },
      /*
       * Integrations moved here from Administration on the evidence of the page
       * rather than its name: `IntegrationsController` is read-only and its own
       * docblock says it "reports whether what was connected is still working".
       * Connecting a number happens in Marketing settings. Health is monitoring.
       */
      { id: 'admin-integrations', label: 'Integrations',     href: '/company/integrations',      icon: Plug,            permission: 'view-integrations',        hue: '#3E93A3', hint: 'Connected systems and their health',             keywords: 'connection webhook api whatsapp instagram' },
    ],
  },

  // ───────────────────────────────────────────────────────────────────────────
  // 02 · REVENUE & SUBSCRIPTIONS — the money, in one place
  // ───────────────────────────────────────────────────────────────────────────
  {
    id: 'revenue',
    label: 'Revenue',
    title: 'REVENUE & SUBSCRIPTIONS',
    icon: Banknote,
    /*
     * No pillar gate, and the previous Finance module's comment explains why it
     * matters: its five entries all used to say `view-finance` while the routes
     * asked `view-revenue`, `view-invoices`, `view-payments` and
     * `view-expenses`, so a user holding only `view-finance` was shown five
     * entries and refused on four. Each entry names its own route's permission.
     */
    items: [
      { id: 'fin-overview',     label: 'Revenue Overview',    href: '/company/finance',                        icon: LayoutDashboard, permission: 'view-finance',       hue: '#2F6BE4', hint: 'The money, summarised',            keywords: 'dashboard home finance' },
      { id: 'fin-revenue',      label: 'Revenue',             href: '/company/finance/revenue',                icon: TrendingUp,      permission: 'view-revenue',       hue: '#2FA96B', hint: 'MRR, ARR and growth',              keywords: 'mrr arr growth' },
      /*
       * Subscriptions, Renewals and Usage arrive from Customer Success. They
       * are what a customer is paying for, what they will pay next, and what
       * they are consuming against it — the same subject as the invoices and
       * payments below, which is why they were hard to find one menu away.
       * Every route is unchanged.
       */
      { id: 'cs-subscriptions', label: 'Subscriptions',       href: '/company/customer-success/subscriptions', icon: CreditCard,      permission: 'view-subscriptions', hue: '#7C6FE0', hint: 'Plans, seats and billing state',   keywords: 'plan seats mrr billing entitlement' },
      { id: 'cs-renewals',      label: 'Renewals',            href: '/company/customer-success/renewals',      icon: RefreshCw,       permission: 'view-renewals',      hue: '#2AA79B', hint: 'What is coming up for renewal',    keywords: 'renew expiry churn' },
      { id: 'cs-usage',         label: 'Usage & Entitlements', href: '/company/customer-success/usage',        icon: GaugeCircle,     permission: 'view-usage',         hue: '#3E93A3', hint: 'What customers actually consume',  keywords: 'consumption limits entitlement adoption overage' },
      /*
       * Packages arrives from Administration. What may be sold belongs beside
       * what is being sold; filed under platform configuration it read as a
       * settings screen rather than a pricing one.
       */
      { id: 'admin-packages',   label: 'Plans & Packages',    href: '/company/admin/packages',                 icon: Award,           permission: 'view-plans',         hue: '#E0B21E', hint: 'What may be sold',                 keywords: 'plan catalogue pricing tier package' },
      { id: 'fin-invoices',     label: 'Invoices',            href: '/company/finance/invoices',               icon: FileText,        permission: 'view-invoices',      hue: '#2F6BE4', hint: 'What has been billed',             keywords: 'bill billing' },
      { id: 'fin-payments',     label: 'Payments',            href: '/company/finance/payments',               icon: DollarSign,      permission: 'view-payments',      hue: '#C25A8A', hint: 'What has come in',                 keywords: 'collection settlement received' },
      { id: 'fin-expenses',     label: 'Expenses',            href: '/company/finance/expenses',               icon: Receipt,         permission: 'view-expenses',      hue: '#F59245', hint: 'What has gone out',                keywords: 'cost spend' },
    ],
  },

  // ───────────────────────────────────────────────────────────────────────────
  // 03 · ACQUISITION & CRM — before they are a customer
  // ───────────────────────────────────────────────────────────────────────────
  {
    id: 'acquisition',
    label: 'Acquisition',
    title: 'ACQUISITION & CRM',
    icon: Briefcase,
    /*
     * No pillar gate. This is the one place it would actively break something:
     * the CRM's five destinations ask `view-crm` / `view-leads` and Marketing's
     * ask `view-marketing`, and a gate over the pillar would hide one team's
     * work from the other. The `group` field separates them visually instead.
     */
    items: [
      /*
       * The CRM's five destinations are locked — same ids, same order, same
       * hues as before this reorganisation, so generalising the pillar pattern
       * could not disturb the one area already settled.
       */
      { id: 'crm-overview',       label: 'CRM Overview',   href: '/company/crm/overview',            icon: LayoutDashboard, permission: 'view-crm',        hue: '#2F6BE4', hint: 'Sales performance',                     keywords: 'dashboard home crm' },
      { id: 'deals',              label: 'Deals',          href: '/company/crm/deals',               icon: Briefcase,       permission: 'view-leads',      hue: '#F59245', hint: 'Every opportunity, by stage',           keywords: 'pipeline stages leads prospects clinics lost triage winback' },
      { id: 'trials',             label: 'Trials',         href: '/company/crm/trials',              icon: Zap,             permission: 'view-trials',     hue: '#E0B21E', hint: 'Evaluations',                           keywords: 'evaluation poc' },
      { id: 'commercials',        label: 'Commercials',    href: '/company/crm/quotations',          icon: FileSpreadsheet, permission: 'view-quotations', hue: '#7C6FE0', hint: 'Quotations, payments and invoices',     keywords: 'quotes pricing proposal invoice payment settlement' },
      { id: 'my-work',            label: 'My Work',        href: '/company/crm/my-workspace',        icon: CheckSquare,     permission: 'view-leads',      hue: '#2AA79B', hint: 'Your day, tasks, demos and follow-ups', keywords: 'my day focus queue tasks todo followup demos' },

      /*
       * Marketing, as a labelled group inside the same pillar.
       *
       * Ten destinations, in the order work flows: the database, the targeting
       * drawn from it, what gets sent, what comes back, what runs on its own,
       * then the reporting and the configuration.
       *
       * Marketing Overview is kept even though the consolidation brief lists
       * nine: `/company/marketing/overview` is a real, gated, working page and
       * it is the `mktg` rail's first slot. Dropping the entry would orphan it
       * — which is precisely the class of defect this change exists to remove.
       *
       * WhatsApp and Templates live inside Marketing Settings, and Suppression
       * inside Contacts as Compliance — occasional configuration and compliance
       * work reached by tab rather than daily destinations.
       *
       * "Segments" is a label only: the route, model and
       * `marketing_campaigns.audience_id` all still say audience.
       */
      { id: 'mktg-overview',      label: 'Overview',       href: '/company/marketing/overview',      icon: Megaphone,     permission: 'view-marketing', group: 'Marketing', hue: '#565F73', hint: 'Reach, sends and replies at a glance', keywords: 'dashboard home marketing' },
      { id: 'mktg-contacts',      label: 'Contacts',       href: '/company/marketing/contacts',      icon: Users,         permission: 'view-marketing', group: 'Marketing', hue: '#2F6BE4', hint: 'The marketing contact database',       keywords: 'people database import compliance suppression' },
      { id: 'mktg-conversations', label: 'Conversations',  href: '/company/marketing/conversations', icon: MessageSquare, permission: 'view-marketing', group: 'Marketing', hue: '#2FA96B', hint: 'The WhatsApp inbox',                   keywords: 'whatsapp inbox reply chat' },
      { id: 'mktg-audiences',     label: 'Segments',       href: '/company/marketing/audiences',     icon: Target,        permission: 'view-marketing', group: 'Marketing', hue: '#E0B21E', hint: 'Who a campaign will reach',            keywords: 'audience targeting filter' },
      { id: 'mktg-campaigns',     label: 'Campaigns',      href: '/company/marketing/campaigns',     icon: Send,          permission: 'view-marketing', group: 'Marketing', hue: '#F59245', hint: 'What gets sent',                       keywords: 'blast broadcast send' },
      { id: 'mktg-posts',         label: 'Instagram',      href: '/company/marketing/instagram',     icon: Share2,        permission: 'view-marketing', group: 'Marketing', hue: '#EF5B39', hint: 'Scheduled and published posts',        keywords: 'social post reel instagram' },
      { id: 'mktg-automations',   label: 'Automations',    href: '/company/marketing/automations',   icon: Workflow,      permission: 'view-marketing', group: 'Marketing', hue: '#7C6FE0', hint: 'Rules that run on their own',          keywords: 'journey drip trigger' },
      /*
       * Kept out of the rail, in the dropdown: a run log is somewhere you go
       * when an automation misbehaves, not several times a day.
       */
      { id: 'mktg-execution-logs', label: 'Execution Logs', href: '/company/marketing/executions',   icon: Activity,      permission: 'view-marketing', group: 'Marketing', hint: 'Every automation run', keywords: 'runs history debug failures', rail: false },
      { id: 'mktg-analytics',     label: 'Analytics',      href: '/company/marketing/analytics',     icon: BarChart3,     permission: 'view-marketing', group: 'Marketing', hue: '#3E93A3', hint: 'How the sending performed',            keywords: 'report open click attribution' },
      /* Settings is the marketing rail's own last slot, below the divider. */
      { id: 'mktg-settings',      label: 'Marketing Settings', href: '/company/marketing/settings',  icon: Settings,      permission: 'view-marketing', group: 'Marketing', hint: 'Sender, pacing, WhatsApp and templates', keywords: 'configuration whatsapp templates quiet hours', rail: false },
    ],
  },

  // ───────────────────────────────────────────────────────────────────────────
  // 04 · PEOPLE & HR — unchanged, and correct
  // ───────────────────────────────────────────────────────────────────────────
  {
    id: 'people',
    label: 'People',
    title: 'PEOPLE & HR',
    icon: Users,
    /*
     * No pillar gate: Attendance and Leave are open to anybody with an employee
     * record — a person is entitled to their own day and their own leave
     * without a company-wide read grant — and their controllers narrow every
     * query through `PeopleScope::restrict()`.
     */
    items: [
      { id: 'people-employees',  label: 'Employees',     href: '/company/people/employees',  icon: Users,         permission: 'view-employees',  hue: '#2F6BE4', hint: 'The people who work here',        keywords: 'staff headcount directory' },
      { id: 'people-structure',  label: 'Org Structure', href: '/company/people/structure',  icon: Building2,     permission: 'employees.view',  hue: '#7C6FE0', hint: 'Departments and reporting lines', keywords: 'department team hierarchy' },
      /*
       * Attendance and Leave carry no `permission`, and that is the fix for a
       * real defect rather than a relaxation.
       *
       * Neither route has a `can:` guard or a `Gate::authorize`. Both
       * controllers call `PeopleScope::restrict($query, $user, 'view-attendance')`
       * / `'view-leave'`, which narrows the rows so an ordinary employee sees
       * their own day and their own leave and a manager sees their team's. The
       * server was already correct.
       *
       * The menu was not: declaring `view-attendance` here made
       * `useNavAuthorization` hide the entry from every employee who lacks the
       * company-wide grant — that is, from exactly the people the page exists
       * to serve, while the route would have served them. A menu stricter than
       * the server it fronts is the same bug as one looser than it, pointed the
       * other way.
       *
       * Hiding an entry has never been what protects the page behind it, and
       * here there is nothing to protect: the row scoping does that work.
       */
      { id: 'people-attendance', label: 'Attendance',    href: '/company/people/attendance', icon: Clock,         hue: '#3E93A3', hint: 'Who is in today',                keywords: 'present absent shift clock my attendance' },
      { id: 'people-leave',      label: 'Leave',         href: '/company/people/leave',      icon: CalendarCheck, hue: '#2AA79B', hint: 'Requests, balances and types',   keywords: 'holiday absence pto my leave' },
      { id: 'people-payroll',    label: 'Payroll',       href: '/company/people/payroll',    icon: Wallet,        permission: 'view-payroll',    hue: '#E0894A', hint: 'Periods, entries and payslips',  keywords: 'salary pay period payslip' },
      { id: 'people-incentives', label: 'Incentives',    href: '/company/people/incentives', icon: Award,         permission: 'view-incentives', hue: '#C25A8A', hint: 'Commission and bonuses',         keywords: 'commission bonus reward' },
    ],
  },

  // ───────────────────────────────────────────────────────────────────────────
  // 05 · PLATFORM GOVERNANCE — configuration and oversight
  // ───────────────────────────────────────────────────────────────────────────
  {
    id: 'governance',
    label: 'Governance',
    title: 'PLATFORM GOVERNANCE',
    icon: ShieldCheck,
    items: [
      /*
       * Tenant Accounts and Lifecycle arrive from Customer Success. Creating a
       * tenant, shaping its clinics and branches, and taking it live is
       * governance of the platform's own estate; how healthy that tenant is
       * afterwards is monitoring, and stays in pillar 01 as Customer Health.
       * Both routes are unchanged.
       */
      { id: 'cs-accounts',       label: 'Tenant Accounts',        href: '/company/customer-success/accounts',  icon: Building2,   permission: 'view-organizations', hue: '#2F6BE4', hint: 'Every customer organisation',         keywords: 'organisation organization clinic customer account 360 tenant' },
      { id: 'cs-lifecycle',      label: 'Lifecycle & Provisioning', href: '/company/customer-success/lifecycle', icon: Workflow,  permission: 'view-provisioning',  hue: '#F59245', hint: 'Provisioning and go-live',            keywords: 'provisioning onboarding go-live handover' },
      { id: 'admin-roles',       label: 'Roles & Permissions',    href: '/company/people/roles',               icon: Lock,        permission: 'roles.manage',       hue: '#2FA96B', hint: 'What each role may do',               keywords: 'role permission grant rbac' },
      { id: 'admin-security',    label: 'Security',               href: '/company/security',                   icon: ShieldCheck, permission: 'view-audit-logs',    hue: '#EF5B39', hint: 'Route guards, sign-ins and sessions', keywords: 'access guard login session policy' },
      /*
       * Audit is here rather than folded into Security, and that is a decision
       * from reading both pages rather than from their names. Security answers
       * "who can get in and what may they reach"; Audit answers "who did what",
       * unioning `activities` with `company_employee_activities`. Security has
       * no section for that, so merging the entry would have hidden a working
       * page behind a tab that does not exist.
       */
      { id: 'admin-audit',       label: 'Audit',                  href: '/company/audit',                      icon: ScrollText,  permission: 'view-audit-logs',    hue: '#7C6FE0', hint: 'Who did what, across the platform',   keywords: 'history activity trail log' },
      { id: 'admin-sales-teams', label: 'Sales Teams',            href: '/company/crm/teams',                  icon: Users,       permission: 'view-crm',           hue: '#565F73', hint: 'Assignment and territories',          keywords: 'team rep round robin territory' },
      { id: 'admin-workflows',   label: 'Workflows',              href: '/company/workflows',                  icon: Workflow,    permission: 'view-platform',      hue: '#3E93A3', hint: 'The platform automation engine',      keywords: 'rule transition engine' },
      /*
       * The one canonical Settings destination for Company/HQ.
       *
       * Every module's rail used to point its own "Settings" icon at whatever
       * page was nearest — Finance's went to its own overview, People's to the
       * employee list, CRM's to `/profile`. None of them were settings. This is
       * the real one: a directory that either shows a module's actual
       * configuration or deep-links to the existing page that already owns it
       * (Marketing Settings, Roles & Permissions, Leave Types, ...). It renders
       * no privileged data of its own, so it carries no `permission` here — each
       * link inside it enforces the same permission its target route always
       * has. Dropdown-only: every workspace's rail already ends in a Settings
       * icon via `settingsHref`, so railing this too would draw it twice.
       */
      { id: 'admin-settings',    label: 'Settings',               href: '/company/administration/settings',    icon: Settings,    hint: 'One place to configure every module',  keywords: 'settings configuration preferences administration', rail: false },
    ],
  },
];

// ─────────────────────────────────────────────────────────────────────────────
// WORKSPACES — the floating rails, drawn from the pillars above
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Each page shell's rail, by workspace id.
 *
 * These ids are the contract the module shells call with — `moduleRail('crm')`,
 * `moduleRail('people')` and so on — and they stay exactly as they were, so no
 * shell changed in this reorganisation.
 *
 * A rail lists ids, never destinations. Every label, href, icon and permission
 * lives once, in COMPANY_MODULES; `moduleRail()` fails loudly on an id that is
 * not declared there, so a rail cannot outlive the destination it points at.
 */
export const COMPANY_WORKSPACES: CompanyWorkspace[] = [
  {
    id: 'crm',
    rail: ['crm-overview', 'deals', 'trials', 'commercials', 'my-work'],
    create: [
      { id: 'new-lead', label: 'Lead', href: '/company/crm/leads/create', icon: Briefcase, permission: 'create leads' },
    ],
    settingsHref: '/company/administration/settings?tab=crm',
    permission: 'view-crm',
  },
  {
    id: 'marketing',
    rail: [
      'mktg-overview', 'mktg-contacts', 'mktg-conversations', 'mktg-audiences',
      'mktg-campaigns', 'mktg-posts', 'mktg-automations', 'mktg-analytics',
    ],
    create: [
      { id: 'mktg-new-contact',    label: 'Contact',    href: '/company/marketing/contacts?create=1',  icon: UserPlus, permission: 'view-marketing' },
      { id: 'mktg-new-segment',    label: 'Segment',    href: '/company/marketing/audiences/create',   icon: Target,   permission: 'view-marketing' },
      { id: 'mktg-new-automation', label: 'Automation', href: '/company/marketing/automations/create', icon: Zap,      permission: 'view-marketing' },
    ],
    settingsHref: '/company/administration/settings?tab=marketing',
    permission: 'view-marketing',
  },
  {
    id: 'finance',
    rail: ['fin-overview', 'fin-revenue', 'fin-invoices', 'fin-payments', 'fin-expenses'],
    settingsHref: '/company/administration/settings?tab=revenue',
  },
  {
    id: 'people',
    rail: ['people-employees', 'people-structure', 'people-attendance', 'people-leave', 'people-payroll', 'people-incentives'],
    settingsHref: '/company/administration/settings?tab=people',
  },
  {
    id: 'operations',
    rail: ['ops-support', 'ops-notifications', 'ops-reports'],
    settingsHref: '/company/administration/settings?tab=operations',
  },
  {
    id: 'customer-success',
    rail: ['cs-overview', 'cs-accounts', 'cs-subscriptions', 'cs-usage', 'cs-renewals', 'cs-lifecycle'],
    settingsHref: '/company/administration/settings',
  },
  {
    id: 'administration',
    rail: ['admin-security', 'admin-roles', 'admin-audit', 'admin-sales-teams', 'admin-packages', 'admin-integrations', 'admin-workflows'],
    settingsHref: '/company/administration/settings',
  },
];

// ─────────────────────────────────────────────────────────────────────────────
// PROJECTIONS — every consumer reads the registry through one of these
// ─────────────────────────────────────────────────────────────────────────────

/** Every destination in the product, flattened, keyed by id. */
const ITEM_INDEX: Record<string, ModuleNavItem> = COMPANY_MODULES.reduce(
  (index, pillar) => {
    for (const item of pillar.items) {
      index[item.id] = item;
    }

    return index;
  },
  {} as Record<string, ModuleNavItem>
);

/** The pillar a destination belongs to, by id. */
export function companyPillar(id: string): CompanyPillar {
  const found = COMPANY_MODULES.find((pillar) => pillar.id === id);

  if (! found) {
    throw new Error(`Unknown company pillar "${id}" — it must be declared in COMPANY_MODULES.`);
  }

  return found;
}

/** One workspace's rail definition, by id. */
export function companyWorkspace(id: string): CompanyWorkspace {
  const found = COMPANY_WORKSPACES.find((workspace) => workspace.id === id);

  if (! found) {
    throw new Error(`Unknown company workspace "${id}" — it must be declared in COMPANY_WORKSPACES.`);
  }

  return found;
}

/**
 * The destinations a workspace puts on its floating rail.
 *
 * Resolved from ids, so a rail entry whose destination has been renamed or
 * removed throws at module load rather than rendering a link to nowhere.
 * Anything marked `rail: false`, and anything with no hue, is refused: the rail
 * is deliberately allowed to be shorter than the pillar it draws from, and is
 * never allowed to contain something the pillar does not.
 */
export function moduleRail(id: string): Array<{ icon: LucideIcon; label: string; href: string; hue: string }> {
  return companyWorkspace(id).rail.map((itemId) => {
    const item = ITEM_INDEX[itemId];

    if (! item) {
      throw new Error(`Workspace "${id}" rails to unknown destination "${itemId}" — declare it in COMPANY_MODULES.`);
    }

    if (item.rail === false || ! item.hue) {
      throw new Error(`Destination "${itemId}" is not railable — it needs a hue and must not set \`rail: false\`.`);
    }

    return {
      icon: item.icon as LucideIcon,
      label: item.label,
      href: item.href,
      hue: item.hue,
    };
  });
}

/** A workspace's "+ New" entries, or none. */
export function moduleCreate(id: string): Array<{ label: string; href: string; icon: LucideIcon; permission: string }> {
  const workspace = companyWorkspace(id);

  return (workspace.create ?? []).map((item) => ({
    label: item.label,
    href: item.href,
    icon: item.icon as LucideIcon,
    /*
     * A create action with no permission would be offered to everybody and
     * then refused by the route, so an undeclared one is treated as the
     * workspace's own gate rather than as "anyone".
     */
    permission: item.permission ?? workspace.permission ?? '',
  }));
}

/** Where a workspace's rail sends its last slot. */
export function moduleSettingsHref(id: string): string {
  return companyWorkspace(id).settingsHref ?? '/profile';
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. EXECUTIVE / COMPANY NAVIGATION CONFIG (derived)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The header pillars and the mobile sheet — the registry, projected.
 *
 * The name is kept because four consumers read it and none of them care that
 * a section is now a pillar rather than a module.
 */
export const EXECUTIVE_NAV_GROUPS: SidebarNavSection[] = COMPANY_MODULES.map((pillar) => ({
  id: pillar.id,
  title: pillar.title,
  icon: pillar.icon,
  permission: pillar.permission,
  items: pillar.items,
}));

/**
 * Platform Overview, still exported under its old name.
 *
 * It is no longer a standalone header pill — it opens the Operations &
 * Monitoring pillar, which is what it summarises. The legacy sidebar still
 * asks for a top item, so it is derived from the pillar rather than declared
 * a second time: the two cannot disagree about where Dashboard points.
 */
export const EXECUTIVE_TOP_LEVEL_DASHBOARD: SidebarNavItem = companyPillar('operations').items[0];

/**
 * The CRM's destinations, kept as a named export.
 *
 * CrmShell and the command palette were built against this, and the CRM's own
 * navigation is settled — pointing the name at the registry keeps that
 * behaviour identical while removing the second list.
 */
export const CRM_DESTINATIONS = companyWorkspace('crm').rail.map((id) => ITEM_INDEX[id]) as Array<
  ModuleNavItem & { hue: string; hint: string; keywords?: string }
>;

/** What the CRM's "+ New" button offers. */
export const CRM_CREATE_ACTIONS: SidebarNavItem[] = companyWorkspace('crm').create ?? [];

// ─────────────────────────────────────────────────────────────────────────────
// 2. ENTERPRISE / CLINIC PORTAL NAVIGATION CONFIG
// ─────────────────────────────────────────────────────────────────────────────

export const ENTERPRISE_NAV_GROUPS: SidebarNavSection[] = [
  {
    id: 'general',
    title: 'General',
    items: [
      { id: 'dashboard', label: 'Dashboard', href: '/company/dashboard', icon: LayoutDashboard },
    ],
  },
  /*
   * The CRM group that used to sit here is gone.
   *
   * It duplicated Deals, Tasks and Accounts — destinations EXECUTIVE_NAV_GROUPS
   * and the CRM command palette already own — and gated them on `view_leads`,
   * `view_tasks` and `view_organizations`, three permission names that exist on
   * no role in this application. Every one of those items was therefore
   * invisible to every user for as long as it sat in the menu. Removing it
   * changes nothing a reader could see and leaves one CRM map instead of two.
   */
  /*
   * Finance, Development and System used to follow.
   *
   * Finance repeated three destinations EXECUTIVE_NAV_GROUPS already carries
   * with working permissions. Development and System pointed at
   * `/company/dev/api-keys`, `/company/dev/webhooks`, `/company/settings` and
   * `/company/team` — four paths with no route, controller or page behind them.
   * All seven items were gated on permission names this application does not
   * define, so none of them has ever been visible to anybody.
   */
];
