import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { CalendarCheck, CalendarClock, CalendarDays, CalendarX2, Clock, Plus, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import type { HistoryRow, Patient360Props, VisitsPage } from '../../types';
import { useEndpoint } from '../store';
import { BTN_SM, Card, Chips, Empty, Facts, Figure, Pager, Pending, Pill, Row, Split, day, humanize, type Paged, type Tone } from '../ui';

export type AppointmentRow = { id: number; date_label: string; time_label: string; duration: number | null; practitioner: string | null; branch: string | null; type: string | null; status: string | null; status_label: string | null; notes: string | null };
export type FollowUpRow = {
  id: number;
  due_on: string;
  reason: string | null;
  practitioner: string | null;
  status: string;
  overdue: boolean;
  source: string | null;
  source_url: string | null;
  booked_for: string | null;
  actions: { reschedule_url: string; complete_url: string; cancel_url: string } | null;
};

export const VISIT_STATUS: Record<string, [string, Tone]> = {
  registered: ['Registered', 'slate'],
  completed: ['Completed', 'mint'],
  checked_in: ['Checked in', 'blue'],
  in_queue: ['In queue', 'blue'],
  with_doctor: ['With doctor', 'violet'],
  in_progress: ['With doctor', 'violet'],
  waiting: ['Waiting', 'amber'],
  cancelled: ['Cancelled', 'slate'],
  missed: ['Missed', 'rose'],
  not_attended: ['Not attended', 'slate'],
  no_visit_recorded: ['No visit recorded', 'slate'],
};
const CONSULTATION: Record<string, [string, Tone]> = { signed: ['Consultation signed', 'mint'], open: ['Consultation open', 'amber'], voided: ['Consultation voided', 'rose'] };

export function visitStatus(status: string): [string, Tone] {
  return VISIT_STATUS[status] ?? [humanize(status), 'slate'];
}

function VisitDialog({ row, onClose }: { row: HistoryRow; onClose: () => void }) {
  const [label, tone] = visitStatus(row.status);
  const consultation = row.kind === 'visit' ? row.consultation : null;

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={row.kind === 'visit' ? 'Visit' : 'Booking not attended'}
      description={[day(row.date), row.time_label].filter(Boolean).join(' · ')}
      size="md"
      footer={<Button tone="primary" onClick={onClose}>Close</Button>}
    >
      <div className="space-y-4">
        <p className="flex flex-wrap gap-1.5">
          <Pill tone={tone}>{label}</Pill>
          {row.kind === 'visit' && row.registration_type && <Pill tone={row.registration_type === 'new' ? 'blue' : 'violet'}>{row.registration_type === 'new' ? 'New visit' : 'Repeat visit'}</Pill>}
          {consultation && <Pill tone={(CONSULTATION[consultation.status] ?? ['', 'slate'])[1]}>{(CONSULTATION[consultation.status] ?? [humanize(consultation.status)])[0]}</Pill>}
        </p>
        <Facts
          items={[
            ['Treating practitioner', row.practitioner],
            ['Branch', row.branch],
            ['Visit type', row.kind === 'visit' ? row.visit_type : row.type],
            ['Booked for', row.kind === 'visit' ? row.booked_for : null],
          ]}
        />
        {row.kind === 'visit' && row.reason && (
          <div className="rounded-2xl bg-cx-blue-bg/40 px-4 py-3 text-[13px] text-cx-text [overflow-wrap:anywhere]">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-cx-blue-ink">Reason for visit</p>
            {row.reason}
          </div>
        )}
        <p className="text-[12.5px] text-cx-text-2">
          {consultation
            ? 'Notes, diagnosis, procedures, prescriptions and orders of this visit are in its consultation.'
            : row.kind === 'visit'
              ? 'No consultation was recorded for this visit.'
              : 'The patient did not come for this booking.'}
        </p>
        <div className="flex flex-wrap gap-2">
          {consultation?.url && (
            <Link href={consultation.url} className={BTN_SM}>
              Open consultation
            </Link>
          )}
          {row.calendar_url && (
            <Link href={row.calendar_url} className={BTN_SM}>
              Open the day in Appointments
            </Link>
          )}
        </div>
      </div>
    </Modal>
  );
}

