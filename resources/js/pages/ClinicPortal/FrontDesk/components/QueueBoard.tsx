import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, ArrowRight, BellRing, CalendarClock, CircleCheck, CircleSlash, ClipboardCheck, Flag, FolderOpen, Hourglass, LogIn, Megaphone, Play, ReceiptText, Wallet, RotateCcw, ShieldAlert, Smile, Stethoscope, UserPlus, UserRoundX, type LucideIcon } from 'lucide-react';
import { ActionMenu, Avatar, ConfirmDialog, DsButton, FOCUS_RING, InlineNotice, TONE, isDental, isPhysio, type ActionMenuItem, type DsTone } from '@/clinic/ds';
import { formatMoney, formatNumber, humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { IconChip, SURFACE } from '@/pages/ClinicPortal/CommandCenter/sections';
import { patientSection, type DeskMode } from './desk';
import { PhysioDeskDialog } from '@/clinic/patients/PhysioDeskDialog';
import { DoctorDialog, PriorityDialog } from './QueueDialogs';
import { clockTime, waitLabel } from './QueueRowItem';
import { CollectDialog } from './SidePanels';
import type { BookingRow, DeskBilling, DoctorRow, ListKey, QueueRow } from './types';
import { useQueueAction } from './useQueueAction';

/** A list on the board: the server's queue lists, and (a physio desk) today's bookings still to arrive. */
export type BoardLane = ListKey | 'appointments';

interface Lane {
  key: BoardLane;
  label: string;
  tone: DsTone;
  icon: LucideIcon;
  empty: { title: string; body: string };
}

export function lanesFor(mode: DeskMode, bookings = false): Lane[] {
  const visits = mode.physio && !mode.dental ? 'sessions' : 'visits';

  const lanes: Lane[] = [
    mode.physioOnly
      ? { key: 'waiting', label: mode.arrived, tone: 'cyan', icon: ClipboardCheck, empty: { title: 'Nobody checked in yet', body: 'Patients appear here the moment they are checked in with Registration or Repeat. Start or continue their session from their row.' } }
      : { key: 'waiting', label: mode.arrived, tone: 'amber', icon: Hourglass, empty: { title: 'Nobody is waiting', body: 'Patients appear here the moment they are checked in with New case or Repeat case.' } },
    { key: 'with_doctor', label: mode.active, tone: 'violet', icon: mode.physio && !mode.dental ? Activity : Stethoscope, empty: { title: `Nobody is ${mode.active.toLowerCase()}`, body: `A patient moves here when the ${mode.doctor.toLowerCase()} starts.` } },
    { key: 'done', label: 'Completed', tone: 'green', icon: CircleCheck, empty: { title: `No ${visits} completed yet`, body: `Finished ${visits} collect here through the day.` } },
    { key: 'not_present', label: 'Not present', tone: 'slate', icon: UserRoundX, empty: { title: 'Everyone called was here', body: 'Patients marked not present when called wait here to be put back.' } },
  ];

  // Physiotherapy calls nobody, so nobody is "not present"; and a session under way stays with the checked-in
  // patients (its row says "In session" and continues it), so the desk has two lists: checked in, and completed.
  const shown = mode.physioOnly ? lanes.filter((item) => item.key === 'waiting' || item.key === 'done') : lanes;

  // Today's bookings before the patients who came: booked → checked in → completed, left to right.
  return bookings
    ? [{ key: 'appointments', label: 'Appointments', tone: 'blue', icon: CalendarClock, empty: { title: 'No more bookings today', body: 'Booked patients show here until they arrive; check them in from their row.' } }, ...shown]
    : shown;
}

/** Status in words on a soft pill of its family. */
function statusOf(row: QueueRow, mode: DeskMode): { label: string; tone: DsTone } {
  switch (row.status) {
    case 'waiting':
      return mode.physioOnly ? { label: mode.arrived, tone: 'cyan' } : { label: 'Waiting', tone: 'amber' };
    case 'called':
      return { label: 'Called', tone: 'cyan' };
    case 'skipped':
      return { label: 'Not present', tone: 'slate' };
    case 'in_consultation':
      return { label: mode.active, tone: 'violet' };
    case 'completed':
      return { label: 'Completed', tone: 'green' };
    default:
      return { label: humanize(row.status), tone: 'slate' };
  }
}

const PAYMENT: Record<string, { label: string; tone: DsTone }> = {
  paid: { label: 'Paid', tone: 'green' },
  due: { label: 'Balance due', tone: 'amber' },
  not_billed: { label: 'To collect', tone: 'amber' },
  none: { label: 'No charge', tone: 'slate' },
};

/** Every row's columns, so time, status and the action line up down the list. */
const ROW_GRID = 'flex flex-wrap items-center gap-x-3 gap-y-2 sm:grid sm:grid-cols-[minmax(0,1fr)_56px_128px_172px_36px] sm:gap-x-4';

type DialogKind = 'priority' | 'doctor' | 'cancel' | 'physio' | 'collect';
type Dialog = { kind: DialogKind; row: QueueRow } | null;

/**
 * Today's queue at this branch, one lane at a time: the lanes are the
 * server's lists (`FrontDesk::LISTS`), each showing its first rows with a
 * link to the full list. A row's actions are the reception moves the queue
 * state machine allows from its status; calling and its corrections also
 * need the Queue module (`can.queue`), cancelling does not. Each row also
 * opens the patient's record at the section that fits its specialty.
 */
export function QueueBoard({
  mode,
  lane,
  onLaneChange,
  rows,
  counts,
  listHref,
  canQueue,
  longWaitMinutes,
  doctors,
  billing = null,
  bookings = null,
  onRegister,
  calendarHref = null,
}: {
  mode: DeskMode;
  lane: BoardLane;
  onLaneChange: (lane: BoardLane) => void;
  rows: Record<ListKey, QueueRow[]>;
  counts: Record<ListKey, number> & { appointments?: number };
  listHref: (lane: ListKey) => string | null;
  canQueue: boolean;
  longWaitMinutes: number;
  doctors: DoctorRow[];
  /** The desk's billing (its payment methods): a finished visit's payment is collected from its row. */
  billing?: DeskBilling | null;
  /** Today's bookings still to arrive: given, they are the board's first list. */
  bookings?: BookingRow[] | null;
  /** Register a booked prospect (New Case / Registration). */
  onRegister?: () => void;
  calendarHref?: string | null;
}) {
  const action = useQueueAction();
  const [dialog, setDialog] = useState<Dialog>(null);
  const lanes = lanesFor(mode, bookings !== null);
  const current = lanes.find((item) => item.key === lane) ?? lanes[0];
  const booked = current.key === 'appointments';
  const shown = booked ? [] : rows[current.key as ListKey];
  const size = booked ? (bookings?.length ?? 0) : shown.length;
  const href = booked ? calendarHref : listHref(current.key as ListKey);
  const count = (key: BoardLane) => (key === 'appointments' ? (counts.appointments ?? bookings?.length ?? 0) : counts[key]);

  const close = () => {
    setDialog(null);
    action.dismiss();
  };

  return (
    <section aria-labelledby="queue-title" className={cn('min-w-0 overflow-hidden', SURFACE)}>
      {/* The Owner Dashboard's panel header: its tile, title and caption. */}
      <header className="flex flex-col gap-3 px-5 pb-4 pt-5 2xl:flex-row 2xl:items-center 2xl:justify-between">
        {/* The title and the lists on one line from a small tablet up; the title gives way, the lists never wrap below. */}
        <div className="flex min-w-0 flex-1 items-center gap-3">
          <IconChip icon={mode.physioOnly ? ClipboardCheck : Hourglass} tone="blue" />
          <div className="min-w-0">
            <h2 id="queue-title" className="text-[15px] font-semibold leading-5 text-cx-nav-ink">
              {mode.physioOnly ? 'Today’s patients' : 'Today’s patient flow'}
            </h2>
            <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">
              {mode.physioOnly ? 'Booked, checked in, completed: each row has its next step.' : 'Who is here, who is next, and who has finished.'}
            </p>
          </div>
        </div>
        <div role="tablist" aria-label="Queue status" className="flex max-w-full shrink-0 gap-1 self-start overflow-x-auto rounded-xl bg-[#EEF2F7] p-1 [scrollbar-width:none]">
          {lanes.map((item) => {
            const selected = item.key === lane;

            return (
              <button
                key={item.key}
                type="button"
                role="tab"
                id={`lane-${item.key}`}
                aria-selected={selected}
                aria-controls="lane-panel"
                onClick={() => onLaneChange(item.key)}
                className={cn(
                  'flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-[13px] font-semibold motion-safe:transition-colors [@media(pointer:coarse)]:h-11',
                  FOCUS_RING,
                  selected ? 'bg-white text-[#173B60] shadow-[0_1px_3px_rgba(15,23,42,0.12)]' : 'text-[#64748B] hover:text-[#173B60]',
                )}
              >
                <span aria-hidden="true" className={cn('h-2 w-2 rounded-full', TONE[item.tone].solid)} />
                {item.label}
                <span className={cn('rounded-md px-1.5 text-[12px] tabular-nums', selected ? cn(TONE[item.tone].soft, TONE[item.tone].ink) : 'text-[#64748B]')}>{formatNumber(count(item.key))}</span>
              </button>
            );
          })}
        </div>
      </header>

      <div id="lane-panel" role="tabpanel" aria-labelledby={`lane-${current.key}`} aria-live="polite" className="border-t border-[#E6EBF1]">
        {size > 0 && (
          <div aria-hidden="true" className={cn(ROW_GRID, 'hidden border-b border-[#EEF2F6] bg-[#F8FAFC] px-5 py-2 text-[12px] font-semibold text-[#64748B] sm:grid')}>
            <span>{booked ? 'Booked' : 'Patient'}</span>
            <span className="text-right">{booked ? 'At' : current.key === 'done' ? 'Done' : 'In'}</span>
            <span>Status</span>
            <span className="text-right">Next</span>
            <span />
          </div>
        )}
        {booked && size > 0 ? (
          <ol className="divide-y divide-[#EEF2F6]">
            {(bookings ?? []).map((row) => (
              <li key={row.id}>
                <BookingRowView row={row} onRegister={onRegister} onCheckedIn={() => onLaneChange('waiting')} />
              </li>
            ))}
          </ol>
        ) : size === 0 ? (
          <div className="flex flex-col items-center px-5 py-12 text-center">
            <span className={cn('mb-3 flex h-12 w-12 items-center justify-center rounded-2xl', TONE[current.tone].soft, TONE[current.tone].ink)}>
              <current.icon className="h-6 w-6" strokeWidth={1.9} aria-hidden="true" />
            </span>
            <p className="text-[15px] font-semibold text-[#173B60]">{current.empty.title}</p>
            <p className="mt-1 max-w-[46ch] text-[13.5px] leading-[22px] text-[#64748B]">{current.empty.body}</p>
          </div>
        ) : (
          <>
            <ol className="divide-y divide-[#EEF2F6]">
              {shown.map((row, index) => (
                <li key={row.id} className="motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-300">
                  <QueueRowView
                    row={row}
                    mode={mode}
                    position={current.key === 'waiting' && canQueue && !mode.physioOnly ? index + 1 : null}
                    longWaitMinutes={longWaitMinutes}
                    currency={billing?.currency ?? 'INR'}
                    canCollect={billing !== null && billing.can.collect}
                    canQueue={canQueue}
                    busyKey={action.busy?.startsWith(`${row.id}:`) ? action.busy.slice(String(row.id).length + 1) : null}
                    error={action.error?.rowId === row.id && dialog === null ? action.error.message : null}
                    onDismissError={action.dismiss}
                    onAction={(key, payload) => action.run(row.id, key, row.links.status, payload)}
                    onOpen={(kind) => {
                      action.dismiss();
                      setDialog({ kind, row });
                    }}
                  />
                </li>
              ))}
            </ol>
          </>
        )}
      </div>

      {href && size > 0 && (
        <footer className="border-t border-[#E6EBF1] px-5 py-2.5">
          <Link href={href} className={cn('inline-flex h-9 items-center gap-1 rounded-lg px-2 text-[13px] font-semibold text-[#1769AA] hover:bg-[#EAF2FA]', FOCUS_RING)}>
            {booked ? 'Open the calendar' : count(current.key) > size ? `See all ${formatNumber(count(current.key))} ${current.label.toLowerCase()}` : `Open the ${current.label.toLowerCase()} list`}
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        </footer>
      )}

      {dialog?.kind === 'cancel' && (
        <ConfirmDialog
          open
          onOpenChange={(open) => !open && close()}
          danger
          title={`Cancel ${dialog.row.patient?.name ?? 'this patient'}'s visit?`}
          description="They leave today's queue. If they come back later, check them in again with Repeat Case."
          confirmLabel="Cancel visit"
          busy={action.busy === `${dialog.row.id}:cancel`}
          error={action.error?.rowId === dialog.row.id ? action.error.message : null}
          onConfirm={() => action.run(dialog.row.id, 'cancel', dialog.row.links.status, { action: 'cancel' }, (ok) => ok && setDialog(null))}
        />
      )}
      {dialog?.kind === 'priority' && <PriorityDialog row={dialog.row} action={action} onClose={close} />}
      {dialog?.kind === 'doctor' && <DoctorDialog row={dialog.row} doctors={doctors} action={action} onClose={close} />}
      {dialog?.kind === 'collect' && dialog.row.collect && billing && (
        <CollectDialog
          item={{
            key: `row-${dialog.row.id}`,
            name: dialog.row.patient?.name ?? 'this patient',
            detail: [dialog.row.visit?.reason, dialog.row.doctor?.name].filter(Boolean).join(' · '),
            amount: dialog.row.collect.amount,
            url: dialog.row.collect.url,
            bills: dialog.row.collect.bills,
          }}
          billing={billing}
          onClose={close}
        />
      )}
      {dialog?.kind === 'physio' && dialog.row.patient && (
        <PhysioDeskDialog patientId={dialog.row.patient.id} patientName={dialog.row.patient.name} onClose={close} onDone={() => router.reload()} />
      )}
    </section>
  );
}

function QueueRowView({
  row,
  mode,
  position,
  longWaitMinutes,
  currency,
  canCollect,
  canQueue,
  busyKey,
  error,
  onDismissError,
  onAction,
  onOpen,
}: {
  row: QueueRow;
  mode: DeskMode;
  position: number | null;
  longWaitMinutes: number;
  currency: string;
  canCollect: boolean;
  canQueue: boolean;
  busyKey: string | null;
  error: string | null;
  onDismissError: () => void;
  onAction: (key: string, payload: Record<string, string>) => void;
  onOpen: (kind: DialogKind) => void;
}) {
  const [opening, setOpening] = useState(false);
  // The visit takes a moment to open; the button says so and is not pressed twice.
  const opens = { onStart: () => setOpening(true), onFinish: () => setOpening(false) };
  const name = row.patient?.name ?? 'Unknown patient';
  const status = statusOf(row, mode);
  const inQueue = ['waiting', 'called', 'skipped'].includes(row.status);
  const age = row.patient?.age !== null && row.patient?.age !== undefined ? `${row.patient.age} y` : null;
  const identity = [row.patient?.uhid, [age, row.patient?.gender ? humanize(row.patient.gender) : null].filter(Boolean).join(' · ') || null].filter(Boolean).join(' · ');
  const reason = [row.visit?.type, row.visit?.reason].filter(Boolean).join(' · ');
  const minutes = row.wait_minutes ?? 0;
  const waitClass = minutes >= longWaitMinutes ? 'text-[#B42318]' : minutes >= longWaitMinutes / 2 ? 'text-[#A35A06]' : 'text-[#173B60]';
  // A physio-only desk keeps no wait: the time is when the patient was checked in.
  const waits = inQueue && !mode.physioOnly;
  const time = waits ? (row.wait_minutes !== null ? waitLabel(row.wait_minutes) : null) : clockTime(inQueue ? row.checked_in_at : (row.completed_at ?? row.started_at));
  const payment = row.payment ? PAYMENT[row.payment] : null;
  const specialty = row.visit?.specialty_key ?? null;

  // The next move the queue allows from this status (`QueueStateMachine::MOVES`); calling needs the Queue module.
  const primary: { key: string; label: string; icon: LucideIcon } | null = !canQueue || mode.physioOnly
    ? null
    : row.status === 'waiting'
      ? { key: 'call', label: 'Call', icon: Megaphone }
      : row.status === 'called'
        ? { key: 'recall', label: 'Call again', icon: BellRing }
        : row.status === 'skipped'
          ? { key: 'requeue', label: 'Back to queue', icon: RotateCcw }
          : null;

  const more: ActionMenuItem[] = [];
  if (row.links.patient) {
    const patient = row.links.patient;
    more.push({ key: 'record', label: 'Open patient record', icon: FolderOpen, onSelect: () => router.visit(patient) });
    if (specialty && isDental(specialty) && mode.toothChart) {
      more.push({ key: 'dental', label: 'Dental chart & history', icon: Smile, onSelect: () => router.visit(patientSection(patient, 'dental')) });
    }
    if (specialty && isPhysio(specialty)) {
      more.push({ key: 'physio', label: 'Plan & sessions', icon: Activity, onSelect: () => router.visit(patientSection(patient, 'physio')) });
    }
    if (mode.canSeeBilling) {
      more.push({ key: 'billing', label: 'Patient billing', icon: ReceiptText, onSelect: () => router.visit(patientSection(patient, 'billing')) });
    }
  }
  // A therapist without a login: the desk opens and works the whole visit (problem, plan, session, package, bill).
  const deskOpen = row.links.desk_open;
  const deskWorkspace = row.links.desk_workspace;
  // A finished visit with money to take: collecting it is the row's button, and the visit moves to the menu.
  const collect = canCollect ? (row.collect ?? null) : null;
  // One click: the visit opens straight from the row.
  const visit: { label: string; icon: LucideIcon; go: () => void } | null = deskWorkspace
    ? { label: row.status === 'completed' ? 'View visit' : 'Continue', icon: row.status === 'completed' ? FolderOpen : Play, go: () => router.visit(deskWorkspace, opens) }
    : deskOpen
      ? { label: row.links.desk_kind === 'dental' ? 'Start visit' : 'Start session', icon: Play, go: () => router.post(deskOpen, {}, opens) }
      : null;
  const deskVisit = collect ? null : visit;
  if (collect && visit) {
    more.unshift({ key: 'physio-workspace', label: visit.label, icon: visit.icon, onSelect: visit.go });
  }
  if (!visit && specialty && isPhysio(specialty) && row.patient && ['waiting', 'called', 'in_consultation'].includes(row.status)) {
    // Physiotherapy run from the desk for a therapist who signs in: the session is recorded here for them.
    more.unshift({ key: 'physio-session', label: 'Record session', icon: CircleCheck, onSelect: () => onOpen('physio') });
  }
  if (inQueue) {
    if (canQueue && !mode.physioOnly && row.status !== 'skipped') {
      more.push({ key: 'skip', label: 'Mark not present', icon: UserRoundX, onSelect: () => onAction('skip', { action: 'skip' }) });
    }
    more.push({ key: 'priority', label: 'Change priority', icon: Flag, onSelect: () => onOpen('priority') });
    if (row.can_correct) {
      more.push({ key: 'doctor', label: `Change ${mode.doctor.toLowerCase()}`, icon: Stethoscope, onSelect: () => onOpen('doctor') });
    }
    more.push({ key: 'cancel', label: 'Cancel visit', icon: CircleSlash, danger: true, onSelect: () => onOpen('cancel') });
  }

  // Why they came and with whom, on one line under the name; the specialty only where the desk has two.
  const about = [
    reason || (row.source === 'appointment' ? 'Booked visit' : 'Walk-in'),
    row.doctor?.name ?? `No ${mode.doctor.toLowerCase()} yet`,
    mode.dental && mode.physio ? row.visit?.specialty : null,
  ].filter(Boolean).join(' · ');

  return (
    <div className={cn('px-4 py-2.5 sm:px-5', busyKey && 'bg-[#F8FAFC]')}>
      {/* One line per patient on the table grid: who, why and with whom; the time; the status; the next move; more. */}
      <div className={ROW_GRID}>
        <div className="flex w-full min-w-0 items-center gap-3 sm:w-auto">
          {position !== null && (
            <span className="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-[#E8EEF5] px-1 text-[12px] font-bold tabular-nums text-[#173B60]" aria-label={`Position ${position}`}>
              {position}
            </span>
          )}
          <Avatar name={name} />
          <div className="min-w-0">
            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2">
              {row.links.patient ? (
                <Link href={row.links.patient} className={cn('rounded text-[14px] font-semibold leading-5 text-[#173B60] hover:text-[#1769AA] hover:underline', FOCUS_RING)}>
                  {name}
                </Link>
              ) : (
                <span className="text-[14px] font-semibold leading-5 text-[#173B60]">{name}</span>
              )}
              {row.case && <span className="text-[12px] font-medium tabular-nums text-[#64748B]">{row.case}</span>}
              {row.priority !== 'normal' && (
                <span className={cn('rounded-md px-1.5 py-px text-[11px] font-bold uppercase tracking-wide', row.priority === 'emergency' ? TONE.rose.soft + ' ' + TONE.rose.ink : row.priority === 'urgent' ? TONE.amber.soft + ' ' + TONE.amber.ink : TONE.slate.soft + ' ' + TONE.slate.ink)}>
                  {humanize(row.priority)}
                </span>
              )}
            </div>
            <p className={cn('truncate text-[12.5px] leading-5', row.doctor ? 'text-[#64748B]' : 'text-[#A35A06]')}>{about}</p>
            {row.safety.length > 0 && (
              <p className="flex items-start gap-1 text-[12px] font-medium leading-5 text-[#B42318]">
                <ShieldAlert className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                  <span className="sr-only">Safety: </span>
                  {row.safety.map((flag) => flag.label).join(', ')}
                  {row.safety_more > 0 && ` +${row.safety_more} more`}
                </span>
              </p>
            )}
          </div>
        </div>

        <span className={cn('text-[13.5px] font-semibold tabular-nums sm:text-right', time ? (waits ? waitClass : 'text-[#173B60]') : 'text-[#94A3B8]')}>{time ?? '—'}</span>
        <span className="flex flex-col items-start">
          <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12px] font-semibold', TONE[status.tone].soft, TONE[status.tone].ink)}>
            <span aria-hidden="true" className={cn('h-1.5 w-1.5 rounded-full', TONE[status.tone].solid)} />
            {status.label}
          </span>
          {payment && <span className={cn('mt-0.5 pl-1 text-[11.5px] font-semibold', TONE[payment.tone].ink)}>{payment.label}</span>}
        </span>
        <span className="ml-auto flex items-center justify-end gap-1.5 sm:ml-0">
          {collect && (
            <DsButton size="sm" variant="primary" icon={Wallet} disabled={busyKey !== null} onClick={() => onOpen('collect')}>
              Collect {formatMoney(collect.amount, currency)}
            </DsButton>
          )}
          {deskVisit && (
            <DsButton size="sm" variant={deskOpen && !deskWorkspace ? 'primary' : 'secondary'} icon={deskVisit.icon} loading={opening} disabled={busyKey !== null || opening} onClick={deskVisit.go}>
              {deskVisit.label}
            </DsButton>
          )}
          {primary && (
            <DsButton size="sm" variant={primary.key === 'call' ? 'primary' : 'secondary'} icon={primary.icon} loading={busyKey === primary.key} disabled={busyKey !== null} onClick={() => onAction(primary.key, { action: primary.key })}>
              {primary.label}
            </DsButton>
          )}
        </span>
        <ActionMenu label={`More for ${name}`} items={busyKey !== null ? [] : more} />
      </div>
      {error && (
        <div className="mt-3">
          <InlineNotice
            action={
              <button type="button" onClick={onDismissError} className={cn('rounded px-1 text-[12px] font-semibold underline', FOCUS_RING)}>
                Dismiss
              </button>
            }
          >
            {error}
          </InlineNotice>
        </div>
      )}
    </div>
  );
}

