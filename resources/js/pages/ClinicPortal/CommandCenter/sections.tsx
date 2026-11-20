import { useEffect, useId, useMemo, useState, type CSSProperties, type ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowUpRight,
  BadgeCheck,
  Banknote,
  BarChart3,
  Building2,
  CalendarCheck2,
  ChevronRight,
  CircleDollarSign,
  Hourglass,
  KeyRound,
  Landmark,
  Layers,
  ReceiptText,
  RotateCcw,
  ShieldAlert,
  ShieldCheck,
  Stethoscope,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Tooltip, XAxis, YAxis } from 'recharts';
import { ErrorState } from '@/clinic/ui/states';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import type { BranchesSection, DecisionsSection, Figure, MineSection, MoneyPeriod, MoneySection, PeopleSection, SpendingSection, TrailSection, TrendDay } from './types';

/**
 * The page's one surface: solid white, a hairline border and the portal's own
 * card shadow — the same depth as the rest of the Clinic Portal, never tinted
 * by what lies behind it. Outer radius 16; anything inside a surface uses 12
 * (`rounded-md` in this project's scale, where `rounded-xl` is 24).
 */
export const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

/**
 * The grid every two-column row shares, so right-hand columns line up down
 * the page: 3:2 on a laptop (1024), where the right column is still wide
 * enough for its card, and 2:1 from 1280. Below 1024 the cards stack.
 */
export const ROW = 'grid gap-4 sm:gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] xl:gap-6';

export const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal focus-visible:ring-offset-2';

/**
 * A link set in a line of small text that must still be easy to tap.
 * On a phone, where such links stack, each is its own 44px-tall row and the
 * rows touch without overlapping. From a tablet up, where they share one
 * line, an invisible layer reaches 14px above and below the 16px line (and
 * 4px to each side), giving the same 44px target without spreading the text.
 * The corners are only 2px round: a browser leaves a rounded corner's
 * cut-away out of the tap area, and a larger radius loses the box's corners.
 */
const REACH = "relative z-10 flex min-h-11 items-center rounded-[2px] sm:inline-flex sm:min-h-0 sm:before:absolute sm:before:-inset-x-1 sm:before:-inset-y-3.5 sm:before:content-['']";

/** Entrance for a section: a short rise, once, off for reduced motion. `order` staggers neighbours. */
export function rise(order = 0): { className: string; style: CSSProperties } {
  return {
    className: 'animate-in fade-in-0 slide-in-from-bottom-2 fill-mode-both duration-500 motion-reduce:animate-none',
    style: { animationDelay: `${order * 60}ms` },
  };
}

/** Whether the viewer asked for less motion (charts then draw without animating). */
export function useReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false);
  useEffect(() => {
    const query = window.matchMedia('(prefers-reduced-motion: reduce)');
    setReduced(query.matches);
    const onChange = () => setReduced(query.matches);
    query.addEventListener('change', onChange);

    return () => query.removeEventListener('change', onChange);
  }, []);

  return reduced;
}

/**
 * The size of a box, kept current as it grows or shrinks: a ResizeObserver,
 * with the window's resize as a fallback. The chart is drawn at exactly this
 * size, so it can never be wider than the card it sits in.
 */
function useBoxSize(): [(node: HTMLDivElement | null) => void, { width: number; height: number }] {
  const [node, setNode] = useState<HTMLDivElement | null>(null);
  const [size, setSize] = useState({ width: 0, height: 0 });
  useEffect(() => {
    if (!node) {
      return;
    }
    // The layout size, not the painted one: a CSS transform on an ancestor (a zoomed preview, a print scale) must not shrink the chart.
    const measure = () => {
      const width = node.clientWidth;
      const height = node.clientHeight;
      setSize((current) => (current.width === width && current.height === height ? current : { width, height }));
    };
    measure();
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);
    observer?.observe(node);
    window.addEventListener('resize', measure);

    return () => {
      observer?.disconnect();
      window.removeEventListener('resize', measure);
    };
  }, [node]);

  return [setNode, size];
}

/** A white surface with a soft diffuse shadow: the Command Center's one card, used where content needs a container. */
export function Panel({
  id,
  title,
  caption,
  aside,
  icon: Icon,
  tone = 'teal',
  children,
  className,
  style,
}: {
  id: string;
  title: string;
  caption?: string;
  aside?: ReactNode;
  icon?: LucideIcon;
  tone?: Tone;
  children: ReactNode;
  className?: string;
  style?: CSSProperties;
}) {
  return (
    <section aria-labelledby={`${id}-title`} className={cn('flex min-w-0 flex-col', SURFACE, className)} style={style}>
      <header className="flex min-h-[56px] items-center justify-between gap-x-4 px-5 pt-5">
        <div className="flex min-w-0 items-center gap-3">
          {Icon && <IconChip icon={Icon} tone={tone} />}
          <div className="min-w-0">
            <h2 id={`${id}-title`} className="truncate text-[15px] font-semibold leading-5 text-cx-nav-ink">
              {title}
            </h2>
            {caption && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{caption}</p>}
          </div>
        </div>
        {/* A 44px link inside a 36px row: its extra height overlaps the padding, so every header is the same height. */}
        {aside && <div className="-my-1 shrink-0">{aside}</div>}
      </header>
      <div className="flex-1 px-5 pb-5 pt-4">{children}</div>
    </section>
  );
}

export function FailedPanel({ id, title, onRetry }: { id: string; title: string; onRetry: () => void }) {
  return (
    <Panel id={id} title={title}>
      <ErrorState compact title="This part did not load" onRetry={onRetry} />
    </Panel>
  );
}

/* ----------------------------------------------------------------- tones */

/**
 * The accents. Cyan/teal, blue and violet carry the page's identity and give
 * each section its own colour; amber and rose only ever mean something (still
 * due, failed, at a limit), and green means all clear.
 */
export type Tone = 'blue' | 'teal' | 'violet' | 'amber' | 'rose' | 'mint' | 'slate';

/** Each accent: its icon tile gradient, its soft pastel wash, a flat tint, the ink that reads on them, and the stroke for bars and charts. */
const TONES: Record<Tone, { chip: string; wash: string; soft: string; ink: string; stroke: string }> = {
  blue: { chip: 'from-[#4F9BF0] to-[#2B6FD8]', wash: 'from-[#EEF5FF] to-[#E2ECFD]', soft: 'bg-cx-blue-bg', ink: 'text-cx-blue-ink', stroke: '#2B6FD8' },
  teal: { chip: 'from-[#2CC6B4] to-[#0E9F87]', wash: 'from-[#EAFAF6] to-[#DCF3EE]', soft: 'bg-cx-teal-bg', ink: 'text-cx-teal-ink', stroke: '#0E9F87' },
  violet: { chip: 'from-[#9B7BF2] to-[#6B48DE]', wash: 'from-[#F4F0FF] to-[#EAE3FD]', soft: 'bg-cx-violet-bg', ink: 'text-cx-violet-ink', stroke: '#6B48DE' },
  amber: { chip: 'from-[#F7B451] to-[#E9821A]', wash: 'from-[#FFF7EA] to-[#FFEDD2]', soft: 'bg-cx-amber-bg', ink: 'text-cx-amber-ink', stroke: '#E9821A' },
  rose: { chip: 'from-[#F58A94] to-[#E5484D]', wash: 'from-[#FFF1F2] to-[#FDE3E5]', soft: 'bg-cx-rose-bg', ink: 'text-cx-rose-ink', stroke: '#E5484D' },
  mint: { chip: 'from-[#5FD9A6] to-[#12A877]', wash: 'from-[#EBFAF2] to-[#DAF4E7]', soft: 'bg-cx-green-bg', ink: 'text-cx-green-ink', stroke: '#12A877' },
  slate: { chip: 'from-[#94A3B8] to-[#64748B]', wash: 'from-[#F4F6FA] to-[#EBEFF5]', soft: 'bg-cx-slate-bg', ink: 'text-cx-slate-ink', stroke: '#64748B' },
};

