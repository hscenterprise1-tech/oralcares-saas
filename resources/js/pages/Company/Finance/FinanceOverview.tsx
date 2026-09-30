import React from 'react';
import { Head } from '@inertiajs/react';
import { FinanceShell } from './components/FinanceShell';
import {
  CrmCard,
  CrmCardIcon,
  CrmOpenButton,
  CrmValue,
  CrmHairlineBar,
  CrmSparkline,
  CrmStatusPill,
  CrmGradientCard,
  CrmPageStat,
  CrmRing,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  AreaChart,
  Area,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import {
  ArrowDownRight,
  ArrowUpRight,
  Banknote,
  Clock,
  CreditCard,
  FileText,
  Landmark,
  Receipt,
  TrendingUp,
  Users,
  Wallet,
} from 'lucide-react';

/**
 * Finance → Command Center
 *
 * The same masthead, card language and hue key the CRM and Marketing overviews
 * use, so moving between modules never changes where the eye starts.
 *
 * One rule runs through every figure here: nothing is invented. A month with no
 * MRR snapshot is a break in the line, not a zero. Growth below two months of
 * history reads "Not enough history" rather than 0%. Subscriptions with no
 * recorded MRR are counted and named rather than folded in at zero. Invoiced
 * and collected are shown as the two different measures they are.
 */

/* ─────────────────────────────────────────────────── palette */

const HUE = {
  blue: '#2F6BE4',
  teal: '#3E93A3',
  violet: '#7C6FE0',
  green: '#2FA96B',
  yellow: '#E0B21E',
  orange: '#F59245',
  coral: '#EF5B39',
} as const;

/* ─────────────────────────────────────────────────── interfaces */

interface TrendPoint {
  period: string;
  month: string;
  label: string;
  collected: number;
  expenses_paid: number;
  payroll_paid: number;
  money_out: number;
  net: number;
  /** Null where no snapshot was taken that month. */
  mrr: number | null;
}

interface Props {
  recurring: {
    mrr: number;
    arr: number;
    arpu: number;
    active_subscriptions: number;
    active_clinics: number;
    unrecorded_subscriptions: number;
    growth_rate: number | null;
    history: Array<{ period: string; label: string; mrr: number; active_subscriptions: number; complete: boolean }>;
    has_history: boolean;
  };
  movement: {
    period: string;
    money_in: number;
    money_out: number;
    money_out_expenses: number;
    money_out_payroll: number;
    net_movement: number;
  };
  commitments: {
    expenses_awaiting_approval: number;
    expenses_approved_unpaid: number;
    payroll_approved_unpaid: number;
  };
  receivables: {
    total_outstanding: number;
    invoice_count: number;
    buckets: Record<string, number>;
  };
  lifetime: { collected: number; invoiced: number; expenses: number };
  trend: TrendPoint[];
  expense_breakdown: Array<{ category: string; paid: number; approved_unpaid: number; total: number }>;
  payroll: {
    paid_lifetime: number;
    awaiting_payment: number;
    recent: Array<{
      id: number;
      code: string;
      label: string;
      period_start: string | null;
      period_end: string | null;
      total_net: number;
      entry_count: number;
      paid_at: string | null;
    }>;
  };
  collections: Array<{
    id: number;
    amount: number;
    method: string | null;
    reference: string | null;
    paid_at: string | null;
    clinic: string | null;
    invoice_number: string | null;
  }>;
  invoices: {
    invoiced: number;
    paid: number;
    paid_count: number;
    open_count: number;
    collected: number;
    unlinked_collected: number;
  };
  can: { viewExpenses: boolean; viewPayroll: boolean; viewInvoices: boolean };
}

/* ─────────────────────────────────────────────────── formatting */

const inr = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

/** Compact for card faces, where a full figure would wrap. */
const compact = (value: number) => {
  const abs = Math.abs(value);
  if (abs >= 10000000) return `₹${(value / 10000000).toFixed(2)}Cr`;
  if (abs >= 100000) return `₹${(value / 100000).toFixed(2)}L`;
  if (abs >= 1000) return `₹${(value / 1000).toFixed(1)}K`;

  return `₹${Math.round(value)}`;
};

const day = (iso: string | null) =>
  iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '—';

const CATEGORY_LABEL: Record<string, string> = {
  software: 'Software & SaaS',
  infrastructure: 'Cloud infrastructure',
  marketing: 'Marketing',
  office: 'Office & admin',
  travel: 'Travel & field',
  miscellaneous: 'Other',
};

const CATEGORY_HUE = [HUE.blue, HUE.violet, HUE.orange, HUE.teal, HUE.yellow, HUE.coral];

const AGEING: Array<{ key: string; label: string; hue: string; tone: CrmStatusTone }> = [
  { key: 'current', label: 'Not yet due', hue: HUE.green, tone: 'good' },
  { key: '1_30', label: '1–30 days', hue: HUE.yellow, tone: 'warn' },
  { key: '31_60', label: '31–60 days', hue: HUE.orange, tone: 'warn' },
  { key: '61_90', label: '61–90 days', hue: HUE.coral, tone: 'bad' },
  { key: 'over_90', label: 'Over 90 days', hue: '#C0392B', tone: 'bad' },
  { key: 'undated', label: 'No due date', hue: HUE.teal, tone: 'neutral' },
];

/* ─────────────────────── MoneyCard — the CRM StatCard, in currency */

function MoneyCard({
  icon: Icon,
  label,
  value,
  hint,
  href,
  spark,
  sparkVariant = 'line',
  color,
  frost = false,
  step = 0,
  chip,
  signed = false,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: number | null;
  hint?: React.ReactNode;
  href?: string;
  spark?: number[];
  sparkVariant?: 'line' | 'bars';
  color: string;
  frost?: boolean;
  step?: number;
  chip?: React.ReactNode;
  /** Prefix a positive figure with +, so direction reads before the number. */
  signed?: boolean;
}) {
  return (
    <CrmCard className="flex min-h-[168px] flex-col" step={step} frostHue={frost ? color : undefined}>
      <div className="mb-4 flex items-start justify-between gap-2">
        <CrmCardIcon icon={Icon as never} hue={color} />
        {href ? <CrmOpenButton href={href} label={`Open ${label}`} /> : chip}
      </div>

      <p className="text-[13px] text-ovs-crm-text-secondary">{label}</p>

      <div className="mt-auto flex items-end justify-between gap-3 pt-3">
        <div className="min-w-0">
          {value !== null ? (
            <CrmValue value={`${signed && value > 0 ? '+' : ''}${compact(value)}`} size="major" />
          ) : (
            /* Not zero. No recorded figure is a different statement from none. */
            <span className="text-[18px] font-normal text-ovs-crm-text-muted">Not recorded</span>
          )}
          {hint && <p className="mt-1 truncate text-[11.5px] text-ovs-crm-text-muted">{hint}</p>}
        </div>
        {spark && spark.length > 1 && (
          <CrmSparkline points={spark} color={color} variant={sparkVariant} className="shrink-0" />
        )}
      </div>
    </CrmCard>
  );
}

function CardHead({
  icon,
  title,
  hint,
  href,
  openLabel,
  hue,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  hint?: string;
  href?: string;
  openLabel?: string;
  hue?: string;
}) {
  return (
    <div className="mb-5 flex items-start gap-3">
      <div className="min-w-0 flex-1">
        <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">{title}</h2>
        {hint && <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">{hint}</p>}
      </div>
      {hue && <CrmCardIcon icon={icon as never} hue={hue} />}
      {href && openLabel && <CrmOpenButton href={href} label={openLabel} />}
    </div>
  );
}

/* ═══════════════════════════════════════════════════════════════ page */

export default function FinanceOverview({
  recurring,
  movement,
  commitments,
  receivables,
  lifetime,
  trend = [],
  expense_breakdown = [],
  payroll,
  collections = [],
  invoices,
  can,
}: Props) {
  const [view, setView] = React.useState<'movement' | 'recurring'>('movement');

  const positive = movement.net_movement >= 0;
  const snapshotMonths = trend.filter((point) => point.mrr !== null).length;
  const hasMovement = trend.some((point) => point.collected > 0 || point.money_out > 0);

  const maxAgeing = Math.max(...AGEING.map((bucket) => receivables.buckets[bucket.key] ?? 0), 1);

  const totalCommitted =
    commitments.expenses_awaiting_approval + commitments.expenses_approved_unpaid + commitments.payroll_approved_unpaid;

  return (
    <FinanceShell title="Finance Overview">
      <Head title="Finance Command Center | Money in, money out" />

      {/* ═══ MASTHEAD ════════════════════════════════════════════════════════ */}
      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Finance</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Command Center
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            The company&apos;s own book, across every customer · {movement.period}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat
            icon={ArrowUpRight}
            value={compact(movement.money_in)}
            chip="collected"
            chipHue={HUE.green}
            caption={`In · ${movement.period}`}
          />
          <CrmPageStat
            icon={ArrowDownRight}
            value={compact(movement.money_out)}
            chip="paid out"
            chipHue={HUE.coral}
            caption={`Out · ${movement.period}`}
          />
          <CrmPageStat
            icon={Wallet}
            value={compact(receivables.total_outstanding)}
            chip={`${receivables.invoice_count} open`}
            chipHue={HUE.orange}
            caption="Owed to us"
          />
        </div>
      </div>

      {/* ═══ ROW 1 · KPI CARDS (8) + GRADIENT HERO (4) ══════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-8">
          <MoneyCard
            step={1}
            icon={ArrowUpRight}
            label="Money in"
            value={movement.money_in}
            hint={`${inr(lifetime.collected)} collected all time`}
            href={can.viewInvoices ? '/company/finance/payments' : undefined}
            spark={trend.map((point) => point.collected)}
            color={HUE.green}
            frost
          />
          <MoneyCard
            step={2}
            icon={ArrowDownRight}
            label="Money out"
            value={movement.money_out}
            hint={`${compact(movement.money_out_expenses)} expenses · ${compact(movement.money_out_payroll)} payroll`}
            href={can.viewExpenses ? '/company/finance/expenses' : undefined}
            spark={trend.map((point) => point.money_out)}
            color={HUE.coral}
          />
          <MoneyCard
            step={3}
            icon={positive ? TrendingUp : ArrowDownRight}
            label="Net movement"
            value={movement.net_movement}
            signed
            hint={positive ? 'More came in than went out' : 'More went out than came in'}
            spark={trend.map((point) => point.net)}
            color={positive ? HUE.blue : HUE.coral}
            chip={
              <CrmStatusPill tone={positive ? 'good' : 'bad'}>{positive ? 'Positive' : 'Negative'}</CrmStatusPill>
            }
          />
          <MoneyCard
            step={4}
            icon={Landmark}
            label="Recurring monthly"
            /*
             * Null rather than 0 when nothing is recorded, so the card says
             * "Not recorded" instead of claiming the business earns nothing.
             */
            value={recurring.mrr > 0 ? recurring.mrr : null}
            hint={
              recurring.unrecorded_subscriptions > 0
                ? `${recurring.active_subscriptions} active · ${recurring.unrecorded_subscriptions} unrecorded`
                : `${recurring.active_subscriptions} active · ${recurring.active_clinics} accounts`
            }
            href="/company/finance/revenue"
            spark={recurring.history.map((row) => row.mrr)}
            sparkVariant="bars"
            color={HUE.violet}
          />
        </div>

        {/* Gradient hero — the single number an executive opens this page for */}
        <CrmGradientCard
          className="lg:col-span-4"
          eyebrow={positive ? 'Net position' : 'Net position · attention'}
          timestamp={movement.period}
          tone={positive ? 'calm' : 'urgent'}
          headline={
            positive
              ? 'More money came in than went out this month.'
              : 'More money went out than came in this month.'
          }
          emphasis={`${positive ? '+' : ''}${inr(movement.net_movement)}`}
          href={can.viewExpenses ? '/company/finance/expenses' : '/company/finance/revenue'}
          label={can.viewExpenses ? 'Review outgoings' : 'Open revenue'}
        />
      </div>

      {/* ═══ ROW 2 · THE BIG TREND ═════════════════════════════════════════ */}
      <CrmCard className="mb-4 p-6" step={5}>
        <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              {view === 'movement' ? 'Cash movement' : 'Recurring revenue'}
            </h2>
            <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
              {view === 'movement'
                ? 'Six months of what arrived against what was paid out. Both are cash, not commitments.'
                : 'Recorded monthly, never recomputed. A gap is a month with no snapshot, not a month of nothing.'}
            </p>
          </div>

          {/*
            The period selector. Two measures that answer different questions —
            cash that moved, and the contracted run rate — so they get a switch
            rather than being stacked onto one misleading axis.
          */}
          <div
            role="tablist"
            aria-label="Trend view"
            className="flex shrink-0 items-center gap-1 rounded-full bg-ovs-crm-surface-inset p-1"
          >
            {(['movement', 'recurring'] as const).map((key) => (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={view === key}
                onClick={() => setView(key)}
                className={
                  view === key
                    ? 'rounded-full bg-ovs-crm-ink px-3.5 py-1.5 text-[12px] font-semibold text-white transition-colors'
                    : 'rounded-full px-3.5 py-1.5 text-[12px] font-semibold text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary'
                }
              >
                {key === 'movement' ? 'Cash movement' : 'Recurring'}
              </button>
            ))}
          </div>
        </div>

        {view === 'movement' && !hasMovement ? (
          <div className="flex min-h-[260px] flex-col items-center justify-center gap-2 rounded-[20px] bg-ovs-crm-surface-inset">
            <CrmCardIcon icon={Banknote as never} hue={HUE.teal} />
            <p className="mt-2 text-[13px] font-medium text-ovs-crm-text-primary">No cash movement recorded</p>
            <p className="max-w-[380px] text-center text-[12px] text-ovs-crm-text-muted">
              Nothing has been collected or paid out in the last six months.
            </p>
          </div>
        ) : view === 'recurring' && snapshotMonths < 2 ? (
          <div className="flex min-h-[260px] flex-col items-center justify-center gap-2 rounded-[20px] bg-ovs-crm-surface-inset">
            <CrmCardIcon icon={Landmark as never} hue={HUE.violet} />
            <p className="mt-2 text-[13px] font-medium text-ovs-crm-text-primary">Not enough history</p>
            <p className="max-w-[380px] text-center text-[12px] text-ovs-crm-text-muted">
              {snapshotMonths === 0
                ? 'No monthly MRR has been recorded yet. The first snapshot is taken at the start of next month, and a trend needs two.'
                : 'One month has been recorded. The next snapshot completes the trend.'}
            </p>
          </div>
        ) : (
          <div className="h-[280px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trend} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="finIn" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={HUE.green} stopOpacity={0.34} />
                    <stop offset="100%" stopColor={HUE.green} stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="finOut" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={HUE.coral} stopOpacity={0.26} />
                    <stop offset="100%" stopColor={HUE.coral} stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="finMrr" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={HUE.violet} stopOpacity={0.32} />
                    <stop offset="100%" stopColor={HUE.violet} stopOpacity={0} />
                  </linearGradient>
                </defs>

                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E8ECF3" />
                <XAxis dataKey="month" tick={{ fontSize: 11, fill: '#7A8598' }} tickLine={false} axisLine={false} />
                <YAxis
                  tick={{ fontSize: 11, fill: '#7A8598' }}
                  tickLine={false}
                  axisLine={false}
                  width={62}
                  tickFormatter={(value) => compact(Number(value))}
                />
                <Tooltip
                  formatter={(value, name) => [
                    value === null || value === undefined ? 'Not recorded' : inr(Number(value)),
                    name === 'collected' ? 'Collected' : name === 'money_out' ? 'Paid out' : 'MRR',
                  ]}
                  labelFormatter={(_, payload) => payload?.[0]?.payload?.label ?? ''}
                  contentStyle={{ borderRadius: 14, border: '1px solid #E8ECF3', fontSize: 12 }}
                />

                {view === 'movement' ? (
                  <>
                    <Area
                      type="monotone"
                      dataKey="collected"
                      stroke={HUE.green}
                      strokeWidth={2.5}
                      fill="url(#finIn)"
                      fillOpacity={1}
                    />
                    <Area
                      type="monotone"
                      dataKey="money_out"
                      stroke={HUE.coral}
                      strokeWidth={2}
                      strokeDasharray="4 4"
                      fill="url(#finOut)"
                      fillOpacity={1}
                    />
                  </>
                ) : (
                  /*
                   * `connectNulls` stays off. A month with no snapshot breaks
                   * the line, which is the honest rendering of a gap — joining
                   * across it would draw a trend through data nobody recorded.
                   */
                  <Area
                    type="monotone"
                    dataKey="mrr"
                    stroke={HUE.violet}
                    strokeWidth={2.5}
                    fill="url(#finMrr)"
                    fillOpacity={1}
                    connectNulls={false}
                  />
                )}
              </AreaChart>
            </ResponsiveContainer>
          </div>
        )}

        <div className="mt-4 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-ovs-crm-line pt-3">
          {view === 'movement' ? (
            <>
              <span className="inline-flex items-center gap-2 text-[12px] text-ovs-crm-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: HUE.green }} aria-hidden />
                Collected
              </span>
              <span className="inline-flex items-center gap-2 text-[12px] text-ovs-crm-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: HUE.coral }} aria-hidden />
                Paid out — expenses and payroll
              </span>
            </>
          ) : (
            <>
              <span className="inline-flex items-center gap-2 text-[12px] text-ovs-crm-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: HUE.violet }} aria-hidden />
                Recorded MRR
              </span>
              {snapshotMonths < trend.length && (
                <span className="text-[11.5px] text-ovs-crm-text-muted">
                  {trend.length - snapshotMonths} of {trend.length} months have no snapshot and are left as gaps
                </span>
              )}
            </>
          )}
        </div>
      </CrmCard>

      {/* ═══ ROW 3 · AGEING + EXPENSE BREAKDOWN ════════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <CrmCard className="p-6" step={6}>
          <CardHead
            icon={FileText}
            title="Receivables"
            hint={`${receivables.invoice_count} open invoice${receivables.invoice_count === 1 ? '' : 's'}, by how late they are`}
            hue={HUE.orange}
          />

          {receivables.total_outstanding === 0 ? (
            <div className="flex min-h-[180px] flex-col items-center justify-center gap-1.5 rounded-[20px] bg-ovs-crm-surface-inset">
              <p className="text-[13px] font-medium text-ovs-crm-text-primary">Nothing outstanding</p>
              <p className="text-[12px] text-ovs-crm-text-muted">Every invoice raised has been settled.</p>
            </div>
          ) : (
            <>
              <div className="mb-4 flex items-end gap-3">
                <CrmValue value={compact(receivables.total_outstanding)} size="major" />
                <span className="pb-1 text-[12px] text-ovs-crm-text-muted">owed across all accounts</span>
              </div>

              <ul className="space-y-3">
                {AGEING.map((bucket) => {
                  const amount = receivables.buckets[bucket.key] ?? 0;

                  return (
                    <li key={bucket.key} className="flex items-center gap-3">
                      <span className="w-[104px] shrink-0 text-[12px] text-ovs-crm-text-secondary">
                        {bucket.label}
                      </span>
                      <CrmHairlineBar value={amount} max={maxAgeing} color={bucket.hue} className="flex-1" />
                      <span className="w-[84px] shrink-0 text-right text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                        {amount > 0 ? compact(amount) : '—'}
                      </span>
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </CrmCard>

        <CrmCard className="p-6" step={7}>
          <CardHead
            icon={Receipt}
            title="Expense breakdown"
            hint="Money that has left the account, against money only agreed"
            hue={HUE.violet}
            href={can.viewExpenses ? '/company/finance/expenses' : undefined}
            openLabel={can.viewExpenses ? 'Open expenses' : undefined}
          />

          {expense_breakdown.length === 0 ? (
            <div className="flex min-h-[180px] flex-col items-center justify-center gap-1.5 rounded-[20px] bg-ovs-crm-surface-inset">
              <p className="text-[13px] font-medium text-ovs-crm-text-primary">No committed spend</p>
              <p className="text-[12px] text-ovs-crm-text-muted">
                Nothing has been approved yet, so there is no breakdown to show.
              </p>
            </div>
          ) : (
            <>
              <div className="mb-4 h-[150px] w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={expense_breakdown} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E8ECF3" />
                    <XAxis
                      dataKey="category"
                      tick={{ fontSize: 10, fill: '#7A8598' }}
                      tickLine={false}
                      axisLine={false}
                      tickFormatter={(value: string) => (CATEGORY_LABEL[value] ?? value).split(' ')[0]}
                    />
                    <YAxis
                      tick={{ fontSize: 10, fill: '#7A8598' }}
                      tickLine={false}
                      axisLine={false}
                      width={54}
                      tickFormatter={(value) => compact(Number(value))}
                    />
                    <Tooltip
                      formatter={(value) => inr(Number(value))}
                      labelFormatter={(value) => CATEGORY_LABEL[String(value)] ?? String(value)}
                      contentStyle={{ borderRadius: 14, border: '1px solid #E8ECF3', fontSize: 12 }}
                    />
                    <Bar dataKey="total" radius={[8, 8, 0, 0]}>
                      {expense_breakdown.map((row, index) => (
                        <Cell key={row.category} fill={CATEGORY_HUE[index % CATEGORY_HUE.length]} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>

              <ul className="space-y-2 border-t border-ovs-crm-line pt-3">
                {expense_breakdown.slice(0, 4).map((row, index) => (
                  <li key={row.category} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span
                      className="h-2 w-2 shrink-0 rounded-full"
                      style={{ background: CATEGORY_HUE[index % CATEGORY_HUE.length] }}
                      aria-hidden
                    />
                    <span className="min-w-[120px] flex-1 text-[12.5px] text-ovs-crm-text-secondary">
                      {CATEGORY_LABEL[row.category] ?? row.category}
                    </span>
                    <CrmStatusPill tone="good">{compact(row.paid)} paid</CrmStatusPill>
                    {row.approved_unpaid > 0 && (
                      <CrmStatusPill tone="warn">{compact(row.approved_unpaid)} owed</CrmStatusPill>
                    )}
                  </li>
                ))}
              </ul>
            </>
          )}
        </CrmCard>
      </div>

      {/* ═══ ROW 4 · COMMITMENTS + PAYROLL + COLLECTIONS ═══════════════════ */}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard className="p-6 lg:col-span-4" step={8}>
          <CardHead icon={Clock} title="Committed, unpaid" hint="Agreed to, not yet moved" hue={HUE.yellow} />

          <div className="mb-4 flex items-center gap-4">
            <CrmRing
              value={commitments.expenses_approved_unpaid + commitments.payroll_approved_unpaid}
              max={Math.max(totalCommitted, 1)}
              color={HUE.yellow}
              size={78}
              label={compact(totalCommitted)}
              caption="total"
            />
            <p className="text-[12px] text-ovs-crm-text-secondary">
              Approved money that has not left the account, plus claims still waiting on somebody.
            </p>
          </div>

          <ul className="space-y-2.5 border-t border-ovs-crm-line pt-3">
            <li className="flex items-baseline justify-between gap-3">
              <span className="text-[12.5px] text-ovs-crm-text-secondary">Expenses awaiting approval</span>
              <span className="text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                {compact(commitments.expenses_awaiting_approval)}
              </span>
            </li>
            <li className="flex items-baseline justify-between gap-3">
              <span className="text-[12.5px] text-ovs-crm-text-secondary">Expenses approved, unpaid</span>
              <span className="text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                {compact(commitments.expenses_approved_unpaid)}
              </span>
            </li>
            <li className="flex items-baseline justify-between gap-3">
              <span className="text-[12.5px] text-ovs-crm-text-secondary">Payroll approved, unpaid</span>
              <span className="text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                {compact(commitments.payroll_approved_unpaid)}
              </span>
            </li>
          </ul>
        </CrmCard>

        <CrmCard className="p-6 lg:col-span-4" step={9}>
          <CardHead
            icon={Users}
            title="Payroll"
            hint="Read from People. Finance never writes a payroll expense."
            hue={HUE.teal}
            href={can.viewPayroll ? '/company/people/payroll' : undefined}
            openLabel={can.viewPayroll ? 'Open payroll' : undefined}
          />

          <div className="mb-4 flex flex-wrap items-end gap-x-8 gap-y-3">
            <div>
              <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Paid to date</p>
              <CrmValue value={compact(payroll.paid_lifetime)} size="minor" className="mt-1" />
            </div>
            <div>
              <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Awaiting</p>
              <CrmValue value={compact(payroll.awaiting_payment)} size="minor" className="mt-1" />
            </div>
          </div>

          {payroll.recent.length === 0 ? (
            <div className="flex min-h-[110px] flex-col items-center justify-center gap-1.5 rounded-[20px] bg-ovs-crm-surface-inset">
              <p className="text-[12.5px] font-medium text-ovs-crm-text-primary">No paid runs yet</p>
              <p className="text-[11.5px] text-ovs-crm-text-muted">Runs appear here once they are marked paid.</p>
            </div>
          ) : (
            <ul className="space-y-2 border-t border-ovs-crm-line pt-3">
              {payroll.recent.slice(0, 4).map((run) => (
                <li key={run.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <span className="min-w-[110px] flex-1">
                    <span className="block text-[12.5px] font-medium text-ovs-crm-text-primary">{run.label}</span>
                    <span className="block text-[11px] text-ovs-crm-text-muted">
                      {run.entry_count} {run.entry_count === 1 ? 'person' : 'people'} · paid {run.paid_at}
                    </span>
                  </span>
                  <span className="text-[12.5px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {compact(run.total_net)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CrmCard>

        <CrmCard className="p-6 lg:col-span-4" step={10}>
          <CardHead
            icon={CreditCard}
            title="Recent collections"
            hint="Invoiced and collected are counted independently"
            hue={HUE.green}
            href={can.viewInvoices ? '/company/finance/payments' : undefined}
            openLabel={can.viewInvoices ? 'Open payments' : undefined}
          />

          <div className="mb-4 grid grid-cols-2 gap-3">
            <div className="rounded-[18px] bg-ovs-crm-surface-inset px-3.5 py-3">
              <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Invoiced</p>
              <p className="mt-1 text-[16px] font-semibold tabular-nums text-ovs-crm-text-primary">
                {compact(invoices.invoiced)}
              </p>
              <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">
                {invoices.paid_count} paid · {invoices.open_count} open
              </p>
            </div>
            <div className="rounded-[18px] bg-ovs-crm-surface-inset px-3.5 py-3">
              <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Collected</p>
              <p className="mt-1 text-[16px] font-semibold tabular-nums text-ovs-crm-text-primary">
                {compact(invoices.collected)}
              </p>
              {/*
                They legitimately disagree — some money arrived against a
                commercial document rather than an invoice. Saying so beats
                reconciling the figures by hiding one.
              */}
              {invoices.unlinked_collected > 0 && (
                <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">
                  {compact(invoices.unlinked_collected)} not against an invoice
                </p>
              )}
            </div>
          </div>

          {collections.length === 0 ? (
            <div className="flex min-h-[110px] flex-col items-center justify-center gap-1.5 rounded-[20px] bg-ovs-crm-surface-inset">
              <p className="text-[12.5px] font-medium text-ovs-crm-text-primary">Nothing collected yet</p>
              <p className="text-[11.5px] text-ovs-crm-text-muted">Payments appear here as they arrive.</p>
            </div>
          ) : (
            <ul className="space-y-2 border-t border-ovs-crm-line pt-3">
              {collections.slice(0, 4).map((payment) => (
                <li key={payment.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <span className="min-w-[110px] flex-1">
                    <span className="block truncate text-[12.5px] font-medium text-ovs-crm-text-primary">
                      {payment.clinic ?? 'Unattributed'}
                    </span>
                    <span className="block text-[11px] text-ovs-crm-text-muted">
                      {day(payment.paid_at)}
                      {payment.invoice_number ? ` · ${payment.invoice_number}` : ' · no invoice'}
                    </span>
                  </span>
                  <span className="text-[12.5px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {compact(payment.amount)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CrmCard>
      </div>
    </FinanceShell>
  );
}