/** A booking still to arrive, on the same grid: check a registered patient in from here (by the booking), or register a prospect first. */
function BookingRowView({ row, onRegister, onCheckedIn }: { row: BookingRow; onRegister?: () => void; onCheckedIn: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const name = row.patient?.name ?? row.prospect?.name ?? 'Unnamed booking';
  const late = row.late_minutes !== null && row.late_minutes > 0;
  const about = [row.type, row.doctor, row.patient ? null : 'Not registered yet'].filter(Boolean).join(' · ') || 'Booked visit';
  const checkIn = row.links.check_in;

  return (
    <div className="px-4 py-2.5 sm:px-5">
      <div className={ROW_GRID}>
        <div className="flex w-full min-w-0 items-center gap-3 sm:w-auto">
          <Avatar name={name} />
          <div className="min-w-0">
            <div className="flex min-w-0 flex-wrap items-baseline gap-x-2">
              <span className="text-[14px] font-semibold leading-5 text-[#173B60]">{name}</span>
              {row.patient?.uhid && <span className="text-[12px] font-medium tabular-nums text-[#64748B]">{row.patient.uhid}</span>}
            </div>
            <p className={cn('truncate text-[12.5px] leading-5', row.patient ? 'text-[#64748B]' : 'text-[#A35A06]')}>{about}</p>
          </div>
        </div>
        <span className="text-[13.5px] font-semibold tabular-nums text-[#173B60] sm:text-right">{clockTime(row.scheduled_at) ?? '—'}</span>
        <span className="flex">
          <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12px] font-semibold', late ? cn(TONE.amber.soft, TONE.amber.ink) : cn(TONE.blue.soft, TONE.blue.ink))}>
            <span aria-hidden="true" className={cn('h-1.5 w-1.5 rounded-full', late ? TONE.amber.solid : TONE.blue.solid)} />
            {late ? `${waitLabel(row.late_minutes ?? 0)} late` : 'Booked'}
          </span>
        </span>
        <span className="ml-auto flex justify-end sm:ml-0">
          {checkIn ? (
            <DsButton
              size="sm"
              variant="primary"
              icon={LogIn}
              loading={busy}
              disabled={busy}
              onClick={() =>
                router.post(checkIn, { appointment_id: row.id }, {
                  preserveScroll: true,
                  onStart: () => {
                    setBusy(true);
                    setError(null);
                  },
                  onError: (errors) => setError(Object.values(errors)[0] ?? 'The patient was not checked in.'),
                  // The next step is theirs to start: show the checked-in list.
                  onSuccess: (page) => Object.keys(page.props.errors ?? {}).length === 0 && onCheckedIn(),
                  onFinish: () => setBusy(false),
                })
              }
            >
              Check in
            </DsButton>
          ) : (
            onRegister && (
              <DsButton size="sm" variant="secondary" icon={UserPlus} onClick={onRegister}>
                Register
              </DsButton>
            )
          )}
        </span>
        <span className="hidden sm:block" aria-hidden="true" />
      </div>
      {error && <p className="mt-2 text-[12.5px] font-medium text-[#B42318]">{error}</p>}
    </div>
  );
}
