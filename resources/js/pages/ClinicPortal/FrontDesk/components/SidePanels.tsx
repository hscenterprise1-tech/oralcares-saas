import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, CalendarClock, FlaskConical, CalendarHeart, CalendarPlus, ReceiptText, Stethoscope, TriangleAlert } from 'lucide-react';
import { requestKey } from '@/clinic/billing/BillingFrame';
import { PaymentForm, paymentFor, paymentPayloadOf, paymentProblem } from '@/clinic/billing/PaymentForm';
import { Avatar, DsButton, DsDialog, FOCUS_RING, Panel, TONE, dsButtonClasses } from '@/clinic/ds';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { DayClosingDialog } from './DayClosingDialog';
import type { DeskMode } from './desk';
import { clockTime, waitLabel } from './QueueRowItem';
import type { BookingRow, Desk, DeskBilling, DeskFollowUps, DeskLab, DoctorRow, QueueRow } from './types';

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

/** What needs the desk; a physio-only desk keeps no queue, so long waits and "not present" are not its concern. */
export function attentionTotal(attention: Desk['attention'], mode: DeskMode): number {
  const queue = mode.physioOnly ? 0 : attention.long_wait.count + attention.not_present.rows.length;

  return queue + attention.unassigned.count + attention.late_bookings.count;
}

