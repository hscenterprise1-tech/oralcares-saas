import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CalendarOff, Check, Phone, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PageHeader } from '@/clinic/page/PageHeader';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button, buttonClasses } from '@/clinic/ui/Button';
import { Field, Modal, textareaClass } from '@/clinic/ui/Modal';
import { LEAVE_STATUS, RequestLeaveDialog } from '../Staff/components/Sections';
import { Chip, Empty, formatDate } from '../Staff/lib/ui';

interface LeaveItem {
  id: number;
  employee: { id: number; name: string | null; staff_type: string | null };
  type: string;
  type_label: string;
  status: string;
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
  branch_id: number;
  status: string;
}

const FILTERS = [
  { value: '', label: 'All' },
  { value: 'requested', label: 'Pending' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
];

/**
 * Leave: request your own, and (with approval rights) decide requests and
 * review the appointments a leave affects. Nothing is moved or cancelled
 * automatically; each affected appointment opens in the calendar to
 * reschedule, reassign or cancel.
 */
export default function LeaveIndex({
  leaves,
  affected,
  focus,
  filters,
  staff,
  me,
  leaveTypes,
  can,
  links,
}: {
  leaves: LeaveItem[];
  affected: Affected[] | null;
  focus: number | null;
  filters: { status: string | null };
  staff: { id: number; name: string }[];
  me: { id: number; name: string } | null;
  leaveTypes: Record<string, string>;
  can: { approve: boolean; request: boolean; manageAppointments: boolean };
  links: { index: string; store: string; appointments: string };
}) {
  const [requesting, setRequesting] = useState(false);
  const [deciding, setDeciding] = useState<{ leave: LeaveItem; action: 'approve' | 'reject' } | null>(null);
  const focusLeave = leaves.find((leave) => leave.id === focus) ?? null;
  const pending = leaves.filter((leave) => leave.status === 'requested').length;

  const cancel = (leave: LeaveItem) => {
    if (window.confirm('Cancel this leave?')) {
      router.post(`${links.index}/${leave.id}/cancel`, {}, { preserveScroll: true });
    }
  };

  const time = (value: string) => {
    const [hours, minutes] = value.slice(11, 16).split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}${minutes ? `:${String(minutes).padStart(2, '0')}` : ''} ${hours < 12 ? 'am' : 'pm'}`;
  };

  return (
    <ClinicPortalShell title="Leave">
      <PageHeader
        eyebrow={can.approve ? 'Administration' : 'My leave'}
        title="Leave"
        description={can.approve ? 'Requests from your team, and the appointments each leave affects.' : 'Ask for time off and see where your requests stand.'}
        actions={(can.request && (me || can.approve)) ? <Button tone="primary" icon={CalendarOff} onClick={() => setRequesting(true)}>Request leave</Button> : undefined}
      />

      {can.approve && pending > 0 && (
        <p className="mb-4 flex items-center gap-2 rounded-2xl border border-sky-200 bg-sky-50/95 px-4 py-3 text-[13px] font-semibold text-sky-900">
          <CalendarClock className="h-4 w-4" aria-hidden="true" /> {pending} {pending === 1 ? 'request is' : 'requests are'} waiting for a decision.
        </p>
      )}

      <div role="tablist" aria-label="Leave status" className="mb-4 flex max-w-full gap-0.5 overflow-x-auto rounded-full border border-white/70 bg-white/[0.93] p-1 backdrop-blur-sm [scrollbar-width:none] sm:inline-flex">
        {FILTERS.map((filter) => (
          <Link
            key={filter.value}
            role="tab"
            aria-selected={(filters.status ?? '') === filter.value}
            href={links.index}
            data={filter.value ? { status: filter.value } : {}}
            preserveScroll
            className={cn(
              'inline-flex h-11 shrink-0 items-center rounded-full px-3.5 text-[12.5px] font-semibold sm:h-9',
              (filters.status ?? '') === filter.value ? 'bg-clinic-ink text-white' : 'text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink',
            )}
          >
            {filter.label}
          </Link>
        ))}
      </div>

      <div className={cn('grid items-start gap-4', focusLeave && 'xl:grid-cols-[minmax(0,1fr)_420px]')}>
        <section aria-label="Leave requests" className="overflow-hidden rounded-3xl border border-white/70 bg-white/[0.93] backdrop-blur-sm">
          {leaves.length === 0 ? (
            <div className="p-5"><Empty>No leave here.</Empty></div>
          ) : (
            <ul className="divide-y divide-clinic-border">
              {leaves.map((leave) => {
                const status = LEAVE_STATUS[leave.status] ?? LEAVE_STATUS.cancelled;
                const upcoming = leave.end_date >= new Date().toISOString().slice(0, 10);

                return (
                  <li key={leave.id} className={cn('flex flex-wrap items-center gap-3 px-4 py-3.5', focus === leave.id && 'bg-clinic-brand-soft/40')}>
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="text-[14px] font-bold text-clinic-ink">{leave.employee.name}</span>
                        <Chip className={status.chip}>{status.label}</Chip>
                      </span>
                      <span className="block text-[12.5px] font-semibold text-clinic-ink">
                        {formatDate(leave.start_date)}{leave.end_date !== leave.start_date ? ` – ${formatDate(leave.end_date)}` : ''} · {leave.days} {leave.days === 1 ? 'day' : 'days'} · {leave.type_label}
                      </span>
                      {(leave.reason || leave.decision_note) && (
                        <span className="block text-[12px] text-clinic-secondary">{[leave.reason, leave.decision_note ? `Decision: ${leave.decision_note}` : null, leave.decided_by ? `by ${leave.decided_by}` : null].filter(Boolean).join(' · ')}</span>
                      )}
                    </span>
                    {leave.affected > 0 && (
                      <Link href={links.index} data={{ leave: leave.id, ...(filters.status ? { status: filters.status } : {}) }} preserveScroll className="inline-flex min-h-11 items-center gap-1 rounded-full bg-amber-50 px-3 text-[12px] font-bold text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100 sm:min-h-9">
                        <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" /> {leave.affected} affected
                      </Link>
                    )}
                    <span className="flex flex-wrap gap-1.5">
                      {can.approve && leave.status === 'requested' && (
                        <>
                          <Button size="sm" tone="primary" icon={Check} onClick={() => setDeciding({ leave, action: 'approve' })}>Approve</Button>
                          <Button size="sm" icon={X} onClick={() => setDeciding({ leave, action: 'reject' })}>Reject</Button>
                        </>
                      )}
                      {(leave.is_mine || can.approve) && ['requested', 'approved'].includes(leave.status) && upcoming && (
                        <Button size="sm" tone="ghost" onClick={() => cancel(leave)}>Cancel</Button>
                      )}
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </section>

        {focusLeave && affected && (
          <section aria-label="Affected appointments" className="rounded-3xl border border-amber-200 bg-white/[0.95] p-4 backdrop-blur-sm sm:p-5">
            <div className="mb-3 flex items-start justify-between gap-2">
              <div>
                <h2 className="text-[15px] font-bold text-clinic-ink">{affected.length} {affected.length === 1 ? 'appointment is' : 'appointments are'} affected</h2>
                <p className="text-[12px] text-clinic-secondary">{focusLeave.employee.name}, {formatDate(focusLeave.start_date)}{focusLeave.end_date !== focusLeave.start_date ? ` – ${formatDate(focusLeave.end_date)}` : ''}. Open each one to reschedule, give it to another doctor, or cancel.</p>
              </div>
              <Link href={links.index} data={filters.status ? { status: filters.status } : {}} aria-label="Close" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset">
                <X className="h-4 w-4" aria-hidden="true" />
              </Link>
            </div>
            {affected.length === 0 ? (
              <Empty>No booked appointments fall in this leave.</Empty>
            ) : (
              <ul className="divide-y divide-clinic-border rounded-2xl ring-1 ring-clinic-border">
                {affected.map((appointment) => (
                  <li key={appointment.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                    <span className="w-24 shrink-0 text-[12px] font-bold tabular-nums text-clinic-ink">
                      {formatDate(appointment.start.slice(0, 10))}
                      <span className="block font-semibold text-clinic-secondary">{time(appointment.start)}</span>
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[13px] font-bold text-clinic-ink">{appointment.patient}</span>
                      <span className="block truncate text-[11.5px] text-clinic-secondary">{[appointment.service, appointment.branch].filter(Boolean).join(' · ')}</span>
                    </span>
                    {appointment.phone && (
                      <a href={`tel:${appointment.phone}`} aria-label={`Call ${appointment.patient}`} className="flex h-11 w-11 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset sm:h-9 sm:w-9">
                        <Phone className="h-4 w-4" aria-hidden="true" />
                      </a>
                    )}
                    {can.manageAppointments && (
                      <Link href={`${links.appointments}?view=day&date=${appointment.start.slice(0, 10)}&providers[]=${focusLeave.employee.id}&appointment=${appointment.id}`} className={buttonClasses('secondary', 'sm')}>
                        Review
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            )}
            <p className="mt-3 text-[11.5px] text-clinic-secondary">Patients are not notified automatically yet; call them from here.</p>
          </section>
        )}
      </div>

      {requesting && <RequestLeaveDialog url={links.store} leaveTypes={leaveTypes} employeeId={null} staff={can.approve ? staff.filter((member) => member.id !== me?.id) : undefined} onClose={() => setRequesting(false)} />}
      {deciding && <DecisionDialog leave={deciding.leave} action={deciding.action} url={links.index} onClose={() => setDeciding(null)} />}
    </ClinicPortalShell>
  );
}

function DecisionDialog({ leave, action, url, onClose }: { leave: LeaveItem; action: 'approve' | 'reject'; url: string; onClose: () => void }) {
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = () => {
    setSaving(true);
    router.post(`${url}/${leave.id}/${action}`, { note: note || null }, { preserveScroll: true, onSuccess: onClose, onError: (errors) => setError(Object.values(errors)[0] ?? 'This could not be saved.'), onFinish: () => setSaving(false) });
  };

  return (
    <Modal
      open
      size="sm"
      onOpenChange={(open) => !open && onClose()}
      title={`${action === 'approve' ? 'Approve' : 'Reject'} leave for ${leave.employee.name}`}
      description={`${formatDate(leave.start_date)}${leave.end_date !== leave.start_date ? ` – ${formatDate(leave.end_date)}` : ''} · ${leave.type_label}${action === 'approve' && leave.affected > 0 ? `. ${leave.affected} booked ${leave.affected === 1 ? 'appointment needs' : 'appointments need'} review after approval.` : ''}`}
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Back</Button>
          <Button tone={action === 'approve' ? 'primary' : 'danger'} loading={saving} loadingLabel="Saving…" onClick={submit}>{action === 'approve' ? 'Approve' : 'Reject'}</Button>
        </>
      }
    >
      <Field label="Note (optional)" htmlFor="ld-note" error={error}>
        <textarea id="ld-note" rows={2} maxLength={500} className={textareaClass} value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>
    </Modal>
  );
}