function Visits({ endpoint }: { endpoint: string }) {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<HistoryRow | null>(null);
  const state = useEndpoint<VisitsPage>(endpoint, page > 1 ? { page } : undefined);

  return (
    <Card title="Visit history" caption="Newest first · choose a visit for its details" icon={CalendarDays} tone="teal">
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : state.data.data.length === 0 ? (
        <Empty>No visits yet.</Empty>
      ) : (
        <ul className={cn('space-y-2', state.loading && 'opacity-60')}>
          {state.data.data.map((row) => {
            const [label, tone] = visitStatus(row.status);

            return (
              <li key={`${row.kind}-${row.id}`}>
                <Row
                  icon={row.attended ? CalendarCheck : CalendarX2}
                  tone={row.attended ? 'teal' : 'slate'}
                  onOpen={() => setOpen(row)}
                  title={[day(row.date), row.time_label].filter(Boolean).join(' · ')}
                  meta={[row.kind === 'visit' ? row.reason : row.type, row.practitioner, row.branch].filter(Boolean).join(' · ') || '—'}
                  aside={
                    <>
                      {row.kind === 'visit' && row.registration_type && <Pill tone={row.registration_type === 'new' ? 'blue' : 'violet'}>{row.registration_type === 'new' ? 'New' : 'Repeat'}</Pill>}
                      <Pill tone={tone}>{label}</Pill>
                    </>
                  }
                />
              </li>
            );
          })}
        </ul>
      )}
      {state.data && <Pager page={state.data.meta.page} last={state.data.meta.last_page} onPage={setPage} busy={state.loading} />}
      {open && <VisitDialog row={open} onClose={() => setOpen(null)} />}
    </Card>
  );
}

