/*
 * The deal page's contract, zone by zone.
 *
 * These mirror `DealPageService` field for field. The previous types file
 * described shapes the server had stopped sending — `plan_name` for `plan`,
 * `due_date` for `due_at`, a `usage_score` nobody computed — so components
 * written against them typechecked and rendered blank. The server is the
 * contract; this file follows it.
 */

/** A plan a quotation or a trial can be raised against. */
export interface DealPackage {
  id: number;
  name: string;
  price: number;
  billing_period: string;
  is_trial_available: boolean;
  trial_duration_days: number;
  max_doctors: number;
  max_users: number;
  max_branches: number;
}

export interface DealCan {
  update: boolean;
  convert: boolean;
  approve_quotation: boolean;
  approve_trial_extension: boolean;
  delete: boolean;
}

/**
 * A specialty, resolved through `healthcare_specialty_masters`.
 *
 * `tone` is the category's colour role, decided server-side, so the deal page
 * and the Deals list cannot colour the same specialty differently.
 */
export interface DealSpecialty {
  key: string;
  name: string;
  category: string | null;
  category_label: string | null;
  tone: string;
}

/**
 * The facility archetype and its capacity.
 *
 * `archetype` is composed server-side because which figure leads depends on the
 * facility type — beds for a hospital, doctors for a polyclinic, sites for a
 * group — and that decision belongs in one place.
 */
export interface DealFacility {
  type: string | null;
  label: string;
  site_count: number;
  is_multi_site: boolean;
  doctors: number | null;
  beds: number | null;
  theatres: number | null;
  stations: number | null;
  archetype: string;
}

/** One site in the estate. Capacity figures are null when never recorded. */
export interface DealBranchSummary {
  id: number;
  name: string;
  is_main: boolean;
  city: string | null;
  area: string | null;
  postal_code: string | null;
  location: string | null;
  doctor_count: number | null;
  bed_count: number | null;
  operation_theatre_count: number | null;
  treatment_station_count: number | null;
  contact_name: string | null;
  phone: string | null;
}

/** The command bar. Arrives with the document. */
export interface Deal {
  id: number;
  company_name: string;
  contact_name: string;
  email: string | null;
  phone: string | null;
  status: string;
  status_label: string;
  priority: string | null;
  source: string | null;
  source_label: string | null;
  city: string | null;
  state: string | null;
  postal_code: string | null;
  area: string | null;
  /** Every specialty this practice runs, catalogued and category-coloured. */
  specialties: DealSpecialty[];
  /** Solo practice, polyclinic, hospital — and how big. Null if unrecorded. */
  facility: DealFacility | null;
  /** The whole estate, for the footprint panel. */
  branches: DealBranchSummary[];
  expected_mrr: number;
  expected_arr: number;
  probability_to_close: number;
  lead_score: number;
  weighted_value: number;
  assigned_user: { id: number; name: string; email: string } | null;
  assigned_team: { id: number; name: string } | null;
  selected_plan: { id: number; name: string; price: number; billing_period: string } | null;
  stage_entered_at: string | null;
  days_in_stage: number | null;
  created_at: string | null;
  lost_reason: string | null;
  lost_competitor: string | null;
  lost_at: string | null;
  winback_scheduled_at: string | null;
  customer_organization_id: number | null;
}

/**
 * One edge of the pipeline, as the server sees it.
 *
 * `blocked` and `!allowed` are different states and the stepper renders them
 * differently. Blocked means the edge does not exist — a won deal cannot go
 * anywhere, and no action will change that, so `action` is null. Not-allowed
 * means the evidence is missing, and `action` names the form that would supply
 * it, so a click can open that form instead of firing a request destined for a
 * 422.
 */
export interface StageTransition {
  value: string;
  label: string;
  is_current: boolean;
  allowed: boolean;
  /** The pipeline refuses this edge outright. No action can unblock it. */
  blocked: boolean;
  requirement: string | null;
  /** `log_contact`, `schedule_demo`, `start_trial`, `provide_lost_reason`, … */
  action: string | null;
}

export interface DealTask {
  id: number;
  title: string;
  description: string | null;
  type: string | null;
  priority: string;
  due_at: string | null;
  is_overdue: boolean;
  is_completed: boolean;
  assigned_to: string | null;
}

/** Z1 — what moves this deal next. */
export interface DealNow {
  next_action: {
    has_action: boolean;
    task_id?: number;
    title?: string;
    type?: string;
    due_at?: string | null;
    due_label?: string | null;
    is_overdue?: boolean;
    owner?: string | null;
    recommendation: string | null;
  };
  stage_control: {
    current: string;
    current_label: string | null;
    suggested: string | null;
    transitions: StageTransition[];
    lost_reasons: { value: string; label: string }[];
  };
  tasks: DealTask[];
}

