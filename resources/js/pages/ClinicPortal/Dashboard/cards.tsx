import type { CSSProperties, ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ChevronRight, MoreHorizontal } from 'lucide-react';
import { cn } from '@/lib/utils';
import { cancelLiveRefresh } from '@/clinic/live';
import type { DashboardAction, DashboardWidget, KpiData } from '@/clinic/types';
import { disc, PILL, solidDisc, SURFACE, tone } from '@/clinic/ui/hues';
import { personInitials } from '@/clinic/ui/PersonAvatar';
import { formatMoney, formatNumber, humanize, iconFor } from '@/clinic/ui/visuals';
import type { AppointmentsData, AttentionItem, DuesData, FollowUpRow, PatientRow, PatientsData } from './types';

/*
 * The dashboard's cards, in the Company CRM's treatment (values in
 * `@/clinic/ui/hues`): white cards on the workspace with the CRM's hairline,
 * a restrained shadow and consistent radii (24px panels, 20px figures, 16px
 * tiles). Colour is an accent only: icon discs, a figure's thin leading edge,
 * an action's edge on hover, status pills. One spacing rhythm: 16px between
 * cards, 20px inside panels, 12px under a panel heading.
 *
 * Icon sizes, by purpose:
 *   standard   16px glyph in a 36px disc   figure cards, New Case, Repeat Case
 *   compact    14px glyph in a 28px disc   panel headings, compact action rows
 *   inline     14px chevrons; 16px row menu (three dots read too thin smaller)
 *   dots        6px                        status pills
 */

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#12A0B5] focus-visible:ring-offset-2';

/* ---------------------------------------------------------------- shared */

/**
 * A section card: the CRM's white panel. Its heading line carries a compact
 * icon in the section's colour, the title, and on the right whatever the
 * section summarises there (counts) and its one link onward. On a narrow
 * screen the right-hand part wraps under the title, never over it.
 */
export function Panel({ title, icon, tone = 'slate', count, aside, action, children, className, labelledBy }: {
  title: ReactNode;
  icon?: string;
  tone?: string;
  /** How many there are in all, beside the title (lists that show only the first few). */
  count?: number | null;
  /** The heading line's right-hand summary. */
  aside?: ReactNode;
  action?: { label: string; href: string } | null;
  children: ReactNode;
  className?: string;
  labelledBy: string;
}) {
  const Icon = icon ? iconFor(icon) : null;

  return (
    <section aria-labelledby={labelledBy} className={cn('flex min-w-0 flex-col p-5', SURFACE.card, className)}>
      <header className="mb-3 flex min-h-7 flex-wrap items-center justify-between gap-x-3 gap-y-2">
        <h2 id={labelledBy} className={cn('flex min-w-0 items-center gap-2.5 text-[15px] font-semibold tracking-[-0.01em]', SURFACE.primary)}>
          {Icon && (
            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full" style={disc(tone)} aria-hidden="true">
              <Icon className="h-3.5 w-3.5" strokeWidth={2} />
            </span>
          )}
          <span className="truncate">{title}</span>
          {count !== null && count !== undefined && count > 0 && (
            <span className={cn('shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-medium tabular-nums', SURFACE.inset, SURFACE.secondary)}>{count}</span>
          )}
        </h2>
        {(aside || action) && (
          <div className="flex min-w-0 flex-wrap items-center gap-2">
            {aside}
            {action && (
              <Link href={action.href} className={cn('inline-flex shrink-0 items-center gap-0.5 rounded-full px-1 text-[12.5px] font-medium text-[#0A6473] hover:underline', FOCUS)}>
                {action.label}
                <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
              </Link>
            )}
          </div>
        )}
      </header>
      <div className="min-w-0 flex-1">{children}</div>
    </section>
  );
}

export function Skeleton({ rows = 4 }: { rows?: number }) {
  return (
    <div className="space-y-2" aria-hidden="true">
      {Array.from({ length: rows }).map((_, index) => (
        <div key={index} className={cn('h-10 animate-pulse rounded-[12px]', SURFACE.inset)} />
      ))}
    </div>
  );
}

export function Failed() {
  return <p className={cn('rounded-[12px] px-3 py-2.5 text-[12.5px] font-medium', PILL.bad.pill)}>This could not be loaded. Refresh to try again.</p>;
}

/** A quiet line where a list has nothing in it: text only, no box. */
export function Empty({ children }: { children: ReactNode }) {
  return <p className={cn('py-1 text-[13px]', SURFACE.muted)}>{children}</p>;
}

