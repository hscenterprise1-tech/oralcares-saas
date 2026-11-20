import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarOff, Plus } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { EmptyState } from '@/clinic/ui/states';
import { LinkTabs } from '@/clinic/ui/Tabs';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

interface LeaveRow {
  id: number;
  employee: { id: number; name: string | null; staff_type: string | null };
  type: string;
  type_label: string;
  status: 'requested' | 'approved' | 'rejected' | 'cancelled';
  start_date: string;
  end_date: string;
  days: number;
  reason: string | null;
  note: string | null;
  decision_note: string | null;
  decided_by: string | null;
  requested_by: string | null;
  affected: number;
  is_mine: boolean;
}

interface Affected {
  id: number;
  start: string;
  patient: string;
  phone: string | null;
  service: string | null;
  branch: string | null;
  status: string;
}

/** `LeaveController::index`: the server is the contract. */
interface Props {
  leaves: LeaveRow[];
  affected: Affected[] | null;
  focus: number | null;
  filters: { status: string | null };
  staff: { id: number; name: string }[];
  me: { id: number; name: string } | null;
  leaveTypes: Record<string, string>;
  can: { approve: boolean; request: boolean; manageAppointments: boolean };
  links: { index: string; store: string; appointments: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const STATUS: Record<string, [string, string]> = {
  requested: ['Waiting for a decision', 'bg-cx-amber-bg text-cx-amber-ink'],
  approved: ['Approved', 'bg-cx-green-bg text-cx-green-ink'],
  rejected: ['Rejected', 'bg-cx-rose-bg text-cx-rose-ink'],
  cancelled: ['Cancelled', 'bg-cx-slate-bg text-cx-slate-ink'],
};
const FILTERS: { key: string; label: string; status: string | null }[] = [
  { key: 'all', label: 'All', status: null },
  { key: 'requested', label: 'To decide', status: 'requested' },
  { key: 'upcoming', label: 'Upcoming', status: 'upcoming' },
  { key: 'approved', label: 'Approved', status: 'approved' },
  { key: 'rejected', label: 'Rejected', status: 'rejected' },
];
/** Today as the person sees it (not UTC, which is a day behind in the early hours). */
const localToday = () => {
  const now = new Date();

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};
const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
const range = (l: LeaveRow) => (l.start_date === l.end_date ? day(l.start_date) : `${day(l.start_date)} to ${day(l.end_date)}`);

export default function LeaveIndex(props: Props) {
  return (
    <ClinicPortalShell title="Leave">
      <LeavePage {...props} />
    </ClinicPortalShell>
  );
}

/** Time off: staff ask for it, whoever may approve decides, and every leave shows the booked appointments it affects. Approving never moves an appointment. */
function LeavePage({ leaves, affected, focus, filters, staff, me, leaveTypes, can, links }: Props) {
  const [requesting, setRequesting] = useState(false);
  const [deciding, setDeciding] = useState<{ leave: LeaveRow; action: 'approve' | 'reject' } | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const focused = leaves.find((l) => l.id === focus) ?? null;
  const toDecide = leaves.filter((l) => l.status === 'requested').length;
  const href = (status: string | null) => (status ? `${links.index}?status=${status}` : links.index);

  const cancel = (leave: LeaveRow) =>
    router.post(`${links.index}/${leave.id}/cancel`, {}, { preserveScroll: true, onStart: () => setBusyId(leave.id), onFinish: () => setBusyId(null) });

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={CalendarOff}
        title="Leave"
        description={can.approve ? (toDecide > 0 ? `${toDecide} ${toDecide === 1 ? 'request is' : 'requests are'} waiting for you` : 'Time off across the team') : 'Your time off'}
        actions={can.request && <Button tone="primary" onClick={() => setRequesting(true)}><Plus className="h-4 w-4" aria-hidden="true" /> Request leave</Button>}
      />

      {can.approve && (
        <LinkTabs
          label="Leave filter"
          className="mb-4"
          tabs={FILTERS.map((f) => ({ key: f.key, label: f.label, href: href(f.status), active: (filters.status ?? null) === f.status }))}
        />
      )}

      {leaves.length === 0 ? (
        <EmptyState title="No leave" description={filters.status ? 'Nothing matches this filter.' : 'Nobody has asked for time off yet.'} action={can.request ? <Button tone="primary" onClick={() => setRequesting(true)}>Request leave</Button> : undefined} />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {leaves.map((leave) => {
            const [label, tone] = STATUS[leave.status] ?? [humanize(leave.status), 'bg-cx-slate-bg text-cx-slate-ink'];
            const open = leave.status === 'requested' || (leave.status === 'approved' && leave.end_date >= localToday());

            return (
              <li key={leave.id} className={cn('flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4', leave.id === focus && 'bg-cx-blue-bg/30')}>
                <PersonAvatar name={leave.employee.name ?? '?'} />
                <div className="min-w-0 flex-1 basis-56">
                  <p className="truncate text-[14px] font-semibold text-cx-nav-ink">{leave.employee.name}{leave.is_mine && <span className="ml-2 text-[11.5px] font-semibold text-cx-text-3">You</span>}</p>
                  <p className="text-[12.5px] text-cx-text-2">{leave.type_label} · {range(leave)} · {leave.days} {leave.days === 1 ? 'day' : 'days'}</p>
                  {(leave.reason || leave.decision_note) && <p className="mt-0.5 text-[12px] text-cx-text-3">{leave.decision_note ? `${leave.decided_by ?? 'Decision'}: ${leave.decision_note}` : leave.reason}</p>}
                </div>
                {leave.affected > 0 && leave.status !== 'rejected' && leave.status !== 'cancelled' && (
                  <Link href={`${links.index}?leave=${leave.id}${filters.status ? `&status=${filters.status}` : ''}`} preserveScroll className="text-[12.5px] font-semibold text-[#B54708] hover:underline">
                    {leave.affected} {leave.affected === 1 ? 'appointment' : 'appointments'} affected
                  </Link>
                )}
                <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', tone)}>{label}</span>
                <div className="flex items-center gap-2">
                  {can.approve && leave.status === 'requested' && (
                    <>
                      <Button size="sm" onClick={() => setDeciding({ leave, action: 'reject' })}>Reject</Button>
                      <Button size="sm" tone="primary" onClick={() => setDeciding({ leave, action: 'approve' })}>Approve</Button>
                    </>
                  )}
                  {open && (leave.is_mine || can.approve) && (
                    <Button size="sm" onClick={() => cancel(leave)} disabled={busyId === leave.id}>Cancel leave</Button>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {requesting && <RequestDialog staff={staff} me={me} leaveTypes={leaveTypes} canChoose={can.approve} url={links.store} onClose={() => setRequesting(false)} />}
      {deciding && <DecisionDialog {...deciding} base={links.index} onClose={() => setDeciding(null)} />}
      {focused && affected && focused.affected > 0 && (
        <Modal
          open
          onOpenChange={(open) => !open && router.get(links.index, filters.status ? { status: filters.status } : {}, { preserveScroll: true })}
          size="lg"
          title={`Appointments affected by ${focused.employee.name}'s leave`}
          description="These stay booked. Move or cancel each one yourself."
          footer={can.manageAppointments ? <Link href={links.appointments} className="inline-flex h-10 items-center rounded-xl bg-[#1769AA] px-4 text-[13.5px] font-semibold text-white">Open calendar</Link> : undefined}
        >
          {affected.length === 0 ? (
            <p className="text-[13px] text-cx-text-2">None are booked.</p>
          ) : (
            <ul className="divide-y divide-cx-line/60">
              {affected.map((a) => (
                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13.5px]">
                  <span><span className="font-semibold text-cx-nav-ink">{a.patient}</span>{a.service ? ` · ${a.service}` : ''}{a.branch ? ` · ${a.branch}` : ''}{a.phone ? ` · ${a.phone}` : ''}</span>
                  <span className="text-cx-text-2">{new Date(a.start).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</span>
                </li>
              ))}
            </ul>
          )}
        </Modal>
      )}
    </div>
  );
}

function RequestDialog({ staff, me, leaveTypes, canChoose, url, onClose }: { staff: Props['staff']; me: Props['me']; leaveTypes: Record<string, string>; canChoose: boolean; url: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const today = localToday();
  const [f, setF] = useState({ employee_id: String(me?.id ?? ''), leave_type: Object.keys(leaveTypes)[0] ?? 'casual', start_date: today, end_date: today, reason: '' });
  const [busy, setBusy] = useState(false);
  const people = me && !staff.some((s) => s.id === me.id) ? [me, ...staff] : staff;

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="md"
      title="Request leave"
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button
            tone="primary"
            loading={busy}
            disabled={f.employee_id === '' || f.end_date < f.start_date}
            onClick={() => router.post(url, { ...f, employee_id: Number(f.employee_id), reason: f.reason || null } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}
          >
            Send request
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {(errors.start_date || errors.end_date || errors.employee_id) && <Notice tone="danger">{errors.start_date ?? errors.end_date ?? errors.employee_id}</Notice>}
        {canChoose && people.length > 0 && (
          <Field label="For" htmlFor="lv-who"><Select id="lv-who" value={f.employee_id} onChange={(e) => setF({ ...f, employee_id: e.target.value })} options={people.map((p) => ({ value: String(p.id), label: p.name }))} /></Field>
        )}
        <Field label="Kind of leave" htmlFor="lv-type"><Select id="lv-type" value={f.leave_type} onChange={(e) => setF({ ...f, leave_type: e.target.value })} options={Object.entries(leaveTypes).map(([value, label]) => ({ value, label }))} /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="From" htmlFor="lv-from"><Input id="lv-from" type="date" min={today} value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value, end_date: e.target.value > f.end_date ? e.target.value : f.end_date })} /></Field>
          <Field label="To" htmlFor="lv-to"><Input id="lv-to" type="date" min={f.start_date} value={f.end_date} onChange={(e) => setF({ ...f, end_date: e.target.value })} /></Field>
        </div>
        <Field label="Reason" htmlFor="lv-reason"><Textarea id="lv-reason" value={f.reason} maxLength={500} onChange={(e) => setF({ ...f, reason: e.target.value })} /></Field>
      </div>
    </Modal>
  );
}

function DecisionDialog({ leave, action, base, onClose }: { leave: LeaveRow; action: 'approve' | 'reject'; base: string; onClose: () => void }) {
  const [note, setNote] = useState('');
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title={`${action === 'approve' ? 'Approve' : 'Reject'} ${leave.employee.name}'s leave?`}
      description={`${leave.type_label} · ${range(leave)}`}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone={action === 'approve' ? 'primary' : 'danger'} loading={busy} onClick={() => router.post(`${base}/${leave.id}/${action}`, { note: note || null } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}>
            {action === 'approve' ? 'Approve' : 'Reject'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {action === 'approve' && leave.affected > 0 && <Notice tone="warning">{leave.affected} booked {leave.affected === 1 ? 'appointment falls' : 'appointments fall'} in these days. They stay booked until you move them.</Notice>}
        <Field label="Note (optional)" htmlFor="lv-note"><Textarea id="lv-note" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} /></Field>
      </div>
    </Modal>
  );
}