/**
 * A line icon on a coloured tile. `solid` is the section's own gradient tile
 * (card headers and the money cards); `soft` is a pastel tint for rows inside
 * a card, so the section colour leads and the rows support it.
 */
export function IconChip({ icon: Icon, tone, size = 'md', variant = 'solid' }: { icon: LucideIcon; tone: Tone; size?: 'sm' | 'md'; variant?: 'solid' | 'soft' }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid shrink-0 place-items-center',
        variant === 'solid' ? cn('bg-gradient-to-br text-white shadow-[0_4px_10px_-6px_rgba(30,50,110,0.45)]', TONES[tone].chip) : cn(TONES[tone].soft, TONES[tone].ink),
        size === 'md' ? 'h-9 w-9 rounded-[10px]' : 'h-8 w-8 rounded-sm',
      )}
    >
      {/* The sidebar's own stroke weight, so the page and the navigation draw icons alike. */}
      <Icon className={size === 'md' ? 'h-[18px] w-[18px]' : 'h-4 w-4'} strokeWidth={1.75} />
    </span>
  );
}

/** A link that reads as part of the text it wraps, with the arrow showing where it leads. */
function Go({ href, label, children, className }: { href: string; label: string; children: ReactNode; className?: string }) {
  return (
    <Link href={href} aria-label={label} className={cn('inline-flex min-h-11 items-center gap-1 rounded-lg text-[13px] font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS, className)}>
      {children}
      <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
    </Link>
  );
}

/* ----------------------------------------------------------------- money */

export type Period = 'today' | 'month';

const PERIOD_LABEL: Record<Period, string> = { today: 'Today', month: 'This month' };

export function PeriodSwitch({ period, onChange }: { period: Period; onChange: (period: Period) => void }) {
  return (
    <div role="group" aria-label="Money figures for" className="inline-flex rounded-full bg-white p-1 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)]">
      {(Object.keys(PERIOD_LABEL) as Period[]).map((key) => (
        <button
          key={key}
          type="button"
          aria-pressed={period === key}
          onClick={() => onChange(key)}
          className={cn('min-h-10 rounded-full px-4 text-[13px] font-medium transition-colors', period === key ? 'bg-cx-teal-bg text-cx-teal-ink' : 'text-cx-text-2 hover:text-cx-nav-ink', FOCUS)}
        >
          {PERIOD_LABEL[key]}
        </button>
      ))}
    </div>
  );
}

/** The collected share of what was billed, or null when nothing was billed (never a made-up 0%). */
export function collectionRate(period: MoneyPeriod): number | null {
  return period.billed.value > 0 ? Math.round((period.collected.value / period.billed.value) * 100) : null;
}

/**
 * One money figure on its own soft pastel wash, labelled in its colour:
 * blue billed, teal collected, amber still due, rose given back. The four
 * share one anatomy, so their parts line up across the row: the label with
 * the period it covers beneath it (a 36px block beside the 32px icon), the
 * figure, then one line of detail. `shortLabel` stands in on a phone, where
 * two cards share a row and the full label would wrap and push its figure
 * below its neighbour's.
 */
export function KpiCard({
  tone,
  icon,
  label,
  shortLabel,
  period,
  description,
  value,
  note,
  href,
  onSelect,
  active = false,
  style,
  className,
}: {
  tone: Tone;
  icon: LucideIcon;
  label: string;
  shortLabel?: string;
  /** The time the figure covers, said under the label: "Today", "This month", "Now, all dates". */
  period: string;
  /** The label as a screen reader hears it, with the period the figure covers. */
  description: string;
  value: string;
  note: ReactNode;
  href: string | null | undefined;
  /** In place of a link: the card picks something on the page (the Front Desk's lanes), `active` while it is picked. */
  onSelect?: () => void;
  active?: boolean;
  style?: CSSProperties;
  className?: string;
}) {
  const body = (
    <>
      <span className={cn('pointer-events-none absolute inset-0 -z-10 bg-gradient-to-br', TONES[tone].wash)} aria-hidden="true" />
      <span className="flex items-start justify-between gap-2">
        <span className="min-w-0">
          <span className={cn('block truncate text-[13px] font-medium leading-5', TONES[tone].ink)}>
            {shortLabel ? (
              <>
                <span className="sm:hidden">{shortLabel}</span>
                <span className="hidden sm:inline">{label}</span>
              </>
            ) : (
              label
            )}
          </span>
          <span className="block text-[12px] leading-4 text-cx-text-3">{period}</span>
        </span>
        <IconChip icon={icon} tone={tone} size="sm" />
      </span>
      {/* Two to a row on a phone, so the figure steps down a size there to keep a long amount on one line. */}
      <span className="mt-3 block break-words text-[20px] font-semibold leading-7 tracking-[-0.02em] text-cx-nav-ink tabular-nums sm:text-[24px]">{value}</span>
      <span className="mt-1 block min-h-4 text-[12px] leading-4 text-cx-text-2">{note}</span>
      {href && (
        <ArrowUpRight className={cn('absolute bottom-4 right-4 h-4 w-4 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100', TONES[tone].ink)} aria-hidden="true" />
      )}
    </>
  );
  const classes = cn('group relative isolate flex min-w-0 flex-col overflow-hidden rounded-2xl border border-white/70 p-4 shadow-cx-card sm:p-5', className);
  const lift = 'transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-cx-raised motion-reduce:transition-none motion-reduce:hover:translate-y-0';

  if (onSelect) {
    return (
      <button type="button" onClick={onSelect} aria-pressed={active} aria-label={`${description}: ${value}. Show the list`} className={cn(classes, 'text-left', lift, FOCUS, active && 'ring-2 ring-inset ring-cx-nav-ink/15')} style={style}>
        {body}
      </button>
    );
  }

  return href ? (
    <Link
      href={href}
      aria-label={`${description}: ${value}. Open the list`}
      className={cn(classes, 'transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-cx-raised motion-reduce:transition-none motion-reduce:hover:translate-y-0', FOCUS)}
      style={style}
    >
      {body}
    </Link>
  ) : (
    <div className={classes} style={style}>
      {body}
    </div>
  );
}

/** Billed, collected, outstanding, refunds and adjustments: each the total of the Billing list it opens. */
export function KpiRow({ money, period }: { money: MoneySection; period: Period }) {
  const p = money[period];
  const c = money.currency;
  const when = PERIOD_LABEL[period].toLowerCase();
  const bills = p.billed.count ?? null;
  const payments = (p.methods ?? []).reduce((sum, method) => sum + method.count, 0);
  const plural = (n: number, one: string, many: string) => `${formatNumber(n)} ${n === 1 ? one : many}`;

  return (
    <div className="grid grid-cols-2 gap-3 xl:grid-cols-4 xl:gap-4">
      <KpiCard
        {...rise(1)}
        tone="blue"
        icon={ReceiptText}
        label="Billed"
        period={PERIOD_LABEL[period]}
        description={`Billed ${when}`}
        value={formatMoney(p.billed.value, c)}
        note={[bills !== null ? plural(bills, 'bill', 'bills') : 'Bills issued', p.discounts.value > 0 ? `${formatMoney(p.discounts.value, c)} discounts` : null].filter(Boolean).join(' · ')}
        href={p.billed.href}
      />
      <KpiCard
        {...rise(2)}
        tone="teal"
        icon={Wallet}
        label="Collected"
        period={PERIOD_LABEL[period]}
        description={`Collected ${when}`}
        value={formatMoney(p.collected.value, c)}
        note={p.net_collected.value !== p.collected.value ? `${formatMoney(p.net_collected.value, c)} after refunds` : payments > 0 ? plural(payments, 'payment', 'payments') : 'Payments received'}
        href={p.collected.href}
      />
      <KpiCard
        {...rise(3)}
        tone="amber"
        icon={Hourglass}
        label="Outstanding"
        period="Now, all dates"
        description="Outstanding now, all dates"
        value={formatMoney(money.outstanding.value, c)}
        note="Still due on open bills"
        href={money.outstanding.href}
      />
      <KpiCard
        {...rise(4)}
        tone="rose"
        icon={RotateCcw}
        label="Refunds & adjustments"
        shortLabel="Refunds"
        period={PERIOD_LABEL[period]}
        description={`Refunds and adjustments ${when}`}
        value={formatMoney(p.refunded.value + p.adjusted.value, c)}
        note={
          // One line from a tablet up. Stacked on a phone, where two cards share a row: two touching 44px rows whose text
          // sits at their shared edge (the first at its foot, the second at its head), so the lines read 12px apart while
          // the boxes never overlap. The second box ends 10px into the card's 16px padding, never past its clipped edge.
          // No separator is left dangling.
          <span className="-mb-2.5 -mt-[22px] flex flex-col items-start sm:my-0 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-1">
            {p.refunded.href ? (
              <Link href={p.refunded.href} className={cn(REACH, 'items-end pb-1.5 sm:items-center sm:pb-0 font-medium text-cx-rose-ink hover:underline', FOCUS)}>
                {formatMoney(p.refunded.value, c)} refunded
              </Link>
            ) : (
              `${formatMoney(p.refunded.value, c)} refunded`
            )}
            <span className="hidden sm:inline" aria-hidden="true">
              ·
            </span>
            {p.adjusted.href ? (
              <Link href={p.adjusted.href} className={cn(REACH, 'items-start pt-1.5 sm:items-center sm:pt-0 font-medium text-cx-rose-ink hover:underline', FOCUS)}>
                {formatMoney(p.adjusted.value, c)} adjusted
              </Link>
            ) : (
              `${formatMoney(p.adjusted.value, c)} adjusted`
            )}
          </span>
        }
        href={null}
      />
    </div>
  );
}

/* ------------------------------------------------------------ revenue trend */

const RANGES = [7, 30] as const;

function dayLabel(iso: string, long = false): string {
  return new Intl.DateTimeFormat('en-IN', long ? { weekday: 'short', day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short' }).format(new Date(`${iso}T00:00:00`));
}

/** An axis amount in the clinic's currency, short: ₹16K, ₹1.2L. */
function compactMoney(value: number, currency: string): string {
  try {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(value);
  } catch {
    return new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
  }
}

/**
 * Billed against collected, per clinic day, from the same records as the
 * Billing lists. Bars, not a smoothed line: each day is its own total, and a
 * curve would draw money between days that had none. It opens on the last 7
 * days when nothing happened before them, so a new clinic's first days are
 * not squeezed into the corner of a month of zeros.
 */
export function RevenueTrend({ money, reducedMotion }: { money: MoneySection; reducedMotion: boolean }) {
  const [range, setRange] = useState<(typeof RANGES)[number]>(() => (money.trend.slice(0, -7).some((day) => day.billed > 0 || day.collected > 0) ? 30 : 7));
  const days = useMemo(() => money.trend.slice(-range), [money.trend, range]);
  const totals = useMemo(() => days.reduce((sum, day) => ({ billed: sum.billed + day.billed, collected: sum.collected + day.collected }), { billed: 0, collected: 0 }), [days]);
  const active = days.filter((day) => day.billed > 0 || day.collected > 0).length;
  const empty = active === 0;
  const c = money.currency;
  const ids = useId().replace(/:/g, '');
  const [box, size] = useBoxSize();

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* The legend, with each series' total over the days shown. */}
        <dl className="flex flex-wrap gap-x-6 gap-y-2">
          <div>
            <dt className="flex items-center gap-2 text-[12px] text-cx-text-2">
              <span className="h-2.5 w-2.5 rounded-sm bg-[#2B6FD8]" aria-hidden="true" />
              Billed · {range} days
            </dt>
            <dd className="mt-0.5 text-[16px] font-semibold text-cx-nav-ink tabular-nums">{formatMoney(totals.billed, c)}</dd>
          </div>
          <div>
            <dt className="flex items-center gap-2 text-[12px] text-cx-text-2">
              <span className="h-2.5 w-2.5 rounded-sm bg-[#0EA5B7]" aria-hidden="true" />
              Collected · {range} days
            </dt>
            <dd className="mt-0.5 text-[16px] font-semibold text-cx-nav-ink tabular-nums">{formatMoney(totals.collected, c)}</dd>
          </div>
        </dl>
        <div role="group" aria-label="Days shown" className="inline-flex rounded-full bg-cx-blue-bg/70 p-1">
          {RANGES.map((value) => (
            <button
              key={value}
              type="button"
              aria-pressed={range === value}
              onClick={() => setRange(value)}
              className={cn(
                'min-h-10 rounded-full px-3.5 text-[12.5px] font-medium transition-colors',
                range === value ? 'bg-white text-cx-blue-ink shadow-sm' : 'text-cx-text-2 hover:text-cx-nav-ink',
                FOCUS,
              )}
            >
              {value} days
            </button>
          ))}
        </div>
      </div>

      <div
        ref={box}
        className="relative mt-4 h-[220px] w-full overflow-hidden sm:h-[240px]"
        role="img"
        aria-label={`Billed ${formatMoney(totals.billed, c)} and collected ${formatMoney(totals.collected, c)} over the last ${range} days`}
      >
        {empty ? (
          <div className="grid h-full place-items-center rounded-md bg-gradient-to-br from-[#EEF5FF] via-[#EEF7FA] to-[#F3F0FF] px-6 text-center">
            <div className="flex flex-col items-center">
              <IconChip icon={BarChart3} tone="blue" variant="soft" />
              <p className="mt-3 text-[14px] font-medium text-cx-nav-ink">No bills or payments in the last {range} days</p>
              <p className="mt-1 max-w-[36ch] text-[12.5px] leading-5 text-cx-text-2">Each day's billing and collections appear here as bars once the clinic starts billing.</p>
            </div>
          </div>
        ) : size.width > 0 ? (
          <BarChart width={size.width} height={size.height} data={days} margin={{ top: 8, right: 4, left: 0, bottom: 0 }} barGap={2} barCategoryGap={range === 7 ? '28%' : '18%'}>
            <CartesianGrid vertical={false} stroke="#E8EDF4" />
            <XAxis dataKey="date" tickFormatter={(value: string) => dayLabel(value)} tick={{ fontSize: 12, fill: '#5F6B7E' }} tickLine={false} axisLine={{ stroke: '#E1E7EF' }} minTickGap={20} />
            <YAxis width={56} tick={{ fontSize: 12, fill: '#5F6B7E' }} tickLine={false} axisLine={false} allowDecimals={false} tickFormatter={(value: number) => compactMoney(value, c)} />
            <defs>
              <linearGradient id={`${ids}-billed`} x1="0" x2="0" y1="0" y2="1">
                <stop offset="0" stopColor="#4F9BF0" />
                <stop offset="1" stopColor="#2B6FD8" />
              </linearGradient>
              <linearGradient id={`${ids}-collected`} x1="0" x2="0" y1="0" y2="1">
                <stop offset="0" stopColor="#3FD0C9" />
                <stop offset="1" stopColor="#0EA5B7" />
              </linearGradient>
            </defs>
            <Tooltip content={<TrendTooltip currency={c} />} cursor={{ fill: 'rgba(43,111,216,0.06)' }} />
            <Bar dataKey="billed" name="Billed" fill={`url(#${ids}-billed)`} radius={[4, 4, 0, 0]} maxBarSize={18} isAnimationActive={!reducedMotion} animationDuration={600} />
            <Bar dataKey="collected" name="Collected" fill={`url(#${ids}-collected)`} radius={[4, 4, 0, 0]} maxBarSize={18} isAnimationActive={!reducedMotion} animationDuration={600} />
          </BarChart>
        ) : null}
      </div>
      {/* With little history, say so in the data's own terms, so a mostly empty chart reads as young, not broken. */}
      {!empty && active * 2 < days.length && (
        <p className="mt-3 flex items-center gap-2 rounded-md bg-cx-blue-bg/60 px-3 py-2 text-[12.5px] text-cx-text-2">
          <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[#2B6FD8]" aria-hidden="true" />
          Billing or payments on {active} of the last {range} days.
        </p>
      )}
    </div>
  );
}

function TrendTooltip({ active, payload, currency }: { active?: boolean; payload?: { payload: TrendDay }[]; currency: string }) {
  const day = active ? payload?.[0]?.payload : undefined;
  if (!day) {
    return null;
  }

  return (
    <div className="rounded-md border border-cx-line bg-white px-3.5 py-2.5 text-[12.5px] shadow-cx-raised">
      <p className="font-medium text-cx-nav-ink">{dayLabel(day.date, true)}</p>
      <p className="mt-1 flex items-center gap-2 text-cx-text-2">
        <span className="h-2 w-2 rounded-sm bg-[#2B6FD8]" aria-hidden="true" />
        Billed <b className="ml-auto pl-4 font-semibold text-cx-nav-ink tabular-nums">{formatMoney(day.billed, currency)}</b>
      </p>
      <p className="mt-0.5 flex items-center gap-2 text-cx-text-2">
        <span className="h-2 w-2 rounded-sm bg-[#0EA5B7]" aria-hidden="true" />
        Collected <b className="ml-auto pl-4 font-semibold text-cx-nav-ink tabular-nums">{formatMoney(day.collected, currency)}</b>
      </p>
    </div>
  );
}

/** Each payment method's colour: teal cash, violet UPI, blue card, cyan bank transfer; quieter tones for the rest. */
const METHOD_COLOUR: Record<string, string> = {
  cash: '#0E9F87',
  upi: '#6B48DE',
  card: '#2B6FD8',
  bank_transfer: '#0EA5B7',
  gateway: '#4F5BD5',
  cheque: '#64748B',
  insurance: '#8A6FB8',
};

/**
 * The collection rate, then where the money came in from.
 *
 * The rate is payments received ÷ bills issued in the same period, the same
 * two figures as the cards above; a payment can settle an older bill, so it
 * may pass 100% (the ring stops full, the number does not). The breakdown is
 * the payments received in the period by method, the same rows as the
 * Collected card, so its lines always add up to that total; refunds are their
 * own figure and are not taken off here. Each line opens the Collections list
 * for that method. The clinic's other desk methods that took nothing are
 * named once, quietly, rather than as a column of zero rows.
 */
export function CollectionOverview({ money, period, reducedMotion }: { money: MoneySection; period: Period; reducedMotion: boolean }) {
  const p = money[period];
  const c = money.currency;
  const rate = collectionRate(p);
  const other: Period = period === 'today' ? 'month' : 'today';
  const otherRate = collectionRate(money[other]);
  const shown = rate === null ? 0 : Math.min(100, rate);
  const radius = 52;
  const circumference = 2 * Math.PI * radius;
  const when = period === 'today' ? 'today' : 'this month';
  const methods = p.methods ?? [];
  const payments = methods.reduce((sum, method) => sum + method.count, 0);
  const unused = p.unused_methods ?? [];

  return (
    // Full width on a tablet, the rate and the breakdown sit side by side; in the side column from 1024, one above the other.
    <div className="flex h-full flex-col gap-4 sm:grid sm:grid-cols-2 sm:gap-6 lg:flex lg:gap-4">
      <div className="space-y-4">
        <div className="flex items-center gap-4">
          <div className="relative h-[104px] w-[104px] shrink-0" role="img" aria-label={rate === null ? `Nothing billed ${when}` : `${rate}% of the amount billed ${when} has been collected`}>
            <svg viewBox="0 0 132 132" className="h-full w-full -rotate-90">
              <defs>
                <linearGradient id="cc-ring" x1="0" x2="1" y1="0" y2="1">
                  <stop offset="0" stopColor="#0EA5B7" />
                  <stop offset="0.55" stopColor="#2B6FD8" />
                  <stop offset="1" stopColor="#6B48DE" />
                </linearGradient>
              </defs>
              <circle cx="66" cy="66" r={radius} fill="none" stroke="#EDF1F7" strokeWidth="10" />
              <circle
                cx="66"
                cy="66"
                r={radius}
                fill="none"
                stroke="url(#cc-ring)"
                strokeWidth="10"
                strokeLinecap="round"
                strokeDasharray={circumference}
                strokeDashoffset={circumference * (1 - shown / 100)}
                style={{ transition: reducedMotion ? undefined : 'stroke-dashoffset 900ms cubic-bezier(.2,.7,.2,1)' }}
              />
            </svg>
            <span className="absolute inset-0 grid place-content-center text-center">
              <b className="text-[22px] font-semibold leading-none tracking-[-0.02em] text-cx-nav-ink tabular-nums">{rate === null ? '—' : `${rate}%`}</b>
              <small className="mt-1 text-[11.5px] text-cx-text-3">collected</small>
            </span>
          </div>
          <p className="min-w-0 flex-1 text-[13px] leading-5 text-cx-text-2">{rate === null ? `Nothing billed ${when} yet.` : `Of the amount billed ${when}, the share paid so far.`}</p>
        </div>
        <div className="rounded-md bg-gradient-to-br from-[#EAFAF6] to-[#E2ECFD] px-3 py-2">
          <div className="flex items-baseline justify-between gap-2 text-[12.5px]">
            <span className="text-cx-text-2">{PERIOD_LABEL[other]}</span>
            <span className="font-medium text-cx-nav-ink tabular-nums">{otherRate === null ? 'Nothing billed' : `${otherRate}% collected`}</span>
          </div>
          <span className="mt-1.5 block h-1 overflow-hidden rounded-full bg-white/80" aria-hidden="true">
            <span className="block h-full rounded-full bg-[#0EA5B7]" style={{ width: `${otherRate === null ? 0 : Math.min(100, otherRate)}%` }} />
          </span>
        </div>
      </div>

      <div className="border-t border-cx-line pt-3 sm:border-l sm:border-t-0 sm:pl-6 sm:pt-0 lg:border-l-0 lg:border-t lg:pl-0 lg:pt-3">
        <div className="flex items-baseline justify-between gap-3">
          <h3 className="text-[12.5px] font-medium text-cx-text-2">By payment method</h3>
          {payments > 0 && (
            <p className="text-[12px] text-cx-text-3 tabular-nums">
              {formatNumber(payments)} {payments === 1 ? 'payment' : 'payments'}
            </p>
          )}
        </div>
        {methods.length === 0 ? (
          <p className="mt-2 rounded-md bg-cx-nav-canvas/70 px-3 py-2.5 text-[12.5px] text-cx-text-2">No payments received {when}.</p>
        ) : (
          <ul className="mt-1">
            {methods.map((method) => {
              const share = p.collected.value > 0 ? Math.round((method.amount / p.collected.value) * 100) : 0;
              const colour = METHOD_COLOUR[method.key] ?? '#64748B';
              const body = (
                <>
                  <span className="flex items-center gap-2.5">
                    <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: colour }} aria-hidden="true" />
                    <span className="min-w-0 flex-1 truncate text-[13px] font-medium text-cx-nav-ink">{method.label}</span>
                    <span className="shrink-0 text-[12px] text-cx-text-3 tabular-nums">{share}%</span>
                    <span className="w-[76px] shrink-0 text-right text-[13px] font-semibold text-cx-nav-ink tabular-nums">{formatMoney(method.amount, c)}</span>
                  </span>
                  <span className="mt-1.5 ml-5 flex items-center gap-2.5">
                    <span className="h-1 flex-1 overflow-hidden rounded-full bg-cx-nav-canvas" aria-hidden="true">
                      <span className="block h-full rounded-full" style={{ width: `${share}%`, background: colour }} />
                    </span>
                    <span className="w-[76px] shrink-0 text-right text-[11.5px] text-cx-text-3 tabular-nums">
                      {formatNumber(method.count)} {method.count === 1 ? 'payment' : 'payments'}
                    </span>
                  </span>
                </>
              );

              return (
                <li key={method.key}>
                  {method.href ? (
                    <Link
                      href={method.href}
                      aria-label={`${method.label}: ${formatMoney(method.amount, c)} from ${method.count} ${method.count === 1 ? 'payment' : 'payments'}, ${share}% of collections. Open the list`}
                      className={cn('-mx-2 block min-h-11 rounded-md px-2 py-2 transition-colors hover:bg-cx-nav-canvas', FOCUS)}
                    >
                      {body}
                    </Link>
                  ) : (
                    <div className="py-2">{body}</div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        {unused.length > 0 && methods.length > 0 && (
          <p className="mt-1 text-[12px] leading-5 text-cx-text-3">
            Nothing {when} by {unused.join(', ')}.
          </p>
        )}
      </div>
    </div>
  );
}

/* --------------------------------------------------------------- attention */

export interface AttentionItem {
  id: string;
  tone: Tone;
  icon: LucideIcon;
  primary: string;
  secondary: string | null;
  count?: number;
  href: string | null;
}

const DECISION_LOOK: Record<string, { tone: Tone; icon: LucideIcon }> = {
  expenses_to_decide: { tone: 'amber', icon: CircleDollarSign },
  expenses_to_pay: { tone: 'blue', icon: Banknote },
  approvals: { tone: 'violet', icon: ShieldCheck },
  payroll_to_finalise: { tone: 'violet', icon: Landmark },
  payslips_to_pay: { tone: 'blue', icon: Banknote },
  messages_failed: { tone: 'rose', icon: AlertTriangle },
  long_waits: { tone: 'rose', icon: Hourglass },
  unassigned: { tone: 'amber', icon: Users },
  doctor_overload: { tone: 'rose', icon: Stethoscope },
  follow_ups_due: { tone: 'teal', icon: CalendarCheck2 },
  packages_expiring: { tone: 'violet', icon: Layers },
  reports_due: { tone: 'amber', icon: ReceiptText },
};

/** The decisions waiting, as the server lists them, in its order. */
export function decisionItems(decisions: DecisionsSection | undefined): AttentionItem[] {
  return (decisions?.items ?? []).map((item) => ({
    id: item.id,
    ...(DECISION_LOOK[item.id] ?? { tone: 'slate' as Tone, icon: AlertTriangle }),
    primary: item.primary,
    secondary: item.secondary,
    count: item.count,
    href: item.href,
  }));
}

export function AttentionList({ items }: { items: AttentionItem[] }) {
  if (items.length === 0) {
    return (
      <div className="flex items-center gap-3 rounded-md bg-cx-green-bg px-4 py-3.5 text-[13px] font-medium text-cx-green-ink">
        <BadgeCheck className="h-5 w-5" strokeWidth={1.75} aria-hidden="true" />
        Nothing needs your decision right now.
      </div>
    );
  }

  return (
    <ul className="-mx-2 divide-y divide-cx-line">
      {items.map((item) => {
        // Each row in its kind's colour, as a soft tint, so the card's own amber tile leads; only rose rows are urgent.
        const urgent = item.tone === 'rose';
        const inner = (
          <>
            <IconChip icon={item.icon} tone={item.tone} size="sm" variant="soft" />
            <span className="min-w-0 flex-1">
              <span className="block text-[13.5px] font-medium leading-5 text-cx-nav-ink">{item.primary}</span>
              {item.secondary && <span className="block text-[12px] leading-4 text-cx-text-3">{item.secondary}</span>}
            </span>
            {item.count !== undefined && (
              <span
                className={cn(
                  'grid min-w-[28px] place-items-center rounded-full px-2 py-0.5 text-[12px] font-semibold tabular-nums',
                  TONES[item.tone].soft,
                  TONES[item.tone].ink,
                  urgent && 'ring-1 ring-inset ring-cx-rose-ink/20',
                )}
              >
                {formatNumber(item.count)}
              </span>
            )}
            {item.href && <ChevronRight className="h-4 w-4 shrink-0 text-cx-text-3 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />}
          </>
        );

        return (
          <li key={item.id}>
            {item.href ? (
              <Link href={item.href} className={cn('group flex min-h-[56px] items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-cx-nav-canvas', FOCUS)}>
                {inner}
              </Link>
            ) : (
              <div className="flex min-h-[56px] items-center gap-3 px-2 py-2">{inner}</div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

/**
 * The owner's expenses at a glance, from the same figures as the Expenses
 * list: what was spent this month (approved and paid, dated this month, the
 * list's own default view) and how much of it is paid, the categories it went
 * on, then what still waits on someone, however old: to decide (pending) and
 * approved but not yet paid. Each figure opens exactly its list. An empty
 * month or bucket says so quietly instead of showing a large zero.
 */
export function SpendingPair({ spending }: { spending: SpendingSection }) {
  const c = spending.currency;
  const month = spending.month;
  const top = month?.categories ?? [];
  const waiting = [
    { key: 'to_decide', label: 'To decide', hint: 'Waiting for approval', tone: 'amber' as Tone, bucket: spending.to_decide },
    { key: 'to_pay', label: 'To pay', hint: 'Approved, not yet paid', tone: 'blue' as Tone, bucket: spending.to_pay },
  ];

  const spent = month && (
    <>
      <span className="block text-[12.5px] font-medium text-cx-violet-ink">Spent this month</span>
      {month.spent > 0 ? (
        <>
          <span className="mt-1 block text-[22px] font-semibold leading-7 tracking-[-0.02em] text-cx-nav-ink tabular-nums">{formatMoney(month.spent, c)}</span>
          <span className="mt-0.5 block text-[12px] text-cx-text-2 tabular-nums">
            {month.paid >= month.spent ? 'All paid' : `${formatMoney(month.paid, c)} paid · ${formatMoney(month.spent - month.paid, c)} approved, unpaid`}
          </span>
        </>
      ) : (
        <span className="mt-1 block text-[13px] text-cx-text-2">Nothing approved or paid yet this month.</span>
      )}
    </>
  );

  return (
    <div className="space-y-4">
      {month &&
        (month.href ? (
          <Link
            href={month.href}
            aria-label={`Spent this month: ${formatMoney(month.spent, c)}. Open this month's expenses`}
            className={cn('group relative block rounded-md bg-gradient-to-br from-[#F4F0FF] to-[#EAF1FF] py-3.5 pl-4 pr-10 transition-colors', FOCUS)}
          >
            {spent}
            <ChevronRight className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-text-3 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
          </Link>
        ) : (
          <div className="rounded-md bg-gradient-to-br from-[#F4F0FF] to-[#EAF1FF] px-4 py-3.5">{spent}</div>
        ))}

      {top.length > 0 && month && month.spent > 0 && (
        <ul className="space-y-2" aria-label="Largest categories this month">
          {top.map((category) => {
            const share = Math.round((category.total / month.spent) * 100);

            return (
              <li key={category.name}>
                <span className="flex items-baseline justify-between gap-3 text-[12.5px]">
                  <span className="min-w-0 truncate text-cx-text-2">{category.name}</span>
                  <span className="shrink-0 font-medium text-cx-nav-ink tabular-nums">{formatMoney(category.total, c)}</span>
                </span>
                <span className="mt-1 block h-1 overflow-hidden rounded-full bg-cx-nav-canvas" aria-hidden="true">
                  <span className="block h-full rounded-full bg-[#6B48DE]" style={{ width: `${share}%` }} />
                </span>
              </li>
            );
          })}
        </ul>
      )}

      <div>
        <h3 className="text-[12px] font-medium text-cx-text-3">Waiting on someone · all dates</h3>
        <ul className="mt-1 divide-y divide-cx-line">
          {waiting.map((row) => {
            const has = row.bucket.count > 0;
            const body = (
              <>
                <span className={cn('h-2 w-2 shrink-0 rounded-full', has ? '' : 'bg-cx-line-strong')} style={has ? { background: TONES[row.tone].stroke } : undefined} aria-hidden="true" />
                <span className="min-w-0 flex-1">
                  <span className={cn('block text-[13px] font-medium leading-5', has ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{row.label}</span>
                  <span className="block text-[12px] leading-4 text-cx-text-3">{row.hint}</span>
                </span>
                {has ? (
                  <span className="shrink-0 text-right">
                    <span className="block text-[13px] font-semibold text-cx-nav-ink tabular-nums">{formatMoney(row.bucket.amount, c)}</span>
                    <span className="block text-[12px] text-cx-text-3 tabular-nums">
                      {formatNumber(row.bucket.count)} {row.bucket.count === 1 ? 'expense' : 'expenses'}
                    </span>
                  </span>
                ) : (
                  <span className="shrink-0 text-[12px] text-cx-text-3">None</span>
                )}
                {row.bucket.href && has && <ChevronRight className="h-4 w-4 shrink-0 text-cx-text-3 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />}
              </>
            );

            return (
              <li key={row.key}>
                {row.bucket.href && has ? (
                  <Link
                    href={row.bucket.href}
                    aria-label={`${row.label}: ${row.bucket.count} expenses, ${formatMoney(row.bucket.amount, c)}. Open the list`}
                    className={cn('group -mx-2 flex min-h-[52px] items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-cx-nav-canvas', FOCUS)}
                  >
                    {body}
                  </Link>
                ) : (
                  <div className="flex min-h-[52px] items-center gap-3 py-2">{body}</div>
                )}
              </li>
            );
          })}
        </ul>
      </div>
    </div>
  );
}

/* --------------------------------------------------------------- operations */

const FLOW: Record<string, { tone: Tone; icon: LucideIcon }> = {
  appointments_today: { tone: 'blue', icon: CalendarCheck2 },
  patients_today: { tone: 'teal', icon: Users },
  waiting_now: { tone: 'amber', icon: Hourglass },
  with_doctor_now: { tone: 'violet', icon: Stethoscope },
};

/**
 * A compact coloured figure: a soft pastel wash in its accent, the label with
 * its line icon in that colour, and the count. Used for the clinic's day and
 * the workforce, four to a row on a tablet and up, two on a phone.
 */
function ColourStat({ tone, icon: Icon, label, value, href, linkLabel }: { tone: Tone; icon: LucideIcon; label: string; value: number; href?: string | null; linkLabel?: string }) {
  const tile = cn('block h-full rounded-md bg-gradient-to-br px-4 py-3.5', TONES[tone].wash);
  const body = (
    <>
      <span className={cn('flex items-center gap-1.5 text-[12.5px] font-medium', TONES[tone].ink)}>
        <Icon className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden="true" />
        <span className="min-w-0">{label}</span>
      </span>
      <span className="mt-1.5 block text-[20px] font-semibold leading-6 text-cx-nav-ink tabular-nums">{formatNumber(value)}</span>
    </>
  );

  return href ? (
    <Link href={href} aria-label={linkLabel} className={cn(tile, 'transition-transform duration-200 hover:-translate-y-0.5 motion-reduce:hover:translate-y-0', FOCUS)}>
      {body}
    </Link>
  ) : (
    <div className={tile}>{body}</div>
  );
}

/**
 * Four coloured figures to a row from a tablet up, two on a phone. `beside`:
 * the card shares its row with another, so in the narrower 1024 column it
 * keeps two to a row rather than squeezing four.
 */
const STAT_GRID = (beside: boolean) => cn('grid grid-cols-2 gap-2.5 sm:grid-cols-4', beside && 'lg:grid-cols-2 xl:grid-cols-4');

/** Today's live counts at the clinic: what the front office and doctors are working through now. */
export function OperationsToday({ figures, beside = false }: { figures: Figure[]; beside?: boolean }) {
  return (
    <ul className={STAT_GRID(beside)}>
      {figures.map((figure) => {
        const look = FLOW[figure.key] ?? { tone: 'slate' as Tone, icon: Users };

        return (
          <li key={figure.key} className="min-w-0">
            <ColourStat tone={look.tone} icon={look.icon} label={figure.label} value={figure.value} href={figure.href} linkLabel={`${figure.label}: ${figure.value}. Open the calendar`} />
          </li>
        );
      })}
    </ul>
  );
}

/** The owner's own patients, for an owner who also treats: today's figures and the queue waiting for them. */
export function MyDay({ mine }: { mine: MineSection }) {
  return (
    <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
      {mine.figures.map((figure) => (
        <p key={figure.key} className="text-[13.5px] text-cx-text-2">
          <b className="mr-1 text-[18px] font-semibold text-cx-nav-ink tabular-nums">{formatNumber(figure.value)}</b>
          {figure.label.toLowerCase()}
        </p>
      ))}
      {mine.today_href && (
        <Go href={mine.today_href} label="Open your Today" className="ml-auto">
          Open Today
        </Go>
      )}
    </div>
  );
}

/* ----------------------------------------------------------------- branches */

/** Each branch's day, compared on one scale per measure. */
export function BranchComparison({ branches }: { branches: BranchesSection }) {
  const measures = branches.rows[0]?.values.map((value) => value.label) ?? [];
  const max = (label: string) => Math.max(1, ...branches.rows.map((row) => row.values.find((value) => value.label === label)?.value ?? 0));
  const tones: Tone[] = ['blue', 'teal', 'violet', 'slate'];

  return (
    <div>
      <p className="flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-cx-text-2">
        {measures.map((label, index) => (
          <span key={label} className="inline-flex items-center gap-1.5">
            <span className="h-2 w-3.5 rounded-full" style={{ background: TONES[tones[index % tones.length]].stroke }} aria-hidden="true" />
            {label}
          </span>
        ))}
      </p>
      <ul className="mt-3 divide-y divide-cx-line">
        {branches.rows.map((row) => (
          // The branch above its bars at every width: the card can sit in a narrow column, where a label column beside
          // the bars left them no room.
          <li key={row.id} className="grid gap-2 py-3">
            <span className="flex min-w-0 items-center gap-2 text-[13.5px] font-medium text-cx-nav-ink">
              <Building2 className="h-4 w-4 shrink-0 text-cx-text-3" strokeWidth={1.75} aria-hidden="true" />
              <span className="min-w-0 break-words">{row.label}</span>
            </span>
            <span className="grid gap-1.5">
              {row.values.map((value, index) => (
                <span key={value.label} className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                  <span className="h-2 overflow-hidden rounded-full bg-cx-nav-canvas" aria-hidden="true">
                    <span
                      className="block h-full rounded-full transition-[width] duration-700 motion-reduce:transition-none"
                      style={{ width: `${(value.value / max(value.label)) * 100}%`, background: TONES[tones[index % tones.length]].stroke }}
                    />
                  </span>
                  <span className="min-w-[72px] text-right text-[12.5px] text-cx-text-2 tabular-nums">
                    <span className="sr-only">{value.label}: </span>
                    {value.format === 'currency' ? formatMoney(value.value, branches.currency) : formatNumber(value.value)}
                  </span>
                </span>
              ))}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

/* ---------------------------------------------------------------- workforce */

function SeatMeter({ label, used, allowed }: { label: string; used: number; allowed: number | null }) {
  const pct = allowed ? Math.min(100, Math.round((used / allowed) * 100)) : 0;
  const tone: Tone = allowed === null ? 'slate' : used >= allowed ? 'rose' : used / allowed >= 0.85 ? 'amber' : 'teal';

  return (
    <li className="py-2.5">
      <div className="flex items-baseline justify-between gap-3 text-[13px]">
        <span className="text-cx-text-2">{label}</span>
        <span className="text-cx-text-2 tabular-nums">
          <b className="font-semibold text-cx-nav-ink">{formatNumber(used)}</b>
          {` of ${formatNumber(allowed ?? 0)}`}
        </span>
      </div>
      <span className="mt-2 block h-1.5 overflow-hidden rounded-full bg-cx-nav-canvas" role="img" aria-label={`${label}: ${used} of ${allowed}`}>
        <span className="block h-full rounded-full transition-[width] duration-700 motion-reduce:transition-none" style={{ width: `${pct}%`, background: TONES[tone].stroke }} />
      </span>
    </li>
  );
}

/**
 * Who works here and how many logins the plan allows, counted as the plan
 * enforces them. Seats with a limit get a meter each; the rest are chips
 * with their count. Without a plan on record nothing here says "no limit":
 * the Plan card beside it already explains that, once.
 */
export function Workforce({ people, beside = false }: { people: PeopleSection; beside?: boolean }) {
  const w = people.workforce;
  const stats = [
    { label: 'Active staff', value: w.staff, tone: 'blue' as Tone, icon: Users },
    { label: 'Practitioners', value: w.practitioners, tone: 'violet' as Tone, icon: Stethoscope },
    { label: 'On leave today', value: people.on_leave_today, tone: 'teal' as Tone, icon: CalendarCheck2 },
    { label: 'Without a login', value: people.without_login, tone: 'slate' as Tone, icon: KeyRound },
  ];
  const limited = people.seats.filter((seat) => seat.allowed !== null);
  const unlimited = people.seats.filter((seat) => seat.allowed === null);
  const planRecorded = people.subscription !== null && people.subscription.state !== 'none';
  const chip = 'inline-flex items-center gap-1.5 rounded-full bg-cx-nav-canvas px-3 py-1 text-[12.5px] text-cx-text-2';

  return (
    <div className="space-y-4">
      <ul className={STAT_GRID(beside)}>
        {stats.map((stat) => (
          <li key={stat.label} className="min-w-0">
            <ColourStat tone={stat.tone} icon={stat.icon} label={stat.label} value={stat.value} />
          </li>
        ))}
      </ul>
      <div className="border-t border-cx-line pt-4">
        <h3 className="text-[12.5px] font-medium text-cx-text-2">{planRecorded ? 'Logins against the plan' : 'Logins in use'}</h3>
        {limited.length > 0 && (
          <ul className="divide-y divide-cx-line">
            {limited.map((seat) => (
              <SeatMeter key={seat.key} label={seat.label} used={seat.used} allowed={seat.allowed} />
            ))}
          </ul>
        )}
        {unlimited.length > 0 && (
          <ul className="mt-2 flex flex-wrap gap-2">
            {unlimited.map((seat) => (
              <li key={seat.key} className={chip}>
                {seat.label}
                <b className="font-semibold text-cx-nav-ink tabular-nums">{formatNumber(seat.used)}</b>
                {planRecorded && <span className="text-cx-text-3">· no limit</span>}
              </li>
            ))}
          </ul>
        )}
        <p className="mt-3 text-[12px] text-cx-text-3">
          Branch access: {formatNumber(w.all_branches)} with every branch, {formatNumber(w.some_branches)} with some
        </p>
      </div>
    </div>
  );
}

/* --------------------------------------------------------------------- plan */

const PLAN_STATE: Record<NonNullable<PeopleSection['subscription']>['state'], { label: string; tone: Tone }> = {
  active: { label: 'Active', tone: 'mint' },
  trial: { label: 'Trial', tone: 'blue' },
  grace: { label: 'Grace period', tone: 'amber' },
  lapsed: { label: 'Lapsed', tone: 'rose' },
  none: { label: 'Not recorded', tone: 'amber' },
};

function longDate(iso: string): string {
  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${iso}T00:00:00`));
}

/** The clinic's plan as OralCares records it, and what it may use. Read-only: OralCares manages subscriptions. */
export function PlanModules({ people }: { people: PeopleSection }) {
  const subscription = people.subscription;
  const state = PLAN_STATE[subscription?.state ?? 'none'];
  const ends = subscription?.ends_on ? longDate(subscription.ends_on) : null;
  const until = subscription?.state === 'trial' ? 'Trial ends' : subscription?.state === 'grace' ? 'Grace ends' : 'Current term ends';

  return (
    <div className="space-y-4">
      {/* The plan on a wash in its state's colour: green active, blue trial, amber grace or not recorded, rose lapsed. */}
      <div className={cn('rounded-md bg-gradient-to-br p-4', TONES[state.tone].wash)}>
        <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
          <p className="min-w-0 text-[15px] font-semibold leading-5 text-cx-nav-ink">{subscription?.plan ?? (subscription?.state === 'none' || !subscription ? 'No plan on record' : 'Plan')}</p>
          <span className={cn('shrink-0 rounded-full bg-white/80 px-2 py-0.5 text-[12px] font-medium', TONES[state.tone].ink)}>{state.label}</span>
        </div>
        <p className="mt-1 text-[12.5px] leading-5 text-cx-text-2">
          {subscription && subscription.state !== 'none'
            ? ends
              ? `${until} ${ends}${subscription.days_remaining !== null ? ` · ${subscription.days_remaining} days left` : ''}`
              : 'Open-ended term'
            : 'Seat limits cannot be checked until OralCares records your plan.'}
        </p>
      </div>
      <div>
        <h3 className="text-[12px] font-medium text-cx-text-3">Specialties practised</h3>
        <ul className="mt-2 flex flex-wrap gap-1.5">
          {people.specialties.length === 0 ? (
            <li className="text-[12.5px] text-cx-text-3">None recorded</li>
          ) : (
            people.specialties.map((specialty) => (
              <li key={specialty.key} className="rounded-full bg-cx-violet-bg px-2.5 py-0.5 text-[12.5px] font-medium text-cx-violet-ink">
                {specialty.label}
              </li>
            ))
          )}
        </ul>
      </div>
      <div>
        <h3 className="text-[12px] font-medium text-cx-text-3">Modules on</h3>
        <ul className="mt-2 flex flex-wrap gap-1.5">
          {people.modules.length === 0 ? (
            <li className="text-[12.5px] text-cx-text-3">None</li>
          ) : (
            people.modules.map((module) => (
              <li key={module.key} className="rounded-full bg-cx-teal-bg px-2.5 py-0.5 text-[12.5px] font-medium text-cx-teal-ink">
                {module.label}
              </li>
            ))
          )}
        </ul>
      </div>
    </div>
  );
}

/* -------------------------------------------------------------------- trails */

/** Each audit event family's icon and calm colour; an alert row is always rose, whatever its family. */
const TRAIL_LOOK: Record<string, { icon: LucideIcon; tone: Tone }> = {
  billing: { icon: ReceiptText, tone: 'blue' },
  expense: { icon: CircleDollarSign, tone: 'violet' },
  payroll: { icon: Landmark, tone: 'violet' },
  access: { icon: KeyRound, tone: 'teal' },
  deletion: { icon: Layers, tone: 'slate' },
  owner_mode: { icon: ShieldCheck, tone: 'teal' },
  auth: { icon: KeyRound, tone: 'slate' },
};

/**
 * A short list from the audit trail, newest first: the event's icon in its
 * family's colour (rose only when the row is an alert), what happened, and
 * one quiet line beneath with who did it and when, so a long timestamp never
 * crowds the event. `status` heads the list with one line on the period as a
 * whole (Security's failed checks). When `total` counts the trail's rows
 * (Recent activity), a footer says the list shows only the latest of them;
 * Security's `total` counts failures only, so it passes `totalCountsRows`
 * false and gets no such footer.
 */
export function Timeline({ trail, empty, status, totalCountsRows = true }: { trail: TrailSection; empty: string; status?: { tone: 'mint' | 'rose'; text: string }; totalCountsRows?: boolean }) {
  const shown = trail.items.length;

  return (
    <div className="space-y-3">
      {status && (
        <p className={cn('flex items-center gap-2 rounded-md px-3 py-2 text-[12.5px] font-medium', status.tone === 'rose' ? 'bg-cx-rose-bg text-cx-rose-ink' : 'bg-cx-green-bg text-cx-green-ink')}>
          {status.tone === 'rose' ? (
            <ShieldAlert className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden="true" />
          ) : (
            <BadgeCheck className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden="true" />
          )}
          {status.text}
        </p>
      )}
      {shown === 0 ? (
        <p className="rounded-md bg-cx-nav-canvas/70 px-3 py-3 text-[13px] text-cx-text-2">{empty}</p>
      ) : (
        <ol className="divide-y divide-cx-line">
          {trail.items.map((item) => {
            const look = item.status === 'alert' ? { icon: ShieldAlert, tone: 'rose' as Tone } : (TRAIL_LOOK[item.kind ?? ''] ?? { icon: ReceiptText, tone: 'slate' as Tone });

            return (
              <li key={item.id} className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                <IconChip icon={look.icon} tone={look.tone} size="sm" variant="soft" />
                <span className="min-w-0 flex-1 pt-px">
                  <span className={cn('block text-[13.5px] font-medium leading-5', item.status === 'alert' ? 'text-cx-rose-ink' : 'text-cx-nav-ink')}>{item.primary}</span>
                  <span className="mt-0.5 block text-[12px] leading-4 text-cx-text-3">
                    {item.secondary && <span>{item.secondary} · </span>}
                    <time className="tabular-nums">{item.time}</time>
                  </span>
                </span>
              </li>
            );
          })}
        </ol>
      )}
      {totalCountsRows && trail.total > shown && shown > 0 && (
        <p className="text-[12px] text-cx-text-3">
          Latest {formatNumber(shown)} of {formatNumber(trail.total)}
        </p>
      )}
    </div>
  );
}

/* ----------------------------------------------------------------- welcome */

/** The greeting for the hour on this device's clock. */
function greetingFor(hour: number): string {
  return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
}

/**
 * The Command Center's opening card: the clinic it covers, the greeting and
 * the owner's name, and one line on what waits for them — the count of the
 * attention list below, nothing invented. The date and time stay the top
 * bar's, the branch stays in each section's caption, and the money stays in
 * the cards, so none of them is repeated here.
 *
 * Its look is layered, not loud: a cyan-to-violet wash, two soft glows, a
 * gradient edge, and a quiet line drawing (rings and a pulse trace) filling
 * the right side, all decorative and hidden from assistive technology. The
 * greeting follows the hour as the page stays open.
 */
export function Welcome({
  name,
  clinicName,
  waiting = null,
  line,
  actions,
}: {
  name: string | null;
  clinicName: string | null;
  waiting?: number | null;
  /** A page's own status line in place of the attention count (the Front Desk's day). */
  line?: ReactNode;
  /** The page's main actions, beside the greeting from a tablet up (the Front Desk's New case, Repeat case, Book). */
  actions?: ReactNode;
}) {
  const [hour, setHour] = useState(() => new Date().getHours());
  useEffect(() => {
    const timer = window.setInterval(() => setHour(new Date().getHours()), 60_000);

    return () => window.clearInterval(timer);
  }, []);
  const first = name?.trim().split(/\s+/)[0] ?? null;
  const art = useId().replace(/:/g, '');

  return (
    <header className={cn('relative isolate overflow-hidden rounded-2xl border border-white/80 shadow-cx-card', rise(0).className)}>
      {/* The layers: the wash, a teal glow top right, a violet glow below it, and the gradient edge on the left. */}
      <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_50%,#EFE9FE_100%)]" aria-hidden="true" />
      <div className="absolute -right-20 -top-28 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(44,198,180,0.26),transparent_68%)]" aria-hidden="true" />
      <div className="absolute -bottom-36 right-24 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(107,72,222,0.18),transparent_68%)]" aria-hidden="true" />
      <span className="absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-[#2CC6B4] via-[#4F7FEA] to-[#8B6CF0]" aria-hidden="true" />

      <svg viewBox="0 0 420 180" className="pointer-events-none absolute -right-8 top-1/2 -z-10 hidden h-[180px] w-[420px] -translate-y-1/2 md:block" aria-hidden="true">
        <defs>
          <linearGradient id={`${art}-line`} x1="0" x2="1" y1="0" y2="0">
            <stop offset="0" stopColor="#2CC6B4" stopOpacity="0" />
            <stop offset="0.35" stopColor="#2CC6B4" />
            <stop offset="0.7" stopColor="#4F7FEA" />
            <stop offset="1" stopColor="#8B6CF0" />
          </linearGradient>
          <radialGradient id={`${art}-core`}>
            <stop offset="0" stopColor="#FFFFFF" stopOpacity="0.9" />
            <stop offset="1" stopColor="#FFFFFF" stopOpacity="0" />
          </radialGradient>
        </defs>
        <circle cx="318" cy="90" r="62" fill={`url(#${art}-core)`} />
        {[34, 58, 82, 106].map((radius, index) => (
          <circle key={radius} cx="318" cy="90" r={radius} fill="none" stroke={`url(#${art}-line)`} strokeOpacity={0.5 - index * 0.1} strokeWidth="1.25" />
        ))}
        <polyline
          points="40,98 170,98 188,98 200,64 214,128 228,78 240,98 300,98 312,90 324,98 420,98"
          fill="none"
          stroke={`url(#${art}-line)`}
          strokeOpacity="0.7"
          strokeWidth="1.75"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
        <circle cx="318" cy="90" r="5" fill="#4F7FEA" fillOpacity="0.85" />
        <circle cx="376" cy="40" r="3" fill="#8B6CF0" fillOpacity="0.6" />
        <circle cx="250" cy="150" r="2.5" fill="#2CC6B4" fillOpacity="0.7" />
      </svg>

      <div className={cn('relative px-6 py-5 sm:px-8 sm:py-6', actions ? 'flex flex-col gap-4 md:flex-row md:items-center md:justify-between' : 'max-w-[640px]')}>
        <div className="min-w-0">
        {clinicName && (
          <p className="inline-flex max-w-full items-center gap-1.5 rounded-full bg-white/75 px-3 py-1 text-[12.5px] font-medium text-cx-teal-ink shadow-[inset_0_0_0_1px_rgba(14,163,148,0.16)]">
            <Building2 className="h-3.5 w-3.5 shrink-0" strokeWidth={1.75} aria-hidden="true" />
            <span className="truncate">{clinicName}</span>
          </p>
        )}
        <h1 className={cn('text-[25px] font-medium leading-[1.2] tracking-[-0.02em] text-[#2B3A55] sm:text-[28px]', clinicName && 'mt-2.5')}>
          {greetingFor(hour)}
          {first ? (
            <>
              , <span className="bg-gradient-to-r from-[#0B8478] via-[#2B5FD0] to-[#6B48DE] bg-clip-text font-semibold text-transparent">{first}</span>
            </>
          ) : null}
        </h1>
        <p className="mt-1.5 flex flex-wrap items-center gap-x-2 text-[14px] leading-6 text-cx-text-2">
          {line !== undefined ? (
            line
          ) : waiting === null ? (
            'Your clinic at a glance, as it runs today.'
          ) : waiting === 0 ? (
            <>
              <span className="h-2 w-2 rounded-full bg-cx-green-ink/70" aria-hidden="true" />
              Nothing needs your decision right now.
            </>
          ) : (
            <>
              <span className="h-2 w-2 rounded-full bg-[#E9821A]" aria-hidden="true" />
              {waiting} {waiting === 1 ? 'item needs' : 'items need'} your attention.
              <a href="#attention-title" className={cn('-my-2.5 inline-flex min-h-11 items-center rounded-md font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
                Review
              </a>
            </>
          )}
        </p>
        </div>
        {actions && <div className="flex shrink-0 flex-wrap items-center gap-2.5">{actions}</div>}
      </div>
    </header>
  );
}