/** Initials in a disc, its hue taken from the name so a person keeps one colour. */
const AVATAR_TONES = ['brand', 'blue', 'violet', 'amber', 'green'];

export function Avatar({ name }: { name: string }) {
  const tone = AVATAR_TONES[[...name].reduce((sum, char) => sum + char.charCodeAt(0), 0) % AVATAR_TONES.length];

  return (
    <span aria-hidden="true" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold" style={disc(tone)}>
      {personInitials(name)}
    </span>
  );
}

/** One meaning per status, in the CRM's pill: soft fill, dot, medium label. */
const STATUS: Record<string, { label: string; tone: keyof typeof PILL }> = {
  waiting: { label: 'Waiting', tone: 'warn' },
  called: { label: 'Called', tone: 'info' },
  in_consultation: { label: 'With doctor', tone: 'violet' },
  completed: { label: 'Done', tone: 'good' },
  scheduled: { label: 'Not arrived', tone: 'neutral' },
  confirmed: { label: 'Confirmed', tone: 'info' },
  late: { label: 'Late', tone: 'bad' },
  unpaid: { label: 'Unpaid', tone: 'bad' },
  partially_paid: { label: 'Partially paid', tone: 'warn' },
};

export function StatusChip({ status }: { status: string }) {
  const style = STATUS[status] ?? { label: humanize(status), tone: 'neutral' as const };

  return (
    <span className={cn('inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11.5px] font-medium', PILL[style.tone].pill)}>
      <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', PILL[style.tone].dot)} aria-hidden="true" />
      {style.label}
    </span>
  );
}

/* ---------------------------------------------------------- figure cards */

/**
 * One of the day's figures: a white card, its meaning carried by a tinted
 * icon disc and a thin leading accent, and the label with its value directly
 * beneath it as one group. It fills its place in the 2×2 grid, so the four
 * are the same size and line up with the Quick Actions card. Nothing is
 * computed here.
 */
export function FigureCard({ widget, data }: { widget: DashboardWidget; data: KpiData | { error: true } | undefined }) {
  const Icon = iconFor(widget.icon);
  const kpi = data && !('error' in data) ? data : null;

  return (
    <div className={cn('relative flex h-full min-w-0 items-center gap-3 overflow-hidden rounded-[20px] px-4 py-3', SURFACE.tile)}>
      {/* The metric's colour as a thin accent on the leading edge; the card itself stays white. */}
      <span className="absolute inset-y-3 left-0 w-[3px] rounded-r-full" style={{ backgroundColor: tone(widget.tone).solid }} aria-hidden="true" />
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style={disc(widget.tone)}>
        <Icon className="h-4 w-4" strokeWidth={2} aria-hidden="true" />
      </span>
      <div className="min-w-0 flex-1">
        <p className={cn('text-[12.5px] font-medium leading-tight', SURFACE.secondary)}>{widget.title}</p>
        {data === undefined ? (
          <span className="mt-1 block h-6 w-14 animate-pulse rounded-md bg-white/70" aria-hidden="true" />
        ) : kpi ? (
          <p className={cn('mt-0.5 truncate text-[24px] font-semibold leading-tight tracking-[-0.02em] tabular-nums', SURFACE.primary)}>
            {kpi.format === 'currency' ? formatMoney(kpi.value, kpi.currency) : formatNumber(kpi.value)}
          </p>
        ) : (
          <p className="mt-0.5 text-[12.5px] font-medium text-[#A32E17]">Could not be loaded</p>
        )}
        {kpi?.detail && <p className={cn('line-clamp-2 text-[11.5px] leading-snug', SURFACE.secondary)}>{kpi.detail}</p>}
      </div>
    </div>
  );
}

/* --------------------------------------------------------- quick actions */

/**
 * One quick action: its icon and its name, nothing else, on a white tile.
 * `large` tiles are the day's main pair: 64px tall, a solid icon disc in the
 * action's colour beside the name, two to a row at equal width. A `row` tile
 * is a compact 48px full-width row: a tinted disc, the name and a chevron.
 * The action's colour edges the tile on hover. An action either opens the
 * intake in place or links to its page; the server only sends ones that work.
 */