export interface LifecycleStep {
  key: 'quotation' | 'approved' | 'payment' | 'invoice' | 'won';
  label: string;
  done: boolean;
  detail: string | null;
  hint: string;
}

export interface DealQuotation {
  id: number;
  quotation_number: string;
  version: number;
  status: string;
  /** Whether this is the one version of this quote still being worked. */
  is_active: boolean;
  plan_name: string | null;
  subtotal: number;
  discount: number;
  grand_total: number;
  /** `monthly | quarterly | half_yearly | annual`. */
  billing_cycle: string;
  /** `1M | 3M | 6M | 1Y`. */
  billing_cycle_label: string;
  /** `grand_total` annualised, comparable across quotations quoted at different tenures. */
  normalized_arr: number;
  valid_until: string | null;
  sent_at: string | null;
  approved_at: string | null;
  rejection_reason: string | null;
  created_at: string | null;
  prepared_by_name: string | null;
  is_settled: boolean;
  invoice_number: string | null;
}

export interface DealInvoice {
  id: number;
  invoice_number: string;
  status: string;
  final_amount: number;
  issued_at: string | null;
  paid_at: string | null;
}

export interface DealPayment {
  id: number;
  amount: number;
  currency: string | null;
  status: string;
  gateway: string | null;
  transaction_id: string | null;
  recorded_at: string | null;
}

/** Z2 — what it is worth, and how close it is to closing. */
export interface DealCommercial {
  lifecycle: {
    steps: LifecycleStep[];
    settled_quotation_id: number | null;
    invoice_number: string | null;
    can_close: boolean;
  };
  conversion: {
    is_converted: boolean;
    customer_organization_id: number | null;
    can_convert: boolean;
    is_relevant: boolean;
    requirements: { key: string; label: string; met: boolean; hint: string }[];
  };
  quotations: DealQuotation[];
  invoices: DealInvoice[];
  payments: DealPayment[];
  packages: DealPackage[];
}

export interface DealDemo {
  id: number;
  scheduled_at: string | null;
  /** The raw instant, for a countdown — `scheduled_at` is formatted for reading, not arithmetic. */
  scheduled_at_iso: string | null;
  status: string;
  /*
   * `demo_mode`, not `demo_method`. The server never sent a `demo_method` —
   * there is no such column — so this field was always the default and every
   * demo on the page read as online.
   */
  demo_mode: string;
  meeting_platform: string | null;
  meeting_platform_label: string | null;
  meeting_link: string | null;
  meeting_id: string | null;
  passcode: string | null;
  location: string | null;
  /** What the customer has actually been told, and when. */
  reminders: {
    rep_notified: boolean;
    customer_24h: boolean;
    customer_2h: boolean;
  };
  presenter_name: string | null;
  notes: string | null;
  outcome: string | null;
}

export interface DealTrial {
  id: number;
  status: string;
  /** From `company_plans`, never the free text on the trial. */
  package: { id: number; key: string | null; name: string } | null;
  /** Live usage. Null means not measured — never render it as zeros. */
  telemetry: {
    patients: number;
    prescriptions: number;
    invoices: number;
    is_active_today: boolean;
    is_inactive: boolean;
    hours_since_login: number | null;
  } | null;
  extension_count: number;
  lost: { at: string; reason: string | null; competitor: string | null } | null;
  plan: string | null;
  start_date: string | null;
  end_date: string | null;
  days_remaining: number | null;
  is_ending_soon: boolean;
  notes: string | null;
  /** The provisioned tenant, once `CustomerProvisioningEngine` has built one. */
  provisioning: {
    clinic_slug: string;
    /** Display only — a database-unique slug, not a claim that DNS resolves it. */
    tenant_url: string;
    admin_email: string | null;
    credentials_sent: boolean;
    provisioned_at: string | null;
  } | null;
  /** At most one — a second ask before the first is decided is a duplicate. */
  pending_extension_request: {
    id: number;
    additional_days: number;
    reason: string;
    requested_by: string | null;
  } | null;
}

/** Z3 — demos and trials, as one evaluation record. */
export interface DealEvaluation {
  demos: DealDemo[];
  trials: DealTrial[];
}

export interface WhatsAppMessage {
  id: number;
  direction: string;
  body: string;
  status: string;
  at: string | null;
  at_label: string;
}

export interface DealCall {
  id: number;
  duration_seconds: number;
  disposition: string | null;
  notes: string | null;
  user_name: string | null;
  at: string | null;
  at_label: string | null;
}

/**
 * One send attempt, exactly as `CrmEmailService::history()` shapes it — this
 * type previously described a payload the server never actually sent
 * (`body`/`to`/`error`/`created_at`), which is why the channel had no UI at
 * all rather than a broken one.
 */
