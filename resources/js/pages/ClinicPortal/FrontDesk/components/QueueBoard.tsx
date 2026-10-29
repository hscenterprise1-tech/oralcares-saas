import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, ArrowRight, BellRing, CircleCheck, CircleSlash, Flag, FolderOpen, Hourglass, Megaphone, ReceiptText, RotateCcw, ShieldAlert, Smile, Stethoscope, UserRoundX, type LucideIcon } from 'lucide-react';
import { ActionMenu, Avatar, ConfirmDialog, DsButton, FOCUS_RING, InlineNotice, TONE, isDental, isPhysio, specialtyTone, type ActionMenuItem, type DsTone } from '@/clinic/ds';
import { formatNumber, humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';
import { patientSection, type DeskMode } from './desk';
import { DoctorDialog, PriorityDialog } from './QueueDialogs';
import { clockTime, waitLabel } from './QueueRowItem';
import type { DoctorRow, ListKey, QueueRow } from './types';
import { useQueueAction } from './useQueueAction';

interface Lane {
  key: ListKey;
  label: string;
  tone: DsTone;
  icon: LucideIcon;
  empty: { title: string; body: string };
}

export function lanesFor(mode: DeskMode): Lane[] {
  const visits = mode.physio && !mode.dental ? 'sessions' : 'visits';

  return [
    { key: 'waiting', label: 'Waiting', tone: 'amber', icon: Hourglass, empty: { title: 'Nobody is waiting', body: 'Patients appear here the moment they are checked in with New case or Repeat case.' } },
    { key: 'with_doctor', label: mode.active, tone: 'violet', icon: mode.physio && !mode.dental ? Activity : Stethoscope, empty: { title: `Nobody is ${mode.active.toLowerCase()}`, body: `A patient moves here when the ${mode.doctor.toLowerCase()} starts.` } },
    { key: 'done', label: 'Completed', tone: 'green', icon: CircleCheck, empty: { title: `No ${visits} completed yet`, body: `Finished ${visits} collect here through the day.` } },
    { key: 'not_present', label: 'Not present', tone: 'slate', icon: UserRoundX, empty: { title: 'Everyone called was here', body: 'Patients marked not present when called wait here to be put back.' } },
  ];
}

/** Status in words on a soft pill of its family. */
function statusOf(row: QueueRow, mode: DeskMode): { label: string; tone: DsTone } {
  switch (row.status) {
    case 'waiting':
      return { label: 'Waiting', tone: 'amber' };
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
  not_billed: { label: 'Not billed', tone: 'slate' },
};

/* Patient · visit · doctor · wait · status and actions, aligned from lg as one table. */
const COLUMNS = 'lg:grid lg:grid-cols-[minmax(0,2.1fr)_minmax(0,1.5fr)_minmax(0,1.2fr)_92px_minmax(190px,auto)] lg:items-center lg:gap-x-5';

type Dialog = { kind: 'priority' | 'doctor' | 'cancel'; row: QueueRow } | null;

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
}: {
  mode: DeskMode;
  lane: ListKey;
  onLaneChange: (lane: ListKey) => void;
  rows: Record<ListKey, QueueRow[]>;
  counts: Record<ListKey, number>;
  listHref: (lane: ListKey) => string | null;
  canQueue: boolean;
  longWaitMinutes: number;
  doctors: DoctorRow[];
}) {
  const action = useQueueAction();
  const [dialog, setDialog] = useState<Dialog>(null);
  const lanes = lanesFor(mode);
  const current = lanes.find((item) => item.key === lane) ?? lanes[0];
  const shown = rows[lane];
  const href = listHref(lane);

  const close = () => {
    setDialog(null);
    action.dismiss();
  };

  return (
    <section aria-labelledby="queue-title" className="min-w-0 overflow-hidden rounded-2xl border border-[#E6EBF1] bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_12px_28px_-20px_rgba(23,59,96,0.4)]">
      <header className="flex flex-wrap items-center justify-between gap-3 bg-gradient-to-r from-[#F4F8FD] to-white px-4 pb-4 pt-5 sm:px-5">
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[#173B60] text-white shadow-[0_6px_14px_-8px_rgba(23,59,96,0.8)]">
            <Hourglass className="h-5 w-5" strokeWidth={2} aria-hidden="true" />
          </span>
          <div>
            <h2 id="queue-title" className="text-[17px] font-semibold leading-6 text-[#173B60]">
              Today&apos;s patient flow
            </h2>
            <p className="text-[12.5px] leading-5 text-[#64748B]">Who is here, who is next, and who has finished.</p>
          </div>
        </div>
        <div role="tablist" aria-label="Queue status" className="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-[#EEF2F7] p-1 [scrollbar-width:none]">
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
                <span className={cn('rounded-md px-1.5 text-[12px] tabular-nums', selected ? cn(TONE[item.tone].soft, TONE[item.tone].ink) : 'text-[#64748B]')}>{formatNumber(counts[item.key])}</span>
              </button>
            );
          })}
        </div>
      </header>

      <div id="lane-panel" role="tabpanel" aria-labelledby={`lane-${lane}`} aria-live="polite" className="border-t border-[#E6EBF1]">
        {shown.length === 0 ? (
          <div className="flex flex-col items-center px-5 py-12 text-center">
            <span className={cn('mb-3 flex h-12 w-12 items-center justify-center rounded-2xl', TONE[current.tone].soft, TONE[current.tone].ink)}>
              <current.icon className="h-6 w-6" strokeWidth={1.9} aria-hidden="true" />
            </span>
            <p className="text-[15px] font-semibold text-[#173B60]">{current.empty.title}</p>
            <p className="mt-1 max-w-[46ch] text-[13.5px] leading-[22px] text-[#64748B]">{current.empty.body}</p>
          </div>
        ) : (
          <>
            <div aria-hidden="true" className={cn('hidden border-b border-[#E6EBF1] bg-[#F8FAFC] px-5 py-2 text-[12px] font-semibold text-[#64748B]', COLUMNS)}>
              <span>Patient</span>
              <span>{mode.physio && !mode.dental ? 'Session' : 'Visit'}</span>
              <span>{mode.doctor}</span>
              <span>{lane === 'done' ? 'Finished' : lane === 'with_doctor' ? 'Started' : 'Waiting'}</span>
              <span className="text-right">Status</span>
            </div>
            <ol className="divide-y divide-[#EEF2F6]">
              {shown.map((row, index) => (
                <li key={row.id} className="motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-300">
                  <QueueRowView
                    row={row}
                    mode={mode}
                    position={lane === 'waiting' && canQueue ? index + 1 : null}
                    longWaitMinutes={longWaitMinutes}
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

      {href && shown.length > 0 && (
        <footer className="border-t border-[#E6EBF1] px-5 py-2.5">
          <Link href={href} className={cn('inline-flex h-9 items-center gap-1 rounded-lg px-2 text-[13px] font-semibold text-[#1769AA] hover:bg-[#EAF2FA]', FOCUS_RING)}>
            {counts[lane] > shown.length ? `See all ${formatNumber(counts[lane])} ${current.label.toLowerCase()}` : `Open the ${current.label.toLowerCase()} list`}
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
    </section>
  );
}

function QueueRowView({
  row,
  mode,
  position,
  longWaitMinutes,
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
  canQueue: boolean;
  busyKey: string | null;
  error: string | null;
  onDismissError: () => void;
  onAction: (key: string, payload: Record<string, string>) => void;
  onOpen: (kind: 'priority' | 'doctor' | 'cancel') => void;
}) {
  const name = row.patient?.name ?? 'Unknown patient';
  const status = statusOf(row, mode);
  const inQueue = ['waiting', 'called', 'skipped'].includes(row.status);
  const age = row.patient?.age !== null && row.patient?.age !== undefined ? `${row.patient.age} y` : null;
  const identity = [row.patient?.uhid, [age, row.patient?.gender ? humanize(row.patient.gender) : null].filter(Boolean).join(' · ') || null].filter(Boolean).join(' · ');
  const reason = [row.visit?.type, row.visit?.reason].filter(Boolean).join(' · ');
  const minutes = row.wait_minutes ?? 0;
  const waitClass = minutes >= longWaitMinutes ? 'text-[#B42318]' : minutes >= longWaitMinutes / 2 ? 'text-[#A35A06]' : 'text-[#173B60]';
  const time = inQueue ? (row.wait_minutes !== null ? waitLabel(row.wait_minutes) : null) : clockTime(row.completed_at ?? row.started_at);
  const payment = row.payment ? PAYMENT[row.payment] : null;
  const specialty = row.visit?.specialty_key ?? null;

  // The next move the queue allows from this status (`QueueStateMachine::MOVES`); calling needs the Queue module.
  const primary: { key: string; label: string; icon: LucideIcon } | null = !canQueue
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
  if (inQueue) {
    if (canQueue && row.status !== 'skipped') {
      more.push({ key: 'skip', label: 'Mark not present', icon: UserRoundX, onSelect: () => onAction('skip', { action: 'skip' }) });
    }
    more.push({ key: 'priority', label: 'Change priority', icon: Flag, onSelect: () => onOpen('priority') });
    if (row.can_correct) {
      more.push({ key: 'doctor', label: `Change ${mode.doctor.toLowerCase()}`, icon: Stethoscope, onSelect: () => onOpen('doctor') });
    }
    more.push({ key: 'cancel', label: 'Cancel visit', icon: CircleSlash, danger: true, onSelect: () => onOpen('cancel') });
  }

  return (
    <div className={cn('px-4 py-3 sm:px-5', busyKey && 'bg-[#F8FAFC]')}>
      <div className={cn('flex flex-col gap-2.5', COLUMNS)}>
        {/* Patient */}
        <div className="flex min-w-0 items-center gap-3">
          {position !== null && (
            <span className="flex h-6 min-w-6 shrink-0 items-center justify-center rounded-md bg-[#E8EEF5] px-1 text-[12px] font-bold tabular-nums text-[#173B60]" aria-label={`Position ${position}`}>
              {position}
            </span>
          )}
          <Avatar name={name} />
          <div className="min-w-0">
            <div className="flex min-w-0 flex-wrap items-center gap-x-2">
              {row.links.patient ? (
                <Link href={row.links.patient} className={cn('rounded text-[15px] font-semibold leading-6 text-[#173B60] hover:text-[#1769AA] hover:underline', FOCUS_RING)}>
                  {name}
                </Link>
              ) : (
                <span className="text-[15px] font-semibold leading-6 text-[#173B60]">{name}</span>
              )}
              {row.priority !== 'normal' && (
                <span className={cn('rounded-md px-1.5 py-px text-[11.5px] font-bold uppercase tracking-wide', row.priority === 'emergency' ? TONE.rose.soft + ' ' + TONE.rose.ink : row.priority === 'urgent' ? TONE.amber.soft + ' ' + TONE.amber.ink : TONE.slate.soft + ' ' + TONE.slate.ink)}>
                  {humanize(row.priority)}
                </span>
              )}
            </div>
            <p className="text-[12.5px] leading-5 text-[#64748B]">{[row.case, identity].filter(Boolean).join(' · ') || '—'}</p>
            {row.safety.length > 0 && (
              <p className="mt-0.5 flex items-start gap-1 text-[12.5px] font-medium leading-5 text-[#B42318]">
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

        {/* Visit or session */}
        <div className="min-w-0 pl-[52px] text-[13px] leading-5 lg:pl-0">
          {row.visit?.specialty && (
            <span className={cn('inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[12px] font-semibold', TONE[specialtyTone(specialty)].soft, TONE[specialtyTone(specialty)].ink)}>
              {specialty && isPhysio(specialty) ? <Activity className="h-3.5 w-3.5" aria-hidden="true" /> : specialty && isDental(specialty) ? <Smile className="h-3.5 w-3.5" aria-hidden="true" /> : null}
              {row.visit.specialty}
            </span>
          )}
          <span className="mt-0.5 block text-[#475569]">{reason || (row.source === 'appointment' ? 'Booked visit' : 'Walk-in')}</span>
        </div>

        {/* Doctor */}
        <div className="min-w-0 pl-[52px] text-[13px] leading-5 lg:pl-0">
          {row.doctor ? <span className="font-semibold text-[#173B60]">{row.doctor.name}</span> : <span className="font-semibold text-[#A35A06]">Not assigned yet</span>}
          <span className="block text-[#64748B]">{row.source === 'appointment' ? 'Booked' : 'Walk-in'}</span>
        </div>

        {/* Wait or time */}
        <div className="pl-[52px] text-[14px] font-bold tabular-nums lg:pl-0">
          {time ? <span className={inQueue ? waitClass : 'text-[#173B60]'}>{time}</span> : <span className="font-medium text-[#94A3B8]">—</span>}
        </div>

        {/* Status and actions */}
        <div className="flex flex-wrap items-center justify-between gap-2 pl-[52px] lg:justify-end lg:pl-0">
          <span className="flex flex-wrap items-center gap-1.5">
            <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12.5px] font-semibold', TONE[status.tone].soft, TONE[status.tone].ink)}>
              <span aria-hidden="true" className={cn('h-1.5 w-1.5 rounded-full', TONE[status.tone].solid)} />
              {status.label}
            </span>
            {payment && <span className={cn('text-[12px] font-semibold', TONE[payment.tone].ink)}>{payment.label}</span>}
          </span>
          <span className="flex items-center gap-1.5">
            {primary && (
              <DsButton size="sm" variant={primary.key === 'call' ? 'primary' : 'secondary'} icon={primary.icon} loading={busyKey === primary.key} disabled={busyKey !== null} onClick={() => onAction(primary.key, { action: primary.key })}>
                {primary.label}
              </DsButton>
            )}
            <ActionMenu label={`More for ${name}`} items={busyKey !== null ? [] : more} />
          </span>
        </div>
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
