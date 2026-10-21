import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import type { RequestPayload } from '@inertiajs/core';
import { AlertTriangle, CalendarClock, CheckCircle2, Link2, LogIn, Phone, Repeat, UserPlus, UserRound, UserX, XCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, buttonClasses } from '@/clinic/ui/Button';
import { longDate, timeOf } from '../lib/dates';
import { patientUrl } from '../lib/navigation';
import { humanize, initials, providerTone, statusStyle } from '../lib/status';
import { personName, type AppointmentItem, type CalendarProps, type PatientRef, type StatusValue } from '../lib/types';
import { Modal } from '@/clinic/ui/Modal';
import { PatientPicker } from '@/clinic';

/**
 * One appointment: its facts, and only the actions this user may take on it
 * in its current status. Every action is checked again on the server.
 */
export function DetailDialog({
  appointment,
  onOpenChange,
  props,
  onReschedule,
  onCancel,
  onCheckIn,
}: {
  appointment: AppointmentItem | null;
  onOpenChange: (open: boolean) => void;
  props: CalendarProps;
  onReschedule: (appointment: AppointmentItem) => void;
  onCancel: (appointment: AppointmentItem) => void;
  /** Check-in is the Front Desk's intake (reason, quick care, payment), opened for this booking. */
  onCheckIn: (appointment: AppointmentItem) => void;
}) {
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [linkTo, setLinkTo] = useState<PatientRef | null>(null);
  const { can, links, today } = props;

  useEffect(() => {
    setError(null);
    setLinkTo(null);
  }, [appointment?.id]);

  if (!appointment) {
    return null;
  }

  const style = statusStyle(appointment.display_status);
  const isToday = appointment.start.slice(0, 10) === today;
  const allowed = (status: StatusValue) => appointment.allowed_statuses.includes(status);
  const request = (key: string, method: 'patch' | 'post', url: string, data: RequestPayload, fallback: string) => {
    setBusy(key);
    setError(null);
    router[method](url, data, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => key !== 'link' && onOpenChange(false),
      onError: (errors) => setError(Object.values(errors)[0] ?? fallback),
      onFinish: () => setBusy(null),
    });
  };
  const setStatus = (status: StatusValue) =>
    request(status, 'patch', `${links.index}/${appointment.id}/status`, { status }, 'This could not be changed.');

  const facts: [string, string | null][] = [
    ['Date', longDate(appointment.start.slice(0, 10))],
    ['Time', `${timeOf(appointment.start)} – ${timeOf(appointment.end)}`],
    ['Duration', `${appointment.duration_minutes} min`],
    ['Service', appointment.service?.name ?? 'General appointment'],
    ['Branch', props.branch.name],
    ['Resource', appointment.resource ? `${appointment.resource.name}${appointment.resource.type ? ` · ${humanize(appointment.resource.type)}` : ''}` : null],
    ['Booked by', appointment.source],
    ['Reference', appointment.reference],
    ['Notes', appointment.notes],
    ['Cancellation', appointment.cancellation_reason],
  ];
  const phone = appointment.patient?.phone ?? appointment.new_case?.phone;
  const canCheckIn = can.check_in && isToday && ['scheduled', 'confirmed', 'arrived'].includes(appointment.status) && (appointment.patient !== null || (appointment.new_case !== null && can.register_patient));

  return (
    <Modal
      open
      onOpenChange={onOpenChange}
      title={personName(appointment)}
      description={
        appointment.patient
          ? [appointment.patient.uhid, appointment.patient.phone].filter(Boolean).join(' · ') || undefined
          : `New case · ${appointment.new_case?.phone ?? 'no mobile'} · not registered yet`
      }
      footer={
        <Button tone="ghost" onClick={() => onOpenChange(false)}>
          Close
        </Button>
      }
    >
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <span className={cn('rounded-full px-2.5 py-1 text-[11.5px] font-bold ring-1', style.chip)}>{style.label}</span>
        {appointment.new_case && <span className="rounded-full bg-sky-50 px-2.5 py-1 text-[11.5px] font-bold text-sky-800 ring-1 ring-sky-200">New case</span>}
        {appointment.series_id && (
          <span className="inline-flex items-center gap-1 rounded-full bg-clinic-inset px-2.5 py-1 text-[11.5px] font-semibold text-clinic-ink">
            <Repeat className="h-3 w-3" aria-hidden="true" /> Recurring
          </span>
        )}
        {appointment.rescheduled && <span className="rounded-full bg-clinic-inset px-2.5 py-1 text-[11.5px] font-semibold text-clinic-ink">Rescheduled</span>}
      </div>

      {appointment.provider_on_leave && appointment.upcoming && (
        <p className="mb-4 flex items-start gap-2 rounded-xl bg-clinic-warning-soft px-3 py-2.5 text-[12.5px] font-medium text-clinic-warning">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
          {appointment.provider_name} is on approved leave that day. Reschedule or cancel this appointment.
        </p>
      )}

      <div className="mb-3 flex items-center gap-3 rounded-2xl bg-clinic-canvas px-3.5 py-3">
        <span className={cn('flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12px] font-bold', providerTone(appointment.provider_id))}>
          {initials(appointment.provider_name)}
        </span>
        <span className="min-w-0">
          <span className="block text-[11.5px] font-semibold text-clinic-secondary">Doctor / therapist</span>
          <span className="block truncate text-[13.5px] font-bold text-clinic-ink">{appointment.provider_name ?? 'Not assigned'}</span>
        </span>
      </div>

      <dl className="divide-y divide-clinic-border rounded-2xl border border-clinic-border">
        {facts
          .filter(([, value]) => value)
          .map(([label, value]) => (
            <div key={label} className="flex gap-3 px-3.5 py-2.5">
              <dt className="w-24 shrink-0 text-[12px] font-semibold text-clinic-secondary">{label}</dt>
              <dd className="min-w-0 flex-1 break-words text-[13px] text-clinic-ink">{value}</dd>
            </div>
          ))}
      </dl>

      {appointment.new_case && can.manage && appointment.upcoming && (
        <div className="mt-4 rounded-2xl border border-sky-200 bg-sky-50/60 p-3">
          <p className="text-[12.5px] font-bold text-clinic-ink">When they arrive</p>
          <p className="mb-2.5 text-[12px] text-clinic-secondary">
            Register them, then link the registration here to check them in. Registering never charges a fee.
          </p>
          {can.register_patient && (
            <Link href={links.registerPatient} className={cn(buttonClasses('secondary', 'sm'), 'mb-2.5')}>
              <UserPlus className="h-4 w-4" aria-hidden="true" /> Register patient
            </Link>
          )}
          <PatientPicker searchUrl={links.patientSearch} value={linkTo} onChange={setLinkTo} />
          {linkTo && (
            <Button
              tone="primary"
              size="sm"
              icon={Link2}
              className="mt-2"
              loading={busy === 'link'}
              onClick={() => request('link', 'patch', `${links.index}/${appointment.id}/patient`, { patient_id: linkTo.id }, 'This could not be linked.')}
            >
              Link to {linkTo.name}
            </Button>
          )}
        </div>
      )}

      {error && (
        <p role="alert" className="mt-3 rounded-xl bg-clinic-danger-soft px-3 py-2.5 text-[12.5px] font-semibold text-clinic-danger">
          {error}
        </p>
      )}

      <div className="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {canCheckIn && (
          <Button
            tone="primary"
            icon={LogIn}
            onClick={() => onCheckIn(appointment)}
          >
            {appointment.patient ? 'Check in' : 'Register & check in'}
          </Button>
        )}
        {can.manage && allowed('arrived') && isToday && (
          <Button icon={UserRound} loading={busy === 'arrived'} onClick={() => setStatus('arrived')}>
            Mark arrived
          </Button>
        )}
        {can.manage && allowed('confirmed') && (
          <Button icon={CheckCircle2} loading={busy === 'confirmed'} onClick={() => setStatus('confirmed')}>
            Confirm
          </Button>
        )}
        {can.manage && appointment.upcoming && (
          <Button icon={CalendarClock} onClick={() => onReschedule(appointment)}>
            Reschedule
          </Button>
        )}
        {can.manage && allowed('no_show') && appointment.start <= props.now && (
          <Button icon={UserX} loading={busy === 'no_show'} onClick={() => setStatus('no_show')}>
            Mark no-show
          </Button>
        )}
        {can.manage && appointment.status === 'no_show' && allowed('scheduled') && isToday && (
          <Button loading={busy === 'scheduled'} onClick={() => setStatus('scheduled')}>
            Undo no-show
          </Button>
        )}
        {can.view_patient && appointment.patient && (
          <Link href={patientUrl(links.patient, appointment.patient.id)} className={buttonClasses('secondary', 'md')}>
            <UserRound className="h-4 w-4" aria-hidden="true" /> Open patient
          </Link>
        )}
        {phone && (
          <a href={`tel:${phone}`} className={buttonClasses('secondary', 'md')}>
            <Phone className="h-4 w-4" aria-hidden="true" /> Call
          </a>
        )}
        {can.manage && appointment.upcoming && (
          <Button
            tone="ghost"
            icon={XCircle}
            className="text-clinic-danger hover:bg-clinic-danger-soft hover:text-clinic-danger"
            onClick={() => onCancel(appointment)}
          >
            Cancel appointment
          </Button>
        )}
      </div>
    </Modal>
  );
}
