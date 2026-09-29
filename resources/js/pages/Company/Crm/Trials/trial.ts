/**
 * The Trial detail contract.
 *
 * This mirrors `App\Services\CRM\TrialPageService::payload()` and nothing else
 * does. On the deal pages a component kept its own local copy of the payload
 * shape; it typechecked perfectly and rendered blank the day the server changed,
 * because a type the server does not produce is a guess with a compiler behind
 * it. The server is the contract — when it changes, this file changes with it.
 *
 * Nothing here describes patient, clinical or message content, and nothing
 * should. CRM is given aggregate counters; the shapes below are the whole of
 * what crosses that boundary.
 */

/** A trial's derived status. Never set by a form — computed from its dates. */
export type TrialStatusValue =
  | 'preparing'
  | 'active'
  | 'ending_soon'
  | 'extended'
  | 'in_grace'
  | 'completed'
  | 'quotation_requested'
  | 'cancelled'
  | 'expired';

/** Which phase of its life the tenant's subscription is in. */
export type WindowState = 'none' | 'trial' | 'grace' | 'paid' | 'lapsed';

/**
 * Live tenant activity, measured on demand and cached on the trial row.
 *
 * Distinct from {@link TrialUsage}, which is last night's snapshot and feeds
 * the trend chart. This is "what is happening now", and it is on the row so a
 * list of two hundred trials can show it without a query each.
 *
 * Null on a trial means not measured — render that as "not measured yet", never
 * as zeros. Zeros say the customer did nothing, which is a different and far
 * more damaging thing to tell a salesperson.
 */
export interface TrialTelemetry {
  patients: number;
  prescriptions: number;
  invoices: number;
  appointments: number;
  users_total: number;
  logins_7d: number;
  last_login_at: string | null;
  /** Derived server-side so every surface agrees what "active" means. */
  is_active_today: boolean;
  is_inactive: boolean;
  hours_since_login: number | null;
  measured_at: string;
}

export interface TrialLoss {
  at: string;
  /** A `LostReason` value — the same vocabulary the deal uses. */
  reason: string | null;
  competitor: string | null;
}

export interface TrialPlan {
  id: number;
  key: string | null;
  name: string;
}

export interface TrialLeadSummary {
  id: number;
  companyName: string | null;
  contactName: string | null;
  status: string | null;
}

export interface TrialOwner {
  id: number;
  name: string;
}

export interface TrialRecord {
  id: number;
  status: TrialStatusValue;
  statusLabel: string;
  statusColor: string;
  startDate: string | null;
  endDate: string | null;
  graceEndsAt: string | null;
  durationDays: number | null;
  extensionCount: number;
  originalEndDate: string | null;
  extensionReason: string | null;
  notes: string | null;
  outcome: string | null;
  feedback: string | null;
  cancelledAt: string | null;
  cancellationReason: string | null;
  /**
   * How the evaluation ended, when it ended badly.
   *
   * Separate from `status`: a lost trial is `completed` — the run finished —
   * and "finished" and "they said no" are not the same fact. Null while the
   * evaluation is still open, or if it ended any other way.
   */
  lost: TrialLoss | null;
  /** Live activity, cached on the row. See {@link TrialTelemetry}. */
  telemetry: TrialTelemetry | null;
  plan: TrialPlan | null;
  lead: TrialLeadSummary | null;
  owner: TrialOwner | null;
}

export interface EntitlementLimits {
  users: number | null;
  doctors: number | null;
  branches: number | null;
  storage_mb: number | null;
}

export interface TrialWindow {
  state: WindowState;
  daysRemaining: number | null;
  grantsAccess: boolean;
  limits: EntitlementLimits | null;
  modules: string[];
  /**
   * Always `null` today, and it means "unavailable", not "none".
   *
   * No plan, account or licence column defines a WhatsApp allowance, so a
   * quota would have to be invented. Render this as unavailable — never as 0,
   * and never as a denominator.
   */
  whatsappQuota: number | null;
}

export interface TrialHandover {
  delivered: boolean;
  channel: string | null;
  at: string | null;
}

export interface TrialProvisioning {
  isProvisioned: boolean;
  provisionedAt: string | null;
  /** Why the last attempt failed. Non-null means the retry action is the point. */
  error: string | null;
  clinic: { id: number; name: string; slug: string } | null;
  handover: TrialHandover | null;
}

export interface TrialUsageRecords {
  patients: number;
  appointments: number;
  appointments7d: number;
  encounters: number;
  prescriptions: number;
  invoices: number;
}

export interface TrialUsageStorage {
  bytes: number;
  limitMb: number | null;
  /** Null when there is no limit to compare against — not zero. */
  percent: number | null;
}

export interface TrialUsageModules {
  enabled: string[];
  used: string[];
  /** Granted and untouched. The most commercially useful field here. */
  unused: string[];
}

export interface TrialUsage {
  capturedAt: string | null;
  users: {
    total: number;
    active7d: number;
    logins7d: number;
    lastLoginAt: string | null;
  };
  records: TrialUsageRecords;
  branches: number;
  storage: TrialUsageStorage;
  modules: TrialUsageModules;
  onboarding: {
    readiness: number;
    checks: Record<string, boolean>;
  };
  whatsapp: {
    sent: number;
    quota: number | null;
  };
}

