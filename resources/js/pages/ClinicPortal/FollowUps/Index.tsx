import { useState } from 'react';
import { InfiniteScroll, Link, router } from '@inertiajs/react';
import { AlarmClock, CalendarCheck2, CalendarClock, CalendarPlus, CheckCircle2, CircleSlash, History, Phone, UserRound, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, Input } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { BTN_SM, FOCUS, IconTile, Pill, day, type Tone } from '../Patients/components/patient360/v2/ui';

type Tab = 'overdue' | 'today' | 'upcoming' | 'done';

type FollowUp = {
  id: number;
  due_on: string | null;
  days_overdue: number;
  status: string;
  priority: string | null;
  reason: string | null;
  notes: string | null;
  patient: { id: number; name: string; uhid: string | null; phone: string | null } | null;
  practitioner: string | null;
  treatment: { title: string; specialty: string | null } | null;
  links: { patient: string; complete: string; cancel: string; reschedule: string; annotate: string; book: string };
};

/** `FollowUpBoardController::index`: the server is the contract. */
type Props = {
  tab: Tab;
  mine: boolean;
  can_filter_mine: boolean;
  counts: { overdue: number; today: number };
  followUps: { data: FollowUp[] };
};

const TABS: { key: Tab; label: string; icon: LucideIcon; tone: Tone; empty: string }[] = [
  { key: 'overdue', label: 'Overdue', icon: AlarmClock, tone: 'rose', empty: 'Nothing overdue. Every follow-up is booked or on time.' },
  { key: 'today', label: 'Due today', icon: CalendarCheck2, tone: 'amber', empty: 'No follow-ups due today.' },
  { key: 'upcoming', label: 'Next 30 days', icon: CalendarClock, tone: 'blue', empty: 'Nothing due in the next 30 days.' },
  { key: 'done', label: 'Done', icon: History, tone: 'mint', empty: 'No follow-ups completed in the last 30 days.' },
];

export default function FollowUpsIndex(props: Props) {
  return (
    <ClinicPortalShell title="Follow-ups">
      <FollowUpBoard {...props} />
    </ClinicPortalShell>
  );
}

/**
 * The clinic's follow-ups across patients: who should come back and has not
 * been booked yet. Each one is booked, moved, completed or cancelled from
 * here; the server keeps the history (`FollowUps`).
 */
