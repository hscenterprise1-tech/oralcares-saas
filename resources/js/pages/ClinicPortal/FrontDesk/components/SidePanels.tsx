import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight, CalendarClock, CalendarHeart, ReceiptText, Stethoscope, TriangleAlert } from 'lucide-react';
import { Avatar, FOCUS_RING, Panel, TONE, dsButtonClasses } from '@/clinic/ds';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import type { DeskMode } from './desk';
import { clockTime, waitLabel } from './QueueRowItem';
import type { BookingRow, Desk, DeskBilling, DeskFollowUps, DoctorRow, QueueRow } from './types';

/*
 * The desk's secondary panels, each in its category's colour: attention
 * coral, arrivals cyan, doctors violet, follow-ups amber, billing mint.
 * A panel is drawn only when it has something to show.
 */

function MoreLink({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className={cn('inline-flex h-8 items-center gap-1 rounded-lg px-2 text-[13px] font-semibold text-[#1769AA] hover:bg-[#EAF2FA]', FOCUS_RING)}>
      {children}
      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </Link>
  );
}

function PersonLine({ name, href, detail, aside }: { name: string; href?: string | null; detail: string; aside?: ReactNode }) {
  return (
    <div className="flex min-w-0 items-center gap-3 px-4 py-2.5">
      <Avatar name={name} size="sm" />
      <div className="min-w-0 flex-1">
        {href ? (
          <Link href={href} className={cn('block rounded text-[14px] font-semibold leading-5 text-[#173B60] hover:text-[#1769AA] hover:underline', FOCUS_RING)}>
            {name}
          </Link>
        ) : (
          <p className="text-[14px] font-semibold leading-5 text-[#173B60]">{name}</p>
        )}
        <p className="break-words text-[12.5px] leading-5 text-[#64748B]">{detail}</p>
      </div>
      {aside && <div className="shrink-0 text-right">{aside}</div>}
    </div>
  );
}

function GroupHeading({ children, action }: { children: ReactNode; action?: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-2 px-4 pb-0.5 pt-2.5">
      <h3 className="text-[12.5px] font-semibold text-[#475569]">{children}</h3>
      {action}
    </div>
  );
}

export function attentionTotal(attention: Desk['attention']): number {
  return attention.long_wait.count + attention.unassigned.count + attention.not_present.rows.length + attention.late_bookings.count;
}

