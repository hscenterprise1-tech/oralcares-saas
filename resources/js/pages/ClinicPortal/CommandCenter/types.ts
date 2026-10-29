/** The Command Center's payload (`App\Support\Dashboard\OwnerCommandCenter`). The server is the contract. */

export type SectionState = 'ready' | 'error';

export interface Failed {
  state: 'error';
}

export interface Figure {
  key: string;
  label: string;
  value: number;
  detail?: string | null;
  href: string | null;
}

export interface TodaySection {
  state: 'ready';
  figures: Figure[];
}

export interface MineSection {
  state: 'ready';
  figures: Figure[];
  queue: {
    id: string;
    time: string | null;
    primary: string;
    secondary: string | null;
    status: string;
  }[];
  today_href: string | null;
}

export interface MoneyCell {
  value: number;
  count?: number;
  href?: string | null;
}

export interface MoneyPeriod {
  billed: MoneyCell;
  collected: MoneyCell;
  refunded: MoneyCell;
  adjusted: MoneyCell;
  net_collected: MoneyCell;
  discounts: MoneyCell;
  /** Payments received by method, largest first: the same rows as `collected`, which is their sum. */
  methods: PaymentMethodRow[];
  /** The clinic's desk methods that took nothing in the period, by label. */
  unused_methods: string[];
}

/** One payment method's share of what was collected; `href` opens the Collections list for that method. */
export interface PaymentMethodRow {
  key: string;
  label: string;
  amount: number;
  count: number;
  href?: string | null;
}

/** One clinic day of the money trend: bills issued (not cancelled) and payments received. */
export interface TrendDay {
  date: string;
  billed: number;
  collected: number;
}

export interface MoneySection {
  state: 'ready';
  currency: string;
  today: MoneyPeriod;
  month: MoneyPeriod;
  outstanding: MoneyCell;
  summary_href: string | null;
  trend: TrendDay[];
}

export interface SpendingBucket {
  count: number;
  amount: number;
  href: string | null;
}

export interface SpendingSection {
  state: 'ready';
  currency: string;
  /** This month as the Expenses list counts it: spent is approved plus paid; the top categories of that spend. */
  month?: {
    spent: number;
    paid: number;
    href: string | null;
    categories: { name: string; total: number }[];
  };
  to_decide: SpendingBucket;
  to_pay: SpendingBucket;
}

export interface DecisionItem {
  id: string;
  primary: string;
  secondary: string | null;
  count: number;
  status: string;
  href: string | null;
}

export interface DecisionsSection {
  state: 'ready';
  total: number;
  items: DecisionItem[];
}

export interface BranchesSection {
  state: 'ready';
  currency: string;
  rows: {
    id: number;
    label: string;
    values: {
      label: string;
      value: number;
      tone: string;
      format?: 'currency';
    }[];
  }[];
}

export interface Seat {
  key: string;
  label: string;
  used: number;
  allowed: number | null;
}

export interface PeopleSection {
  state: 'ready';
  plan: {
    name: string | null;
    status: string | null;
    ends_at: string | null;
    grace_ends_at: string | null;
  } | null;
  seats: Seat[];
  without_login: number;
  on_leave_today: number;
  /** The access window as the platform enforces it; `none` when there is no subscription. */
  subscription: {
    state: 'active' | 'trial' | 'grace' | 'lapsed' | 'none';
    plan: string | null;
    ends_on: string | null;
    days_remaining: number | null;
  } | null;
  workforce: {
    staff: number;
    practitioners: number;
    all_branches: number;
    some_branches: number;
  };
  modules: { key: string; label: string }[];
  specialties: { key: string; label: string }[];
}

export interface TrailItem {
  id: number;
  time: string;
  primary: string;
  secondary: string | null;
  status: 'alert' | 'info';
  /** The event's family (billing, expense, payroll, access, deletion, owner_mode, auth). */
  kind?: string;
}

export interface TrailSection {
  state: 'ready';
  total: number;
  items: TrailItem[];
}

export interface Sections {
  mine?: MineSection | Failed;
  today?: TodaySection | Failed;
  money?: MoneySection | Failed;
  spending?: SpendingSection | Failed;
  decisions?: DecisionsSection | Failed;
  branches?: BranchesSection | Failed;
  people?: PeopleSection | Failed;
  security?: TrailSection | Failed;
  activity?: TrailSection | Failed;
}
