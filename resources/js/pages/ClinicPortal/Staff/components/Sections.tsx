import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, CalendarOff, KeyRound, Mail, Phone, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, buttonClasses } from '@/clinic/ui/Button';
import { Field, inputClass, Modal, selectClass, textareaClass } from '@/clinic/ui/Modal';
import type { LeaveRow, ProfileProps } from '../lib/types';
import { Chip, DOC_STATE, Empty, Fact, formatDate, relativeTime, Section } from '../lib/ui';

export const LEAVE_STATUS: Record<string, { label: string; chip: string }> = {
  requested: { label: 'Pending', chip: 'bg-sky-50 text-sky-700 ring-sky-200' },
  approved: { label: 'Approved', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
  rejected: { label: 'Rejected', chip: 'bg-rose-50 text-rose-700 ring-rose-200' },
  cancelled: { label: 'Cancelled', chip: 'bg-slate-100 text-slate-600 ring-slate-200' },
};

export function Overview({ props, onTab }: { props: ProfileProps; onTab: (tab: string) => void }) {
  const { employee, practitioner, documents, leave, appointments, services } = props;
  const nextLeave = leave.find((row) => ['requested', 'approved'].includes(row.status) && row.end_date >= new Date().toISOString().slice(0, 10));
  const attention = documents.items.filter((item) => ['expired', 'expiring_soon', 'rejected', 'pending_verification'].includes(item.state));

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <Section title="Contact & employment" className="lg:col-span-2">
        <dl className="grid gap-4 sm:grid-cols-3">
          <Fact label="Mobile" value={employee.phone && <a href={`tel:${employee.phone}`} className="inline-flex items-center gap-1 text-clinic-brand-strong hover:underline"><Phone className="h-3.5 w-3.5" aria-hidden="true" />{employee.phone}</a>} />
          <Fact label="Email" value={employee.email && <a href={`mailto:${employee.email}`} className="inline-flex min-h-11 max-w-full items-center gap-1 truncate text-clinic-brand-strong hover:underline sm:min-h-0"><Mail className="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span className="truncate">{employee.email}</span></a>} />
          <Fact label="Staff type" value={employee.staff_type_label} />
          <Fact label="Department" value={employee.departments.join(', ')} />
          <Fact label="Joined" value={formatDate(employee.joining_date)} />
          <Fact label="Date of birth" value={formatDate(employee.date_of_birth)} />
          <Fact label="Branches" value={employee.branches.map((branch) => branch.name).join(', ')} />
          <Fact label="Roles" value={employee.roles.join(', ')} />
          <Fact label="Last activity" value={employee.last_activity ? relativeTime(employee.last_activity) : null} />
          {employee.notes && (
            <div className="sm:col-span-3">
              <Fact label="Notes" value={employee.notes} />
            </div>
          )}
        </dl>
      </Section>

      <div className="grid content-start gap-4">
        <button type="button" onClick={() => onTab('documents')} className="rounded-3xl border border-white/70 bg-gradient-to-br from-sky-50 to-teal-50 p-4 text-left hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong">
          <p className="text-[12px] font-semibold text-sky-900">Documents</p>
          <p className="mt-1 text-[22px] font-bold tabular-nums text-sky-950">{documents.summary.complete}/{documents.summary.required} <span className="text-[13px] font-semibold">required on file</span></p>
          {attention.length > 0 && (
            <p className="mt-1.5 flex items-center gap-1.5 text-[12px] font-semibold text-amber-800">
              <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" /> {attention.map((item) => `${item.type.name}: ${DOC_STATE[item.state].label}`).slice(0, 2).join('; ')}
            </p>
          )}
        </button>
        {practitioner && services && (
          <button type="button" onClick={() => onTab('services')} className="rounded-3xl border border-white/70 bg-gradient-to-br from-emerald-50 to-teal-50 p-4 text-left hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong">
            <p className="text-[12px] font-semibold text-emerald-900">Services</p>
            <p className="mt-1 text-[22px] font-bold tabular-nums text-emerald-950">{services.selected.length === 0 ? 'All' : services.selected.length} <span className="text-[13px] font-semibold">{services.selected.length === 0 ? 'services (not restricted)' : 'services provided'}</span></p>
          </button>
        )}
        {practitioner && (
          <button type="button" onClick={() => onTab('appointments')} className="rounded-3xl border border-white/70 bg-gradient-to-br from-violet-50 to-sky-50 p-4 text-left hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong">
            <p className="text-[12px] font-semibold text-violet-900">Upcoming appointments</p>
            <p className="mt-1 text-[22px] font-bold tabular-nums text-violet-950">{appointments.length}{appointments.length >= 12 ? '+' : ''}</p>
          </button>
        )}
        {nextLeave && (
          <div className="rounded-3xl border border-amber-200 bg-amber-50/90 p-4">
            <p className="flex items-center gap-1.5 text-[12px] font-semibold text-amber-900"><CalendarOff className="h-3.5 w-3.5" aria-hidden="true" /> {LEAVE_STATUS[nextLeave.status].label} leave</p>
            <p className="mt-1 text-[14px] font-bold text-amber-950">{formatDate(nextLeave.start_date)} – {formatDate(nextLeave.end_date)}</p>
          </div>
        )}
      </div>
    </div>
  );
}

export function LeaveSection({ props }: { props: ProfileProps }) {
  const { leave, can, links, employee, options } = props;
  const [requesting, setRequesting] = useState(false);

  return (
    <Section
      title="Leave"
      aside={
        <div className="flex flex-wrap gap-2">
          {(can.approveLeave || employee.is_self) && <Button size="sm" tone="primary" icon={CalendarOff} onClick={() => setRequesting(true)}>Request leave</Button>}
          <Link href={links.leave} className={buttonClasses('secondary', 'sm')}>All leave</Link>
        </div>
      }
    >
      {leave.length === 0 ? (
        <Empty>No leave recorded.</Empty>
      ) : (
        <ul className="divide-y divide-clinic-border rounded-2xl ring-1 ring-clinic-border">
          {leave.map((row) => (
            <LeaveItem key={row.id} row={row} leaveLink={links.leave} />
          ))}
        </ul>
      )}
      {requesting && <RequestLeaveDialog url={links.leave} leaveTypes={options.leaveTypes} employeeId={employee.is_self ? null : employee.id} name={employee.name} onClose={() => setRequesting(false)} />}
    </Section>
  );
}

function LeaveItem({ row, leaveLink }: { row: LeaveRow; leaveLink: string }) {
  const status = LEAVE_STATUS[row.status] ?? LEAVE_STATUS.cancelled;

  return (
    <li className="flex flex-wrap items-center gap-3 px-3.5 py-3">
      <span className="min-w-0 flex-1">
        <span className="block text-[13.5px] font-bold text-clinic-ink">{formatDate(row.start_date)}{row.end_date !== row.start_date ? ` – ${formatDate(row.end_date)}` : ''}</span>
        <span className="block text-[12px] text-clinic-secondary">{[row.type_label, row.reason].filter(Boolean).join(' · ')}</span>
        {row.decision_note && <span className="block text-[12px] text-clinic-secondary">Note: {row.decision_note}</span>}
      </span>
      {row.affected > 0 && (
        <Link href={`${leaveLink}?leave=${row.id}`} className="inline-flex min-h-11 items-center gap-1 rounded-full bg-amber-50 px-3 text-[12px] font-bold text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100 sm:min-h-8">
          <AlertTriangle className="h-3.5 w-3.5" aria-hidden="true" /> {row.affected} affected
        </Link>
      )}
      <Chip className={status.chip}>{status.label}</Chip>
    </li>
  );
}

export function RequestLeaveDialog({ url, leaveTypes, employeeId, name, staff, onClose }: {
  url: string;
  leaveTypes: Record<string, string>;
  employeeId: number | null;
  name?: string;
  staff?: { id: number; name: string }[];
  onClose: () => void;
}) {
  const today = new Date().toISOString().slice(0, 10);
  const [form, setForm] = useState({ employee_id: employeeId ? String(employeeId) : '', leave_type: Object.keys(leaveTypes)[0] ?? 'casual', start_date: today, end_date: today, reason: '', note: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((current) => ({ ...current, [key]: value }));

  const submit = () => {
    setSaving(true);
    router.post(url, { ...form, employee_id: form.employee_id ? Number(form.employee_id) : null, reason: form.reason || null, note: form.note || null }, {
      preserveScroll: true,
      onSuccess: onClose,
      onError: (received) => setErrors(received as Record<string, string>),
      onFinish: () => setSaving(false),
    });
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title={name ? `Request leave for ${name}` : 'Request leave'}
      description="Approved leave blocks new bookings at once. Booked appointments are listed for review, never moved automatically."
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>Cancel</Button>
          <Button tone="primary" loading={saving} loadingLabel="Sending…" onClick={submit}>Request leave</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        {staff && (
          <Field label="For" htmlFor="lv-employee" error={errors.employee_id} className="sm:col-span-2">
            <select id="lv-employee" className={selectClass} value={form.employee_id} onChange={(e) => set('employee_id', e.target.value)}>
              <option value="">Myself</option>
              {staff.map((member) => (
                <option key={member.id} value={member.id}>{member.name}</option>
              ))}
            </select>
          </Field>
        )}
        <Field label="Leave type" htmlFor="lv-type" error={errors.leave_type} className="sm:col-span-2">
          <select id="lv-type" className={selectClass} value={form.leave_type} onChange={(e) => set('leave_type', e.target.value)}>
            {Object.entries(leaveTypes).map(([value, label]) => (
              <option key={value} value={value}>{label}</option>
            ))}
          </select>
        </Field>
        <Field label="From" htmlFor="lv-from" error={errors.start_date}>
          <input id="lv-from" type="date" min={today} className={inputClass} value={form.start_date} onChange={(e) => setForm((current) => ({ ...current, start_date: e.target.value, end_date: current.end_date < e.target.value ? e.target.value : current.end_date }))} />
        </Field>
        <Field label="To" htmlFor="lv-to" error={errors.end_date}>
          <input id="lv-to" type="date" min={form.start_date} className={inputClass} value={form.end_date} onChange={(e) => set('end_date', e.target.value)} />
        </Field>
        <Field label="Reason" htmlFor="lv-reason" error={errors.reason} className="sm:col-span-2">
          <input id="lv-reason" maxLength={500} className={inputClass} value={form.reason} onChange={(e) => set('reason', e.target.value)} />
        </Field>
        <Field label="Note (optional)" htmlFor="lv-note" error={errors.note} className="sm:col-span-2">
          <textarea id="lv-note" rows={2} maxLength={1000} className={textareaClass} value={form.note} onChange={(e) => set('note', e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}

export function Appointments({ props }: { props: ProfileProps }) {
  const { appointments, links, employee, can } = props;
  const time = (value: string) => {
    const [hours, minutes] = value.slice(11, 16).split(':').map(Number);

    return `${hours % 12 === 0 ? 12 : hours % 12}${minutes ? `:${String(minutes).padStart(2, '0')}` : ''} ${hours < 12 ? 'am' : 'pm'}`;
  };

  return (
    <Section title="Upcoming appointments" aside={can.appointments && <Link href={`${links.appointments}?view=week&providers[]=${employee.id}`} className={buttonClasses('secondary', 'sm')}><CalendarDays className="h-4 w-4" aria-hidden="true" /> Open calendar</Link>}>
      {appointments.length === 0 ? (
        <Empty>Nothing booked ahead.</Empty>
      ) : (
        <ul className="divide-y divide-clinic-border rounded-2xl ring-1 ring-clinic-border">
          {appointments.map((appointment) => (
            <li key={appointment.id} className="flex flex-wrap items-center gap-3 px-3.5 py-3">
              <span className="w-28 shrink-0 text-[12.5px] font-bold tabular-nums text-clinic-ink">
                {formatDate(appointment.start.slice(0, 10))}
                <span className="block font-semibold text-clinic-secondary">{time(appointment.start)}</span>
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[13.5px] font-bold text-clinic-ink">{appointment.patient}</span>
                <span className="block truncate text-[12px] text-clinic-secondary">{[appointment.service, appointment.branch].filter(Boolean).join(' · ')}</span>
              </span>
              <Chip className="bg-blue-50 text-blue-700 ring-blue-200">{appointment.status.replace('_', ' ')}</Chip>
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}

export function Access({ props }: { props: ProfileProps }) {
  const { employee } = props;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Section title="Sign-in">
        <div className={cn('flex items-start gap-3 rounded-2xl p-4', employee.has_login ? 'bg-emerald-50' : 'bg-clinic-canvas')}>
          <KeyRound className={cn('mt-0.5 h-5 w-5 shrink-0', employee.has_login ? 'text-emerald-700' : 'text-clinic-secondary')} aria-hidden="true" />
          <div className="min-w-0">
            <p className="text-[13.5px] font-bold text-clinic-ink">{employee.has_login ? 'Can sign in' : employee.invitation ? 'Invitation sent' : 'No sign-in yet'}</p>
            <p className="text-[12.5px] text-clinic-secondary">
              {employee.has_login
                ? `Signs in as ${employee.login_email}.`
                : employee.invitation
                  ? `Sent to ${employee.invitation.email}${employee.invitation.expires_at ? `, valid until ${formatDate(employee.invitation.expires_at.slice(0, 10))}` : ''}.`
                  : 'Use "Invite to sign in" above to give them access.'}
            </p>
          </div>
        </div>
      </Section>
      <Section title="Roles and branch access">
        <dl className="grid gap-4">
          <Fact label="Roles" value={employee.roles.length ? (
            <span className="flex flex-wrap gap-1">{employee.roles.map((role) => <Chip key={role} className="bg-violet-50 text-violet-800 ring-violet-200"><ShieldCheck className="h-3 w-3" aria-hidden="true" />{role}</Chip>)}</span>
          ) : null} />
          <Fact label="Works at" value={employee.branches.map((branch) => `${branch.name}${branch.is_active ? '' : ' (paused)'}`).join(', ') || 'Every branch'} />
        </dl>
        <p className="mt-3 text-[11.5px] text-clinic-secondary">Change roles and branches with Edit. A role change needs permission to manage roles.</p>
      </Section>
    </div>
  );
}

const EVENT_LABELS: Record<string, string> = {
  'staff.created': 'Added to staff',
  'staff.updated': 'Details updated',
  'staff.status_changed': 'Status changed',
  'staff.photo_updated': 'Photo updated',
  'staff.branches_updated': 'Branches updated',
  'practitioner.profile_updated': 'Professional details updated',
  'practitioner.qualification_added': 'Qualification added',
  'practitioner.qualification_updated': 'Qualification updated',
  'practitioner.qualification_removed': 'Qualification removed',
  'practitioner.services_updated': 'Services updated',
  'practitioner.schedule_updated': 'Working hours updated',
  'invitation.created': 'Invited to sign in',
};

export function Activity({ props }: { props: ProfileProps }) {
  return (
    <Section title="Activity">
      {props.activity.length === 0 ? (
        <Empty>No recorded activity yet.</Empty>
      ) : (
        <ol className="relative space-y-3 border-l-2 border-clinic-border pl-4">
          {props.activity.map((entry, index) => (
            <li key={index} className="relative">
              <span className="absolute -left-[21px] top-1 h-2.5 w-2.5 rounded-full bg-clinic-brand ring-2 ring-white" aria-hidden="true" />
              <p className="text-[13px] font-semibold text-clinic-ink">{EVENT_LABELS[entry.event] ?? entry.event.replace(/[._]/g, ' ')}</p>
              <p className="text-[11.5px] text-clinic-secondary">{relativeTime(entry.at)}</p>
            </li>
          ))}
        </ol>
      )}
    </Section>
  );
}