export function ActionTile({ action, onIntake }: { action: DashboardAction; onIntake: (mode: 'new' | 'repeat') => void }) {
  const Icon = iconFor(action.icon);
  const large = action.tile === 'large';
  // White tiles; the action's colour is its icon disc and, on hover, its edge.
  const className = cn(
    'group flex w-full min-w-0 items-center text-left motion-safe:transition',
    SURFACE.primary,
    SURFACE.tile,
    FOCUS,
    'hover:border-[var(--action)]',
    large
      ? cn('h-16 gap-2.5 rounded-[16px] px-3 hover:-translate-y-px', SURFACE.lift)
      : 'h-12 gap-3 rounded-[14px] px-3 hover:bg-[#F8F9FB]',
  );
  const body = large ? (
    <>
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full" style={solidDisc(action.tone)}>
        <Icon className="h-4 w-4" strokeWidth={2} aria-hidden="true" />
      </span>
      <span className="line-clamp-2 min-w-0 text-[14.5px] font-semibold leading-tight tracking-[-0.01em]">{action.label}</span>
    </>
  ) : (
    <>
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full" style={disc(action.tone)}>
        <Icon className="h-3.5 w-3.5" strokeWidth={2} aria-hidden="true" />
      </span>
      <span className="min-w-0 flex-1 truncate text-[13.5px] font-medium">{action.label}</span>
      <ChevronRight className={cn('h-3.5 w-3.5 shrink-0 transition group-hover:translate-x-0.5', SURFACE.muted)} aria-hidden="true" />
    </>
  );
  const style = { '--action': tone(action.tone).solid } as CSSProperties;

  if (action.intake) {
    const mode = action.intake;

    return (
      <button type="button" onClick={() => onIntake(mode)} className={className} style={style}>
        {body}
      </button>
    );
  }

  return action.href ? (
    <Link href={action.href} className={className} style={style}>
      {body}
    </Link>
  ) : null;
}

/* ------------------------------------------------------------ fixed previews */

/**
 * The previews' sizes. Today's patients and Appointments sit side by side
 * (60/40), so they share one content height and their panels line up:
 * patients in compact 44px rows, six in view and the rest of the server's
 * `limit` (8) a short scroll away; appointments in roomier 66px rows, all
 * four (`limit`) in view. Pending payments shows all five of its 44px rows.
 * A panel keeps its size whether its list is empty, partly filled or full.
 * Where rows stack (a status under the name on a phone; a bill's details
 * under it below desktop width) an area is instead bounded by its capacity
 * in those taller rows and shrinks to what it holds.
 */
const ROW = { patient: 44, appointment: 66, appointmentStacked: 80, due: 44, dueStacked: 64 } as const;
const SIDE_BY_SIDE_HEIGHT = 264;

/**
 * A preview's list area. It scrolls only when its rows overflow it, and the
 * wheel (or a touch drag) passes on to the page whenever it has nothing to
 * scroll or reaches its end: the browser's own scroll chaining, with no
 * `overscroll-behavior: contain` to trap it and no wheel handler.
 */
function PreviewArea({ height, compactHeight = height, fixedFrom = 'md', children, label }: {
  /** The area's fixed height from `fixedFrom` up. */
  height: number;
  /** Below that, where rows stack, the most it may grow to before it scrolls. */
  compactHeight?: number;
  fixedFrom?: 'md' | 'lg';
  children: ReactNode;
  label: string;
}) {
  return (
    <div
      role="region"
      aria-label={label}
      className={cn(
        'overflow-y-auto overflow-x-hidden',
        fixedFrom === 'lg' ? 'max-h-[var(--preview-compact-h)] lg:h-[var(--preview-h)] lg:max-h-[var(--preview-h)]' : 'max-h-[var(--preview-compact-h)] md:h-[var(--preview-h)] md:max-h-[var(--preview-h)]',
      )}
      style={{ '--preview-h': `${height}px`, '--preview-compact-h': `${compactHeight}px` } as CSSProperties}
    >
      {children}
    </div>
  );
}

/**
 * The preview's footer, always one line so the panel's height never moves:
 * how many are shown of how many, and where the rest are (or that their page
 * is not built yet).
 */
export function PreviewLimit({ shown, total, noun, fullList, missing }: { shown: number; total: number; noun: string; fullList: { label: string; href: string } | null; missing: string }) {
  return (
    <p className={cn('mt-3 min-h-[18px] text-[12px] leading-[18px] md:h-[18px] md:truncate', SURFACE.muted)} title={total > shown && !fullList && missing ? `Showing ${shown} of ${total} ${noun}. ${missing}` : undefined}>
      {total > shown ? (
        <>
          Showing {shown} of {total} {noun}.{' '}
          {fullList ? (
            <Link href={fullList.href} className="font-medium text-[#0A6473] hover:underline">
              {fullList.label}
            </Link>
          ) : (
            missing
          )}
        </>
      ) : total > 0 ? (
        `${total === 1 ? '1' : `All ${total}`} ${noun}`
      ) : null}
    </p>
  );
}