function FollowUpBoard({ tab, mine, can_filter_mine: canFilterMine, counts, followUps }: Props) {
  const [moving, setMoving] = useState<FollowUp | null>(null);
  const [cancelling, setCancelling] = useState<FollowUp | null>(null);
  const visit = (next: Partial<{ tab: Tab; mine: boolean }>) =>
    router.get(window.location.pathname, { tab: next.tab ?? tab, mine: (next.mine ?? mine) ? '1' : '0' }, { preserveScroll: false, reset: ['followUps'] });
  const current = TABS.find((item) => item.key === tab) ?? TABS[0];

  return (
    <div className="w-full pb-10">
      <PageHeader
        eyebrow={<p className="text-[11px] font-semibold uppercase tracking-[0.06em] text-cx-nav-teal-strong">Patients</p>}
        title="Follow-ups"
        description="Patients who should come back: book them, move the date, or close the follow-up."
        actions={
          <>
            {canFilterMine && (
            <div role="radiogroup" aria-label="Whose follow-ups" className="flex rounded-full bg-white/80 p-1 shadow-[inset_0_0_0_1px_#E2E8F0]">
              {[
                { value: true, label: 'Mine' },
                { value: false, label: 'Everyone' },
              ].map((option) => (
                <button
                  key={option.label}
                  type="button"
                  role="radio"
                  aria-checked={mine === option.value}
                  onClick={() => visit({ mine: option.value })}
                  className={cn('h-9 rounded-full px-4 text-[13px] font-semibold transition-colors', FOCUS, mine === option.value ? 'bg-[#1769AA] text-white' : 'text-cx-text-2 hover:text-cx-nav-ink')}
                >
                  {option.label}
                </button>
              ))}
            </div>
          )}
          </>
        }
      />

      <nav aria-label="Follow-up lists" className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        {TABS.map((item) => {
          const selected = item.key === tab;
          const count = item.key === 'overdue' ? counts.overdue : item.key === 'today' ? counts.today : null;

          return (
            <button
              key={item.key}
              type="button"
              aria-current={selected ? 'page' : undefined}
              onClick={() => visit({ tab: item.key })}
              className={cn(
                'flex items-center gap-3 rounded-2xl border bg-white p-3 text-left transition-colors',
                FOCUS,
                selected ? 'border-[#1769AA]/40 shadow-[0_8px_20px_-14px_rgba(23,105,170,0.6)]' : 'border-[#E6EBF1] hover:border-[#C9D3E1]',
              )}
            >
              <IconTile icon={item.icon} tone={item.tone} size="sm" />
              <span className="min-w-0">
                <span className={cn('block text-[13px] font-semibold', selected ? 'text-[#1769AA]' : 'text-cx-nav-ink')}>{item.label}</span>
                {count !== null && <span className="block text-[18px] font-semibold leading-6 tabular-nums text-cx-nav-ink">{count >= 1000 ? '999+' : count}</span>}
              </span>
            </button>
          );
        })}
      </nav>

      <section className="overflow-hidden rounded-[22px] border border-[#E6EBF1] bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)]">
        {followUps.data.length === 0 ? (
          <div className="flex flex-col items-center px-6 py-14 text-center">
            <IconTile icon={current.icon} tone={current.tone} size="lg" />
            <p className="mt-3 text-[14px] font-semibold text-cx-nav-ink">{current.empty}</p>
          </div>
        ) : (
          <InfiniteScroll data="followUps" as="ol" className="divide-y divide-[#EEF2F6]" loading={<p className="px-5 py-4 text-center text-[13px] text-cx-text-2">Loading more…</p>}>
            {followUps.data.map((followUp) => (
              <FollowUpRow key={followUp.id} followUp={followUp} tab={tab} onMove={() => setMoving(followUp)} onCancel={() => setCancelling(followUp)} />
            ))}
          </InfiniteScroll>
        )}
      </section>

      {moving && <MoveDialog followUp={moving} onClose={() => setMoving(null)} />}
      {cancelling && <CancelDialog followUp={cancelling} onClose={() => setCancelling(null)} />}
    </div>
  );
}