export interface TrialTrendPoint {
  capturedAt: string | null;
  activeUsers: number;
  logins: number;
  appointments: number;
  patients: number;
  readiness: number;
}

/**
 * What the server will let this user do.
 *
 * Every flag is a Gate check made server-side. Use these to decide what to
 * render; never re-derive them from a role or a status in the browser, because
 * a client-side permission is decoration and the server will refuse anyway.
 */
export interface TrialAbilities {
  update: boolean;
  provision: boolean;
  extend: boolean;
  cancel: boolean;
  complete: boolean;
  delete: boolean;
  /** Closing the deal. A deal-side permission, checked against the lead. */
  convert: boolean;
  /** Recording the loss. False once already lost — it happens once. */
  markLost: boolean;
  /** Re-measuring the tenant. Needs an environment to measure. */
  syncUsage: boolean;
}

export interface TrialPagePayload {
  trial: TrialRecord;
  window: TrialWindow;
  provisioning: TrialProvisioning;
  /**
   * Null until the nightly snapshot has run at least once.
   *
   * Render that absence as "not measured yet" rather than as zeros: zeros claim
   * the customer did nothing, which is a different and far more damaging thing
   * to tell a salesperson.
   */
  usage: TrialUsage | null;
  usageTrend: TrialTrendPoint[];
  history: TrialHistoryEntry[];
  can: TrialAbilities;
  /**
   * Commercial preconditions the convert action is still missing. Empty means
   * nothing is in the way. These are facts, not permissions — `can.convert`
   * answers the permission question.
   */
  convertBlockers: string[];
  /** The loss vocabulary, from the same enum the deal uses. */
  lostReasons: { value: string; label: string }[];
}

/* ── History ─────────────────────────────────────────────────────────────── */

export interface TrialHistoryEntry {
  id: number;
  type: string;
  description: string | null;
  at: string | null;
  by: string | null;
}

/* ── List ────────────────────────────────────────────────────────────────── */

/**
 * A row's usage summary.
 *
 * Null on the row when the nightly job has not measured this tenant yet. Render
 * that as "not measured", never as zeros — zeros say the customer did nothing.
 */
export interface TrialRowUsage {
  capturedAt: string | null;
  activeUsers: number;
  totalUsers: number;
  patients: number;
  appointments: number;
  lastLoginAt: string | null;
  readiness: number;
}

export interface TrialRow {
  id: number;
  company: string;
  leadId: number | null;
  phase: TrialStatusValue;
  phaseLabel: string;
  /** Ordering weight, lower is more urgent. Already applied server-side. */
  urgency: number;
  /**
   * The subscription window's own phase. After a deal is won this is `paid`,
   * and `daysRemaining` is then the paid term — not trial days.
   */
  windowState: WindowState;
  daysRemaining: number | null;
  endDate: string | null;
  graceEndsAt: string | null;
  grantsAccess: boolean;
  /** The catalogue package under evaluation, when one is linked. */
  package: TrialPlan | null;
  planName: string | null;
  owner: string | null;
  extensionCount: number;
  /** Set once the evaluation is lost. See {@link TrialLoss}. */
  lost: TrialLoss | null;
  /** Live activity, cached on the row. Null until first measured. */
  telemetry: TrialTelemetry | null;
  provisioning: {
    isProvisioned: boolean;
    error: string | null;
  };
  usage: TrialRowUsage | null;
  can: {
    extend: boolean;
    convert: boolean;
    /** False once already lost, so the action disappears rather than no-ops. */
    markLost: boolean;
  };
  /**
   * Why converting would be refused right now — no approved quotation settled
   * in full. Shown on the disabled action so nobody discovers it by pressing a
   * button and reading an error flash.
   */
  convertBlockers: string[];
}

export interface TrialListCounts {
  grace: number;
  endingSoon: number;
  running: number;
  preparing: number;
  failed: number;
  closed: number;
  /** Every lost trial is closed; this is how many we actually lost. */
  lost: number;
}

/**
 * The watchtower's four headline figures.
 *
 * A different cut from {@link TrialListCounts}, which describes lifecycle
 * phase. These describe engagement — the question a renewal actually turns on —
 * and they count only live trials, so a completed one is never "at risk".
 * Computed from the same rows the list renders, so a card and the rows beneath
 * it cannot disagree.
 */
export interface TrialHealthCounts {
  active: number;
  /** Three or more prescriptions printed. Not a login count — see the service. */
  healthy: number;
  at_risk: number;
  expiring_48h: number;
  /** Live trials nobody has measured yet. Never folded into `at_risk`. */
  unmeasured: number;
}

export interface TrialListPayload {
  rows: TrialRow[];
  counts: TrialListCounts;
  health: TrialHealthCounts;
  filters: {
    search: string;
    phase: TrialStatusValue | null;
  };
  /** The loss vocabulary, from the same enum the deal uses. */
  lostReasons: { value: string; label: string }[];
}