/* -------------------------------------------------------- today's patients */

/**
 * The day's counts for the Today's patients heading line: one compact pill
 * per stage, in the same colours as the rows' statuses.
 */
export function PatientCounts({ data }: { data: PatientsData }) {
  const counts: { label: string; value: number; tone: keyof typeof PILL }[] = [
    { label: 'Waiting', value: data.counts.waiting, tone: 'warn' },
    { label: 'With doctor', value: data.counts.in_room, tone: 'violet' },
    { label: 'Done', value: data.counts.completed, tone: 'good' },
  ];

  return (
    <ul aria-label="Today so far" className="flex flex-wrap items-center gap-1.5">
      {counts.map((count) => (
        <li key={count.label} className={cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11.5px] font-medium', PILL[count.tone].pill)}>
          <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', PILL[count.tone].dot)} aria-hidden="true" />
          {count.label}
          <span className="font-semibold tabular-nums">{count.value}</span>
        </li>
      ))}
    </ul>
  );
}

/**
 * Today's patients, as the Front Desk sees them: up to `limit` (waiting in
 * queue order, with a doctor, then done) with their status, in an area that
 * keeps its size; the day's counts sit on the heading line (`PatientCounts`).
 */
export function PatientsPreview({ data, empty, fullList }: { data: PatientsData; empty: string; fullList: { label: string; href: string } | null }) {
  return (
    <div>
      <PreviewArea height={SIDE_BY_SIDE_HEIGHT} compactHeight={data.limit * ROW.patient} label="Today's patients, first few">
        {data.rows.length === 0 ? (
          <Empty>{empty}</Empty>
        ) : (
          <ul>
            {data.rows.map((row) => (
              <PatientItem key={row.id} row={row} />
            ))}
          </ul>
        )}
      </PreviewArea>
      <PreviewLimit shown={data.rows.length} total={data.total} noun="patients today" fullList={fullList} missing="Full list: Reception page (not built yet)." />
    </div>
  );
}

function PatientItem({ row }: { row: PatientRow }) {
  const name = row.patient?.name ?? 'Patient';
  const meta = [
    row.kind_label,
    row.doctor ?? 'Any doctor',
    row.wait_minutes !== null ? `${row.wait_minutes} min` : null,
  ].filter(Boolean).join(' · ');

  return (
    <li className="flex min-h-11 items-center gap-3 rounded-[12px] px-2 py-1 transition hover:bg-[#F5F6F9]">
      <Avatar name={name} />
      <span className="min-w-0 flex-1">
        <span className={cn('block truncate text-[13.5px] font-medium', SURFACE.primary)}>{name}</span>
        <span className={cn('line-clamp-2 text-[11.5px] leading-snug sm:line-clamp-1', SURFACE.muted)}>{meta}</span>
        {/* On a phone the status sits under the name, so the name keeps the row's width. */}
        <span className="mt-1 block sm:hidden">
          <StatusChip status={row.status} />
        </span>
      </span>
      <span className="hidden sm:block">
        <StatusChip status={row.status} />
      </span>
      {row.actions.length > 0 ? (
        <Menu.Root>
          <Menu.Trigger
            aria-label={`Actions for ${name}`}
            className={cn('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full hover:bg-white', SURFACE.secondary, FOCUS)}
          >
            <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
          </Menu.Trigger>
          <Menu.Portal>
            <Menu.Content align="end" sideOffset={6} className="z-50 min-w-[180px] rounded-[16px] bg-white p-1 shadow-[0_2px_6px_rgba(11,11,15,0.05),0_20px_44px_-20px_rgba(11,11,15,0.20)]">
              {row.actions.map((action) => (
                <Menu.Item
                  key={action.key}
                  onSelect={() => {
                    // The action's own redirect brings the new figures back; an older figures request must not land after it.
                    cancelLiveRefresh();
                    router.patch(action.url, { action: action.key }, { preserveScroll: true, only: ['widgetData', 'flash'] });
                  }}
                  className={cn('flex h-9 cursor-pointer items-center rounded-[12px] px-3 text-[13px] outline-none data-[highlighted]:bg-[#F5F6F9]', SURFACE.primary)}
                >
                  {action.label}
                </Menu.Item>
              ))}
            </Menu.Content>
          </Menu.Portal>
        </Menu.Root>
      ) : (
        <span className="h-7 w-7 shrink-0" aria-hidden="true" />
      )}
    </li>
  );
}