/** Appointments, upcoming or past; compact (Overview) shows the next few only. */
export function Appointments({ endpoint, compact = false, action }: { endpoint: string; compact?: boolean; action?: ReactNode }) {
  const [scope, setScope] = useState<'upcoming' | 'history'>('upcoming');
  const state = useEndpoint<Paged<AppointmentRow>>(endpoint, scope === 'history' ? { scope } : undefined);
  const rows = (state.data?.data ?? []).slice(0, compact ? 3 : undefined);

  return (
    <Card title="Appointments" caption={compact ? 'Upcoming' : undefined} icon={CalendarClock} tone="blue" action={action}>
      {!compact && (
        <div className="mb-3">
          <Chips label="Appointments" value={scope} onChange={setScope} options={[{ value: 'upcoming', label: 'Upcoming' }, { value: 'history', label: 'Past' }]} />
        </div>
      )}
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : rows.length === 0 ? (
        <Empty>{scope === 'upcoming' ? 'Nothing booked ahead.' : 'No past appointments.'}</Empty>
      ) : (
        <ul className="space-y-2">
          {rows.map((row) => (
            <li key={row.id}>
              <Row
                title={`${row.date_label}, ${row.time_label}`}
                meta={[row.practitioner, row.type, row.branch].filter(Boolean).join(' · ')}
                aside={row.status_label ? <Pill tone={row.status === 'cancelled' ? 'slate' : row.status === 'no_show' ? 'rose' : 'blue'}>{row.status_label}</Pill> : undefined}
              >
                {!compact && row.notes && <p className="text-[12px] text-cx-text-3 [overflow-wrap:anywhere]">{row.notes}</p>}
              </Row>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

const FOLLOW_UP: Record<string, Tone> = { pending: 'amber', completed: 'mint', cancelled: 'slate' };

/** Follow-ups with the actions the server offers; compact (Overview) shows the open ones first, three at most. */
export function FollowUps({ endpoint, practitioners, compact = false, action }: { endpoint: string; practitioners: { id: number; name: string }[]; compact?: boolean; action?: ReactNode }) {
  const state = useEndpoint<{ data: FollowUpRow[]; add_url: string | null }>(endpoint);
  const [adding, setAdding] = useState(false);
  const [moving, setMoving] = useState<FollowUpRow | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const done = {
    preserveScroll: true,
    preserveState: true,
    onError: (errors: Record<string, string>) => setProblem(Object.values(errors)[0] ?? 'Not done.'),
    onSuccess: () => {
      setProblem(null);
      setAdding(false);
      setMoving(null);
      void state.reload();
    },
  };
  const all = state.data?.data ?? [];
  const rows = compact ? [...all].sort((a, b) => Number(b.status === 'pending') - Number(a.status === 'pending')).slice(0, 3) : all;

  return (
    <Card
      title="Follow-ups"
      icon={Clock}
      tone="amber"
      action={
        <span className="flex items-center gap-1">
          {state.data?.add_url && (
            <button type="button" className={BTN_SM} onClick={() => setAdding(true)}>
              <Plus className="h-3.5 w-3.5" aria-hidden="true" /> Add
            </button>
          )}
          {action}
        </span>
      }
    >
      {problem && <p role="alert" className="mb-2 text-[12.5px] font-medium text-cx-rose-ink">{problem}</p>}
      {!state.data ? (
        <Pending error={state.error} onRetry={state.reload} />
      ) : rows.length === 0 ? (
        <Empty>No follow-ups.</Empty>
      ) : (
        <ul className="space-y-2">
          {rows.map((row) => (
            <li key={row.id}>
              <Row
                title={`Due ${day(row.due_on)}`}
                meta={[row.reason, row.practitioner ? `with ${row.practitioner}` : null, row.booked_for ? `booked ${row.booked_for}` : null].filter(Boolean).join(' · ') || undefined}
                aside={<Pill tone={row.overdue ? 'rose' : (FOLLOW_UP[row.status] ?? 'slate')}>{row.overdue ? 'Overdue' : humanize(row.status)}</Pill>}
              >
                {row.actions && (
                  <span className="flex flex-wrap gap-1.5">
                    <button type="button" className={BTN_SM} onClick={() => router.post(row.actions!.complete_url, {}, done)}>
                      Complete
                    </button>
                    <button type="button" className={BTN_SM} onClick={() => setMoving(row)}>
                      Reschedule
                    </button>
                    <button type="button" className={BTN_SM} onClick={() => router.post(row.actions!.cancel_url, {}, done)}>
                      Cancel
                    </button>
                  </span>
                )}
              </Row>
            </li>
          ))}
        </ul>
      )}
      {adding && state.data?.add_url && <FollowUpDialog title="Add a follow-up" practitioners={practitioners} onClose={() => setAdding(false)} onSave={(data) => router.post(state.data!.add_url as string, data, done)} />}
      {moving?.actions && <FollowUpDialog title="Reschedule the follow-up" initial={moving.due_on} onClose={() => setMoving(null)} onSave={(data) => router.patch(moving.actions!.reschedule_url, { due_on: data.due_on }, done)} />}
    </Card>
  );
}

function FollowUpDialog({ title, initial, practitioners, onClose, onSave }: { title: string; initial?: string; practitioners?: { id: number; name: string }[]; onClose: () => void; onSave: (data: { due_on: string; reason?: string | null; practitioner_id?: number | null }) => void }) {
  const [due, setDue] = useState(initial ?? '');
  const [reason, setReason] = useState('');
  const [doctor, setDoctor] = useState('');

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={title}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Cancel</Button>
          <Button tone="primary" disabled={!due} onClick={() => onSave({ due_on: due, reason: reason.trim() || null, practitioner_id: doctor ? Number(doctor) : null })}>Save</Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Due on" htmlFor="fu-due" required>
          <Input id="fu-due" type="date" value={due} onChange={(event) => setDue(event.target.value)} />
        </Field>
        {practitioners && (
          <>
            <Field label="Reason" htmlFor="fu-reason">
              <Input id="fu-reason" value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />
            </Field>
            <Field label="Doctor" htmlFor="fu-doctor">
              <Select id="fu-doctor" value={doctor} onChange={(event) => setDoctor(event.target.value)} options={[{ value: '', label: 'Any doctor' }, ...practitioners.map((item) => ({ value: String(item.id), label: item.name }))]} />
            </Field>
          </>
        )}
      </div>
    </Modal>
  );
}

/**
 * Visits (zone spec §4): the counts the server verifies, every visit with who
 * saw the patient and why (each opens its details), then appointments and
 * follow-ups. Patient-wide.
 */
export function VisitsZone({ props, sections }: { props: Patient360Props; sections: string[] }) {
  const { endpoints, state, overview } = props;
  const visits = useEndpoint<VisitsPage>(sections.includes('visits') ? endpoints.visits : null);
  const upcoming = overview?.summary.appointments?.upcoming;

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Figure icon={CalendarCheck} tone="teal" label="Total visits" value={visits.data ? visits.data.counts.visits : '—'} detail="Visits the patient came for" />
        <Figure icon={CalendarX2} tone="slate" label="Not attended" value={visits.data ? visits.data.counts.not_attended : '—'} detail="Missed or cancelled bookings" />
        <Figure icon={UserRound} tone="violet" label="Last visit" value={state.last_visit ? day(state.last_visit) : '—'} />
        <Figure icon={CalendarClock} tone="blue" label="Upcoming" value={state.next_appointment ? (state.next_appointment.is_today ? 'Today' : state.next_appointment.date_label) : 'None'} detail={state.next_appointment ? [state.next_appointment.time_label, upcoming && upcoming > 1 ? `${upcoming} booked` : null].filter(Boolean).join(' · ') : 'Nothing booked'} />
      </div>
      <Split
        main={sections.includes('visits') && endpoints.visits ? <Visits endpoint={endpoints.visits} /> : null}
        side={[
          sections.includes('appointments') && endpoints.appointments && <Appointments key="appointments" endpoint={endpoints.appointments} />,
          sections.includes('follow-ups') && endpoints['follow-ups'] && <FollowUps key="follow-ups" endpoint={endpoints['follow-ups'] as string} practitioners={props.practitioners} />,
        ]}
      />
    </div>
  );
}