function FollowUpRow({ followUp, tab, onMove, onCancel }: { followUp: FollowUp; tab: Tab; onMove: () => void; onCancel: () => void }) {
  const [busy, setBusy] = useState(false);
  const open = tab !== 'done';
  const complete = () => router.post(followUp.links.complete, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

  return (
    <li className="flex flex-col gap-3 px-4 py-3.5 sm:px-5 lg:flex-row lg:items-center">
      <div className="flex min-w-0 flex-1 items-start gap-3">
        <span className={cn('mt-0.5 flex h-10 w-12 shrink-0 flex-col items-center justify-center rounded-xl text-center', followUp.days_overdue > 0 ? 'bg-[#FFF1F1] text-[#B42318]' : 'bg-[#EEF4FF] text-[#1D4ED8]')}>
          <span className="text-[15px] font-bold leading-4 tabular-nums">{followUp.due_on?.slice(8, 10) ?? '—'}</span>
          <span className="text-[10.5px] font-semibold uppercase">{followUp.due_on ? new Date(`${followUp.due_on}T00:00:00`).toLocaleString('en-IN', { month: 'short' }) : ''}</span>
        </span>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            {followUp.patient ? (
              <Link href={followUp.links.patient} className={cn('rounded text-[14.5px] font-semibold text-cx-nav-ink hover:text-[#1769AA] hover:underline', FOCUS)}>
                {followUp.patient.name}
              </Link>
            ) : (
              <span className="text-[14.5px] font-semibold text-cx-nav-ink">Patient</span>
            )}
            {followUp.days_overdue > 0 && <Pill tone="rose">{followUp.days_overdue} {followUp.days_overdue === 1 ? 'day' : 'days'} overdue</Pill>}
            {followUp.status === 'booked' && <Pill tone="blue">Booked</Pill>}
            {followUp.priority && followUp.priority !== 'Normal' && <Pill tone="amber">{followUp.priority}</Pill>}
          </div>
          <p className="mt-0.5 text-[12.5px] text-cx-text-2 [overflow-wrap:anywhere]">{[followUp.reason, followUp.treatment?.title].filter(Boolean).join(' · ') || 'Follow-up visit'}</p>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12px] text-cx-text-3">
            {followUp.patient?.uhid && <span>{followUp.patient.uhid}</span>}
            {followUp.practitioner && (
              <span className="inline-flex items-center gap-1">
                <UserRound className="h-3 w-3" aria-hidden="true" /> {followUp.practitioner}
              </span>
            )}
            {followUp.patient?.phone && (
              <a href={`tel:${followUp.patient.phone}`} className="inline-flex items-center gap-1 hover:text-[#1769AA]">
                <Phone className="h-3 w-3" aria-hidden="true" /> {followUp.patient.phone}
              </a>
            )}
            {!open && followUp.due_on && <span>Due {day(followUp.due_on)}</span>}
          </p>
        </div>
      </div>
      {open && (
        <div className="flex flex-wrap items-center gap-1.5 lg:justify-end">
          {followUp.status !== 'booked' && (
            <Link href={followUp.links.book} className={cn(BTN_SM, 'border-transparent bg-[#1769AA] text-white hover:bg-[#135A93] hover:text-white')}>
              <CalendarPlus className="h-3.5 w-3.5" aria-hidden="true" /> Book
            </Link>
          )}
          <button type="button" className={BTN_SM} onClick={onMove} disabled={busy}>
            <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" /> Move
          </button>
          <button type="button" className={BTN_SM} onClick={complete} disabled={busy}>
            <CheckCircle2 className="h-3.5 w-3.5 text-[#12A877]" aria-hidden="true" /> Done
          </button>
          <button type="button" className={cn(BTN_SM, 'text-[#B42318]')} onClick={onCancel} disabled={busy} aria-label={`Cancel the follow-up for ${followUp.patient?.name ?? 'this patient'}`}>
            <CircleSlash className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        </div>
      )}
    </li>
  );
}

function MoveDialog({ followUp, onClose }: { followUp: FollowUp; onClose: () => void }) {
  const [date, setDate] = useState(followUp.due_on ?? '');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const save = () =>
    router.patch(followUp.links.reschedule, { due_on: date }, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not moved.'),
      onSuccess: onClose,
    });

  return (
    <Modal
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title="Move the follow-up"
      description={followUp.patient?.name}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} disabled={!date} onClick={save}>
            Move
          </Button>
        </>
      }
    >
      <Field label="New date" htmlFor="follow-up-date" required error={problem ?? undefined}>
        <Input id="follow-up-date" type="date" value={date} onChange={(event) => setDate(event.target.value)} />
      </Field>
    </Modal>
  );
}

function CancelDialog({ followUp, onClose }: { followUp: FollowUp; onClose: () => void }) {
  const [busy, setBusy] = useState(false);
  const cancel = () => router.post(followUp.links.cancel, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose });

  return (
    <Modal
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title="Cancel this follow-up?"
      description={`${followUp.patient?.name ?? 'The patient'} will not be reminded. It stays in their history as cancelled.`}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Keep it
          </Button>
          <Button tone="danger" loading={busy} onClick={cancel}>
            Cancel follow-up
          </Button>
        </>
      }
    >
      <p className="text-[13px] text-cx-text-2">{followUp.reason ?? 'Follow-up visit'}</p>
    </Modal>
  );
}