/* ------------------------------------------------- upcoming appointments */

/**
 * Today's bookings still to arrive, earliest first, up to `limit`, in an area
 * that keeps its size; a row opens its booking on the Calendar when the
 * server says it may.
 */
export function AppointmentList({ data, empty, fullList }: { data: AppointmentsData; empty: string; fullList: { label: string; href: string } | null }) {
  return (
    <div>
      <PreviewArea height={Math.max(SIDE_BY_SIDE_HEIGHT, data.limit * ROW.appointment)} compactHeight={data.limit * ROW.appointmentStacked} label="Appointments still to arrive, first few">
        {data.items.length === 0 ? (
          <Empty>{empty}</Empty>
        ) : (
          <ul>
            {data.items.map((row) => {
              const body = (
                <>
                  <span className={cn('whitespace-nowrap text-[12px] font-medium tabular-nums', SURFACE.secondary)}>{row.time}</span>
                  <span className="min-w-0">
                    <span className={cn('block truncate text-[13.5px] font-medium', SURFACE.primary)}>{row.primary}</span>
                    {row.secondary && <span className={cn('line-clamp-2 text-[11.5px] leading-snug sm:line-clamp-1', SURFACE.muted)}>{row.secondary}</span>}
                    <span className="mt-1 block sm:hidden">
                      <StatusChip status={row.status} />
                    </span>
                  </span>
                  <span className="hidden sm:block">
                    <StatusChip status={row.status} />
                  </span>
                </>
              );
              const grid = 'grid min-h-[66px] grid-cols-[60px_minmax(0,1fr)_auto] items-center gap-2.5 rounded-[12px] px-2 py-2';

              return (
                <li key={row.id}>
                  {row.href ? (
                    <Link href={row.href} className={cn(grid, 'transition hover:bg-[#F5F6F9]', FOCUS)}>
                      {body}
                    </Link>
                  ) : (
                    <div className={grid}>{body}</div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </PreviewArea>
      <PreviewLimit shown={data.items.length} total={data.total} noun="still to arrive" fullList={fullList} missing="" />
    </div>
  );
}

/* ---------------------------------------------------- today's pending payments */

// The column layout needs desktop width; on a tablet or phone a bill is a stacked row with its reference written out.
const DUES_GRID = 'lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1.3fr)_repeat(3,minmax(0,0.75fr))_116px]';

/**
 * What today's visits still owe: each bill's total, what has been paid and
 * the balance, up to `limit` bills in an area that keeps its size, with the
 * balance due and the count over every such bill. The figures are the
 * bill's own; nothing is computed here.
 */
export function DuesTable({ data, empty, fullList }: { data: DuesData; empty: string; fullList: { label: string; href: string } | null }) {
  const money = (value: number) => formatMoney(value, data.currency);

  return (
    <div>
      <div className="mb-3 min-h-[46px]">
        <p className={cn('text-[10px] font-bold uppercase tracking-[0.12em]', SURFACE.muted)}>Due today</p>
        <p className={cn('mt-1 text-[24px] font-light leading-none tracking-[-0.03em] tabular-nums', SURFACE.primary)}>
          {money(data.due)}
          <span className={cn('ml-2 text-[12px] font-normal tracking-normal', SURFACE.muted)}>on {data.total} {data.total === 1 ? 'bill' : 'bills'}</span>
        </p>
      </div>
      <div className={cn('hidden gap-3 border-b px-2 pb-2 text-[10px] font-bold uppercase tracking-[0.12em] lg:grid', DUES_GRID, SURFACE.line, SURFACE.muted)}>
        <span>Patient</span>
        <span>Bill</span>
        <span className="text-right">Total</span>
        <span className="text-right">Paid</span>
        <span className="text-right">Balance</span>
        <span className="text-right">Status</span>
      </div>
      <PreviewArea height={data.limit * ROW.due} compactHeight={data.limit * ROW.dueStacked} fixedFrom="lg" label="Today's pending bills, first few">
        {data.items.length === 0 ? (
          <div className="pt-2">
            <Empty>{empty}</Empty>
          </div>
        ) : (
          <ul>
            {data.items.map((row) => (
              <li key={row.id} className={cn('grid min-h-11 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-[12px] px-2 py-1.5 text-[13.5px] transition hover:bg-[#F5F6F9]', DUES_GRID)}>
                <span className={cn('min-w-0 truncate font-medium', SURFACE.primary)}>{row.patient}</span>
                <span className={cn('hidden min-w-0 truncate text-[12.5px] lg:block', SURFACE.secondary)} title={row.reference ?? undefined}>{row.reference ?? '—'}</span>
                <span className={cn('hidden whitespace-nowrap text-right tabular-nums lg:block', SURFACE.secondary)}>{money(row.amount)}</span>
                <span className={cn('hidden whitespace-nowrap text-right tabular-nums lg:block', SURFACE.secondary)}>{money(row.paid)}</span>
                <span className={cn('whitespace-nowrap text-right font-semibold tabular-nums', SURFACE.primary)}>{money(row.balance)}</span>
                <span className="col-span-2 flex items-center justify-between gap-2 lg:col-span-1 lg:justify-end">
                  <span className={cn('text-[11.5px] lg:hidden', SURFACE.muted)}>
                    {[row.reference, `${money(row.paid)} of ${money(row.amount)} paid`].filter(Boolean).join(' · ')}
                  </span>
                  <StatusChip status={row.status} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </PreviewArea>
      <PreviewLimit shown={data.items.length} total={data.total} noun="bills from today's visits" fullList={fullList} missing="The rest, and older balances: Billing page (not built yet)." />
    </div>
  );
}

/* --------------------------------------------------------------- follow-ups */

export function FollowUpList({ rows, total, empty }: { rows: FollowUpRow[]; total: number; empty: string }) {
  if (rows.length === 0) {
    return <Empty>{empty}</Empty>;
  }

  return (
    <>
      <ul className="-mx-2">
        {rows.map((row) => (
          <li key={row.id} className="flex items-center justify-between gap-3 rounded-[12px] px-2 py-2 transition hover:bg-[#F5F6F9]">
            <span className="min-w-0">
              <span className={cn('block truncate text-[13.5px] font-medium', SURFACE.primary)}>{row.primary}</span>
              {row.secondary && <span className={cn('line-clamp-2 text-[11.5px] leading-snug sm:line-clamp-1', SURFACE.muted)}>{row.secondary}</span>}
            </span>
            <span className={cn('shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-medium', row.status === 'overdue' ? PILL.bad.pill : PILL.neutral.pill)}>{row.time}</span>
          </li>
        ))}
      </ul>
      {total > rows.length && <p className={cn('mt-2 text-[12px]', SURFACE.muted)}>and {total - rows.length} more</p>}
    </>
  );
}

/* --------------------------------------------------------- needs attention */

/** The CRM's attention card: a coloured edge for how urgent, the count in the same colour. */
const ATTENTION_HUE: Record<string, string> = { overdue: '#E8593C', alert: '#E8593C', pending: '#F5A623' };

export function AttentionList({ items, empty }: { items: AttentionItem[]; empty: string }) {
  if (items.length === 0) {
    return <Empty>{empty}</Empty>;
  }

  return (
    <ul className="grid gap-2">
      {items.map((item) => {
        const color = ATTENTION_HUE[item.status] ?? '#6B7488';
        const body = (
          <>
            <span className="absolute inset-y-0 left-0 w-[3px]" style={{ background: color }} aria-hidden="true" />
            <span className="min-w-0 flex-1 pl-1.5">
              <span className={cn('block truncate text-[13.5px] font-medium', SURFACE.primary)}>{item.primary}</span>
              <span className={cn('line-clamp-2 text-[11.5px] leading-snug sm:line-clamp-1', SURFACE.muted)}>
                {item.amount !== undefined ? `${formatMoney(item.amount, item.currency)} · ${item.secondary}` : item.secondary}
              </span>
            </span>
            <span className="shrink-0 text-[20px] font-medium leading-none tabular-nums" style={{ color }}>{item.count}</span>
          </>
        );
        const box = cn('relative flex items-center gap-3 overflow-hidden rounded-[16px] border border-[#ECEEF2]/70 bg-white px-4 py-3');

        return (
          <li key={item.id}>
            {item.href ? (
              <Link href={item.href} className={cn(box, 'transition hover:border-transparent hover:shadow-[0_2px_6px_rgba(11,11,15,0.05),0_20px_44px_-20px_rgba(11,11,15,0.20)]', FOCUS)}>
                {body}
              </Link>
            ) : (
              <div className={box}>{body}</div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