/** Long waits, patients without a doctor, people not present when called and bookings past their time. */
export function AttentionPanel({ attention, mode, onShowLane }: { attention: Desk['attention']; mode: DeskMode; onShowLane: (lane: 'waiting' | 'not_present') => void }) {
  const total = attentionTotal(attention, mode);
  if (total === 0) {
    return null;
  }
  const groups: { key: string; label: string; count: number; rows: QueueRow[]; lane: 'waiting' | 'not_present' }[] = [
    { key: 'long', label: `Waiting over ${attention.long_wait.minutes} min`, count: mode.physioOnly ? 0 : attention.long_wait.count, rows: attention.long_wait.rows, lane: 'waiting' },
    { key: 'unassigned', label: `No ${mode.doctor.toLowerCase()} assigned`, count: attention.unassigned.count, rows: attention.unassigned.rows, lane: 'waiting' },
    { key: 'absent', label: 'Not present when called', count: mode.physioOnly ? 0 : attention.not_present.rows.length, rows: attention.not_present.rows, lane: 'not_present' },
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
                      aside={row.wait_minutes !== null && !mode.physioOnly ? <span className="text-[13px] font-bold tabular-nums text-[#B42318]">{waitLabel(row.wait_minutes)}</span> : undefined}
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

/** Today's bookings not yet checked in (only where the calendar is open to this member); an empty day says so. */
export function ArrivalsPanel({ upcoming, stillToArrive, calendarHref, bookHref }: { upcoming: BookingRow[]; stillToArrive: number; calendarHref: string | null; bookHref?: string | null }) {
  return (
    <Panel
      title="Appointments"
      icon={CalendarClock}
      tone="cyan"
      count={stillToArrive > 0 ? formatNumber(stillToArrive) : undefined}
      description={stillToArrive > 0 ? 'Today, still to arrive' : 'Today'}
      actions={calendarHref ? <MoreLink href={calendarHref}>Calendar</MoreLink> : undefined}
      flush
    >
      {upcoming.length > 0 ? (
        <ul className="pb-2">
          {upcoming.map((row) => (
            <li key={row.id}>
              <BookingLine row={row} />
            </li>
          ))}
        </ul>
      ) : (
        <div className="flex items-center justify-between gap-3 px-5 pb-5">
          <p className="text-[13px] leading-5 text-[#64748B]">No more bookings today.</p>
          {bookHref && (
            <Link href={bookHref} className={cn(dsButtonClasses('secondary', 'sm'), 'shrink-0')}>
              <CalendarPlus className="h-4 w-4" aria-hidden="true" />
              Book
            </Link>
          )}
        </div>
      )}
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
                  <span className="mt-0.5 block text-[12px] tabular-nums text-[#64748B]">
                    {formatNumber(doctor.waiting)} {mode.physioOnly ? 'to start' : 'waiting'}
                  </span>
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

/**
 * Dental lab work the desk moves: made but not yet sent, out with the lab (late ones flagged), and arrived back, when the patient
 * is to be called in to be fitted. Sending and receiving are the desk's steps; the server decides again who may take them.
 */
export function LabCasesPanel({ lab }: { lab: DeskLab | null | undefined }) {
  const [busy, setBusy] = useState<number | null>(null);
  if (!lab || lab.rows.length === 0) {
    return null;
  }
  const move = (id: number, url: string) => router.post(url, {}, { preserveScroll: true, onStart: () => setBusy(id), onFinish: () => setBusy(null) });
  const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  const waiting = lab.count.arrived + lab.count.late + lab.count.to_send;

  return (
    <Panel id="desk-lab" title="Lab cases" icon={FlaskConical} tone="cyan" count={waiting > 0 ? formatNumber(waiting) : undefined} description={`${formatNumber(lab.count.arrived)} arrived · ${formatNumber(lab.count.with_lab)} with the lab${lab.count.late > 0 ? ` · ${formatNumber(lab.count.late)} late` : ''}`} flush>
      <ul className="pb-2">
        {lab.rows.map((row) => (
          <li key={row.id}>
            <PersonLine
              name={row.patient?.name ?? 'Unknown patient'}
              href={row.links.patient}
              detail={[row.what, row.lab, row.state === 'arrived' ? 'Call the patient to be fitted' : row.due_on ? `due ${day(row.due_on)}` : null].filter(Boolean).join(' · ')}
              aside={
                row.steps.send || row.steps.receive ? (
                  <DsButton size="sm" variant={row.state === 'arrived' ? 'secondary' : 'primary'} loading={busy === row.id} onClick={() => move(row.id, (row.steps.send ?? row.steps.receive) as string)}>
                    {row.steps.send ? 'Mark sent' : 'Mark received'}
                  </DsButton>
                ) : (
                  <span className={cn('rounded-full px-2 py-0.5 text-[12px] font-semibold', TONE.cyan.soft, TONE.cyan.ink)}>Arrived</span>
                )
              }
            />
            {row.late && <p className="-mt-1 px-5 pb-2 text-[12px] font-semibold text-[#B13A22]">Late from the lab</p>}
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

export type Collectable = { key: string; name: string; detail: string; amount: number; url: string; bills: boolean };

/**
 * What is waiting to be paid at this branch, for whoever collects payments:
 * visits finished and not yet billed (billed and paid in one step, at the
 * clinic's own prices) and bills with a balance. Each is collected right
 * here; the day's takings show only to those who read the ledger.
 */
export function PaymentsPanel({ billing, onRows }: { billing: DeskBilling; onRows: { encounters: number[]; bills: number[] } }) {
  const money = (value: number) => formatMoney(value, billing.currency);
  const [paying, setPaying] = useState<Collectable | null>(null);
  const [closing, setClosing] = useState(false);
  // Today's finished visits are collected from their own rows; this panel keeps what is left from earlier.
  const items: Collectable[] = [
    ...(billing.can.bill
      ? billing.ready.filter((row) => !onRows.encounters.includes(row.encounter_id)).map((row) => ({
          key: `ready-${row.encounter_id}`,
          name: row.patient?.name ?? 'Unknown patient',
          detail: [row.doctor, 'to bill'].filter(Boolean).join(' · '),
          amount: row.total,
          url: row.links.collect,
          bills: true,
        }))
      : []),
    ...billing.unpaid.filter((bill) => !onRows.bills.includes(bill.id)).map((bill) => ({ key: `bill-${bill.id}`, name: bill.patient?.name ?? 'Unknown patient', detail: [bill.number, bill.plan ? `${bill.plan.overdue ? 'Instalment overdue' : 'Next instalment'} ${money(bill.plan.remaining)}, ${new Date(`${bill.plan.due_on}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}${bill.plan.left > 1 ? ` · ${bill.plan.left} left` : ''}` : 'balance due'].filter(Boolean).join(' · '), amount: bill.outstanding, url: bill.links.pay, bills: false })),
  ];
  const waiting = items.length;

  return (
    <Panel
      id="desk-payments"
      title="Payments due"
      icon={ReceiptText}
      tone="green"
      count={waiting > 0 ? formatNumber(waiting) : undefined}
      description={billing.collected_today ? `${money(billing.collected_today.total)} collected today` : 'From earlier visits'}
      actions={billing.links.all ? <MoreLink href={billing.links.all}>Billing</MoreLink> : undefined}
      flush
    >
      {items.length === 0 ? (
        <p className="px-5 pb-5 text-[13px] leading-5 text-[#64748B]">No balances from earlier visits. Today’s are collected from each patient’s row.</p>
      ) : (
        <ul className="pb-2">
          {items.map((item) => (
            <li key={item.key}>
              <PersonLine
                name={item.name}
                detail={item.detail}
                aside={
                  <DsButton size="sm" variant="primary" onClick={() => setPaying(item)}>
                    Collect {money(item.amount)}
                  </DsButton>
                }
              />
            </li>
          ))}
        </ul>
      )}
      {billing.links.closing && (
        <div className="border-t border-[#EEF2F7] px-5 py-3">
          <DsButton size="sm" variant="secondary" className="w-full" onClick={() => setClosing(true)}>
            Close the day
          </DsButton>
        </div>
      )}
      {paying && <CollectDialog item={paying} billing={billing} onClose={() => setPaying(null)} />}
      {closing && billing.links.closing && <DayClosingDialog url={billing.links.closing} currency={billing.currency} onClose={() => setClosing(false)} />}
    </Panel>
  );
}

/** One payment taken at the desk: how it was paid and how much, sent once (idempotent). */
export function CollectDialog({ item, billing, onClose }: { item: Collectable; billing: Pick<DeskBilling, 'methods' | 'currency'>; onClose: () => void }) {
  const [payment, setPayment] = useState(() => paymentFor(billing.methods, item.amount));
  const [key] = useState(() => requestKey('desk-pay'));
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const pay = () => {
    const wrong = paymentProblem(payment, item.amount, billing.currency);
    if (wrong) {
      setProblem(wrong);

      return;
    }
    setBusy(true);
    router.post(item.url, { ...paymentPayloadOf(payment), idempotency_key: key }, {
      preserveScroll: true,
      onError: (errors) => setProblem(Object.values(errors)[0] ?? 'The payment was not recorded.'),
      onSuccess: () => onClose(),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <DsDialog
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title={`Collect from ${item.name}`}
      description={item.bills ? 'The visit is billed at the clinic’s prices and paid in one step.' : item.detail}
      footer={
        <>
          <DsButton variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </DsButton>
          <DsButton variant="primary" onClick={pay} loading={busy}>
            Record payment
          </DsButton>
        </>
      }
    >
      <PaymentForm value={payment} onChange={(patch) => setPayment((value) => ({ ...value, ...patch }))} methods={billing.methods} due={item.amount} currency={billing.currency} idPrefix={`desk-${item.key}`} error={problem} />
    </DsDialog>
  );
}