export interface EmailThread {
  id: number;
  direction: 'outbound' | 'inbound';
  status: 'queued' | 'sent' | 'failed';
  subject: string;
  body_html: string | null;
  to_email: string | null;
  sent_by: string | null;
  sent_at: string | null;
  failed_at: string | null;
  failure_reason: string | null;
  attempts: number;
  is_retryable: boolean;
  occurred_at: string | null;
  time: string | null;
}

/** Z4 — everything that was said. */
export interface DealConversation {
  whatsapp: {
    conversation_id: number | null;
    phone: string | null;
    unread: number;
    inbox_url: string | null;
    messages: WhatsAppMessage[];
  };
  email: {
    can_send: boolean;
    reason?: string | null;
    default_to: string | null;
    threads: EmailThread[];
  };
  calls: DealCall[];
}

export interface HistoryEvent {
  id: number;
  type: string;
  type_label: string;
  description: string;
  actor: string;
  at: string | null;
  at_label: string | null;
  properties: Record<string, unknown> | null;
}

/** Z5 — the whole history. */
export interface DealHistory {
  events: HistoryEvent[];
  total: number;
  page: number;
  per_page: number;
  has_more: boolean;
  kinds: { value: string; label: string }[];
}

export interface DealBranch {
  id?: number;
  name: string;
  is_main: boolean;
  address: string | null;
  area: string | null;
  city: string | null;
  state: string | null;
  country: string | null;
  postal_code: string | null;
  contact_name: string | null;
  phone: string | null;
  email: string | null;
}

export interface DealContactRow {
  id: number;
  first_name: string;
  last_name: string | null;
  name: string;
  email: string | null;
  phone: string | null;
  whatsapp: string | null;
  job_title: string | null;
  decision_role: string | null;
  is_primary: boolean;
}

/** Z6 — everything the create form captured. */
export interface DealProfileData {
  organisation: {
    company_name: string;
    organization_type: string | null;
    healthcare_specialties: string[];
    branch_count: number | null;
    organization_size: string | null;
  };
  location: {
    address: string | null;
    area: string | null;
    city: string | null;
    state: string | null;
    country: string | null;
    postal_code: string | null;
    has_complete_address: boolean;
  };
  branches: DealBranch[];
  contacts: DealContactRow[];
  qualification: {
    current_software: string | null;
    migration_required: boolean;
    competitors: string | null;
    deployment_preference: string | null;
    expected_go_live_date: string | null;
    expected_users: number | null;
    required_modules: string[];
    required_integrations: string[];
    product_interest: string[];
  };
  commercial: {
    assigned_user_id: number | null;
    assigned_team_id: number | null;
    source: string | null;
    selected_plan_id: number | null;
    expected_mrr: number | null;
    expected_arr: number | null;
    probability_to_close: number | null;
    priority: string | null;
    notes: string | null;
  };
  attribution: {
    marketing_contact: { id: number; name: string; phone: string } | null;
    marketing_campaign: { id: number; name: string } | null;
    created_at: string | null;
    qualified_at: string | null;
  };
}

export interface DealFile {
  id: number;
  name: string;
  size: number | null;
  uploaded_at: string | null;
  uploaded_by?: string | null;
  url?: string;
  download_url?: string;
}

export interface ProfileOptions {
  lead_sources: Record<string, string>;
  organization_types: Record<string, string>;
  specialties: { value: string; label: string; requires_more: boolean }[];
  assignable_users: { id: number; name: string; email: string }[];
  assignable_teams: { id: number; name: string }[];
  packages: { id: number; name: string; price: number; billing_period: string }[];
  decision_roles: Record<string, string>;
}

/**
 * How this deal can be reached.
 *
 * What survives of the conversation zone. `wa_number` is composed server-side —
 * `wa.me` wants an international number with no punctuation, and this book's
 * numbers are stored without a country code.
 */
export interface DealOutreach {
  phone: string | null;
  wa_number: string | null;
  email: {
    can_send: boolean;
    reason?: string | null;
    default_to: string | null;
  };
}

export interface DealPageProps {
  /*
   * Everything down to `outreach` arrives with the document.
   *
   * These were optional because they were deferred — eight props across six
   * groups, which is what made the page settle six times before it could be
   * read. They are required now, and the types say so, so a component cannot
   * quietly render a fallback for data that is always present.
   */
  deal: Deal;
  can: DealCan;
  now: DealNow;
  evaluation: DealEvaluation;
  commercial: DealCommercial;
  assignable_users: { id: number; name: string }[];
  outreach: DealOutreach;
  /**
   * The WhatsApp thread — the same one the Marketing inbox reads and writes
   * — and the email history. The call log (`DealConversation['calls']`) is
   * not part of this page.
   */
  conversation: Pick<DealConversation, 'whatsapp' | 'email'>;

  /* The two audit tails, and the only things still deferred. */
  history?: DealHistory;
  files?: DealFile[];
}

export interface DealProfileProps extends ProfileOptions {
  deal: Deal;
  can: DealCan;
  profile: DealProfileData;
}