/** Long waits, patients without a doctor, people not present when called and bookings past their time. */
export function AttentionPanel({ attention, mode, onShowLane }: { attention: Desk['attention']; mode: DeskMode; onShowLane: (lane: 'waiting' | 'not_present') => void }) {
  const total = attentionTotal(attention);
  if (total === 0) {
    return null;
  }
  const groups: { key: string; label: string; count: number; rows: QueueRow[]; lane: 'waiting' | 'not_present' }[] = [
    { key: 'long', label: `Waiting over ${attention.long_wait.minutes} min`, count: attention.long_wait.count, rows: attention.long_wait.rows, lane: 'waiting' },
    { key: 'unassigned', label: `No ${mode.doctor.toLowerCase()} assigned`, count: attention.unassigned.count, rows: attention.unassigned.rows, lane: 'waiting' },
    { key: 'absent', label: 'Not present when called', count: attention.not_present.rows.length, rows: attention.not_present.rows, lane: 'not_present' },
  ];

  return (
    <Panel title="Needs attention" icon={TriangleAlert} tone="coral" count={formatNumber(total)} className="border-[#F6CFC5] bg-gradient-to-b from-[#FFF6F3] to-white" flush>
      <div className="pb-2">
        {groups
          .filter((group) => group.count > 0)
          .map((group) => (
            <div key={group.key}>
              <GroupHeading
                action={
                  <button type="button" onClick={() => onShowLane(group.lane)} className={cn('rounded px-1.5 text-[12.5px] font-semibold text-[#1769AA] hover:underline', FOCUS_RING)}>
                    Show
                  </button>
                }
              >
                {group.label} · <span className="tabular-nums">{formatNumber(group.count)}</span>
              </GroupHeading>
              <ul>
                {group.rows.slice(0, 3).map((row) => (
                  <li key={row.id}>
                    <PersonLine
                      name={row.patient?.name ?? 'Unknown patient'}
                      href={row.links.patient}
                      detail={row.doctor?.name ?? `No ${mode.doctor.toLowerCase()} yet`}
                      aside={row.wait_minutes !== null ? <span className="text-[13px] font-bold tabular-nums text-[#B42318]">{waitLabel(row.wait_minutes)}</span> : undefined}
                    />
                  </li>
                ))}
              </ul>
            </div>
          ))}
        {attention.late_bookings.count > 0 && (
          <div>
            <GroupHeading>
              Late bookings · <span className="tabular-nums">{formatNumber(attention.late_bookings.count)}</span>
            </GroupHeading>
            <ul>
              {attention.late_bookings.rows.slice(0, 3).map((row) => (
                <li key={row.id}>
                  <BookingLine row={row} />
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Panel>
  );
}

function BookingLine({ row }: { row: BookingRow }) {
  const name = row.patient?.name ?? row.prospect?.name ?? 'Unnamed booking';

  return (
    <PersonLine
      name={name}
      detail={[row.patient ? null : 'Not registered yet', row.type, row.doctor].filter(Boolean).join(' · ') || (row.patient?.uhid ?? '—')}
      aside={
        <>
          <span className="block rounded-md bg-[#DFF5F8] px-2 py-0.5 text-[13px] font-bold tabular-nums text-[#0B6F80]">{clockTime(row.scheduled_at) ?? '—'}</span>
          {row.late_minutes !== null && row.late_minutes > 0 && <span className="mt-0.5 block text-[12px] font-semibold text-[#B42318]">{waitLabel(row.late_minutes)} late</span>}
        </>
      }
    />
  );
}

/** Today's bookings not yet checked in (only where the calendar is open to this member). */
export function ArrivalsPanel({ upcoming, stillToArrive, calendarHref }: { upcoming: BookingRow[]; stillToArrive: number; calendarHref: string | null }) {
  if (upcoming.length === 0) {
    return null;
  }

  return (
    <Panel title="Still to arrive" icon={CalendarClock} tone="cyan" count={formatNumber(stillToArrive)} actions={calendarHref ? <MoreLink href={calendarHref}>Calendar</MoreLink> : undefined} flush>
      <ul className="pb-2">
        {upcoming.map((row) => (
          <li key={row.id}>
            <BookingLine row={row} />
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/** Doctors on shift at this branch: free or with a patient, and how many wait for each. */
export function DoctorsPanel({ doctors, mode }: { doctors: DoctorRow[]; mode: DeskMode }) {
  if (doctors.length === 0) {
    return null;
  }
  const free = doctors.filter((doctor) => !doctor.with_patient).length;

  return (
    <Panel title={`${mode.doctor}s on duty`} icon={Stethoscope} tone="violet" count={formatNumber(doctors.length)} description={`${formatNumber(free)} free right now`} flush>
      <ul className="pb-2">
        {doctors.map((doctor) => (
          <li key={doctor.id}>
            <PersonLine
              name={doctor.name}
              detail={[doctor.practice, doctor.room].filter(Boolean).join(' · ') || doctor.shift_label}
              aside={
                <>
                  <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[12px] font-semibold', doctor.with_patient ? cn(TONE.violet.soft, TONE.violet.ink) : cn(TONE.green.soft, TONE.green.ink))}>
                    {doctor.with_patient ? 'With patient' : 'Free'}
                  </span>
                  <span className="mt-0.5 block text-[12px] tabular-nums text-[#64748B]">{formatNumber(doctor.waiting)} waiting</span>
                </>
              }
            />
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/** Follow-ups due today or overdue (deferred). */
export function FollowUpsPanel({ followUps }: { followUps: DeskFollowUps | null }) {
  if (!followUps || followUps.rows.length === 0) {
    return null;
  }

  return (
    <Panel title="Follow-ups due" icon={CalendarHeart} tone="amber" count={formatNumber(followUps.count.today + followUps.count.overdue)} description={`${formatNumber(followUps.count.today)} today · ${formatNumber(followUps.count.overdue)} overdue`} flush>
      <ul className="pb-2">
        {followUps.rows.map((row) => (
          <li key={row.id}>
            <PersonLine
              name={row.patient?.name ?? 'Unknown patient'}
              href={row.links.patient}
              detail={[row.reason, row.practitioner].filter(Boolean).join(' · ') || (row.patient?.uhid ?? '—')}
              aside={<span className={cn('rounded-full px-2 py-0.5 text-[12px] font-semibold', row.days_overdue > 0 ? cn(TONE.coral.soft, TONE.coral.ink) : cn(TONE.amber.soft, TONE.amber.ink))}>{row.days_overdue > 0 ? `${row.days_overdue} d overdue` : 'Today'}</span>}
            />
          </li>
        ))}
      </ul>
    </Panel>
  );
}

/** The follow-ups request ended without them (a network or server failure): say so and offer another try. */
export function FollowUpsFailed({ onRetry }: { onRetry: () => void }) {
  return (
    <Panel title="Follow-ups due" icon={CalendarHeart} tone="amber">
      <div role="alert" className="flex flex-wrap items-center justify-between gap-2 text-[13px] text-[#475569]">
        <span>Follow-ups could not be loaded.</span>
        <button type="button" onClick={onRetry} className={cn('rounded-lg px-2 py-1 text-[13px] font-semibold text-[#1769AA] hover:bg-[#EAF2FA]', FOCUS_RING)}>
          Try again
        </button>
      </div>
    </Panel>
  );
}

/**
 * Billing overview for members the server sends billing to (billing
 * rights): collected today first, then signed visits waiting for a bill
 * and bills with a balance, each figure exactly as the server sent it.
 * It reads only; bills are raised and paid on the Billing page. A day
 * with no billing activity says so instead of showing a row of zeroes.
 */
export function BillingSummaryPanel({ billing }: { billing: DeskBilling }) {
  const money = (value: number) => formatMoney(value, billing.currency);
  const payments = billing.collected_today.payments;
  const quiet = payments === 0 && billing.ready.length === 0 && billing.unpaid_count === 0;

  return (
    <section aria-labelledby="billing-overview-title" className="min-w-0 rounded-2xl border border-[#E6EBF1] bg-white p-4 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_20px_-16px_rgba(23,59,96,0.3)]">
      <header className="flex items-center gap-2">
        <ReceiptText className="h-4 w-4 text-[#1E6F51]" strokeWidth={2} aria-hidden="true" />
        <h2 id="billing-overview-title" className="text-[15px] font-semibold leading-6 text-[#173B60]">
          Billing overview
        </h2>
      </header>

      {quiet ? (
        <p className="mt-2 text-[13px] leading-5 text-[#64748B]">No payments, bills ready or balances due yet today.</p>
      ) : (
        <>
          <div className="mt-3 rounded-xl bg-[#F2FAF6] px-3.5 py-3">
            <p className="text-[12.5px] font-semibold text-[#1E6F51]">Collected today</p>
            <p className="mt-0.5 text-[24px] font-bold leading-8 tracking-tight text-[#173B60] tabular-nums">{money(billing.collected_today.total)}</p>
            <p className="text-[12.5px] text-[#64748B]">
              from {formatNumber(payments)} payment{payments === 1 ? '' : 's'}
            </p>
          </div>
          <dl className="mt-3 grid grid-cols-2 divide-x divide-[#E6EBF1]">
            <div className="pr-3">
              <dt className="text-[12.5px] font-medium text-[#64748B]">Ready to bill</dt>
              <dd className="text-[18px] font-bold leading-7 text-[#173B60] tabular-nums">
                {formatNumber(billing.ready.length)}
                {billing.ready_more ? '+' : ''}
              </dd>
            </div>
            <div className="pl-3">
              <dt className="text-[12.5px] font-medium text-[#64748B]">Balance due</dt>
              <dd className={cn('text-[18px] font-bold leading-7 tabular-nums', billing.unpaid_count > 0 ? 'text-[#A35A06]' : 'text-[#173B60]')}>{formatNumber(billing.unpaid_count)}</dd>
            </div>
          </dl>
        </>
      )}

      <Link href={billing.links.all} className={cn(dsButtonClasses('secondary', 'md', 'mt-4 w-full'))}>
        Open Billing
        <ArrowRight className="h-4 w-4" aria-hidden="true" />
      </Link>
    </section>
  );
}
