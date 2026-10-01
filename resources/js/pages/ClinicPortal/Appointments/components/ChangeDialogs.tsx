import { useEffect, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import type { RequestPayload } from '@inertiajs/core';
import { Button } from '@/clinic/ui/Button';
import { shortDate, timeOf } from '../lib/dates';
import { personName, type AppointmentItem, type CalendarProps, type EditScope, type Slot } from '../lib/types';
import { Field, inputClass, Modal, selectClass, textareaClass } from '@/clinic/ui/Modal';
import { SlotPicker } from './SlotPicker';

const SCOPES: { value: EditScope; label: string }[] = [
  { value: 'this', label: 'This appointment' },
  { value: 'following', label: 'This and the following ones' },
  { value: 'series', label: 'Every upcoming one in the series' },
];

function ScopePicker({ value, onChange }: { value: EditScope; onChange: (scope: EditScope) => void }) {
  return (
    <fieldset className="space-y-1">
      <legend className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Apply to</legend>
      {SCOPES.map((scope) => (
        <label key={scope.value} className="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-xl px-2 text-[13px] text-clinic-ink hover:bg-clinic-inset">
          <input
            type="radio"
            name="scope"
            value={scope.value}
            checked={value === scope.value}
            onChange={() => onChange(scope.value)}
            className="h-4 w-4 border-clinic-border-strong text-clinic-brand-strong focus:ring-clinic-brand-strong"
          />
          {scope.label}
        </label>
      ))}
    </fieldset>
  );
}

function send(
  url: string,
  data: RequestPayload,
  setSaving: (saving: boolean) => void,
  setError: (error: string | null) => void,
  onDone: () => void,
  fallback: string,
) {
  setSaving(true);
  setError(null);
  router.patch(url, data, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: onDone,
    onError: (errors) => setError(Object.values(errors)[0] ?? fallback),
    onFinish: () => setSaving(false),
  });
}

/**
 * Reschedule through the same availability engine as booking: a date, then
 * one of the free times, with the same or another provider.
 */
export function RescheduleDialog({
  appointment,
  props,
  onClose,
}: {
  appointment: AppointmentItem | null;
  props: CalendarProps;
  onClose: () => void;
}) {
  const [date, setDate] = useState('');
  const [providerId, setProviderId] = useState<number | null>(null);
  const [slot, setSlot] = useState<Slot | null>(null);
  const [scope, setScope] = useState<EditScope>('this');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!appointment) {
      return;
    }
    const start = appointment.start.slice(0, 10);
    setDate(start < props.today ? props.today : start);
    setProviderId(appointment.provider_id);
    setSlot(null);
    setScope('this');
    setError(null);
  }, [appointment, props.today]);

  if (!appointment) {
    return null;
  }

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!slot) {
      setError('Choose a free time.');

      return;
    }
    send(
      `${props.links.index}/${appointment.id}/reschedule`,
      { scheduled_at: slot.start.replace('T', ' '), practitioner_id: slot.provider_id, scope },
      setSaving,
      setError,
      onClose,
      'This could not be moved.',
    );
  };

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      title="Reschedule"
      description={`${personName(appointment)} · now ${shortDate(appointment.start.slice(0, 10))}, ${timeOf(appointment.start)} with ${appointment.provider_name ?? 'no provider'}`}
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Keep as is
          </Button>
          <Button tone="primary" type="submit" form="reschedule-form" loading={saving} loadingLabel="Moving…" disabled={!slot}>
            Move appointment
          </Button>
        </>
      }
    >
      <form id="reschedule-form" onSubmit={submit} className="space-y-4">
        {error && (
          <p role="alert" className="rounded-xl bg-clinic-danger-soft px-3 py-2.5 text-[12.5px] font-semibold text-clinic-danger">
            {error}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Date" htmlFor="rs-date">
            <input id="rs-date" type="date" min={props.today} className={inputClass} value={date} onChange={(e) => setDate(e.target.value || props.today)} />
          </Field>
          <Field label="Doctor / therapist" htmlFor="rs-provider">
            <select
              id="rs-provider"
              className={selectClass}
              value={providerId ?? ''}
              onChange={(e) => setProviderId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">Any available</option>
              {props.providers.map((provider) => (
                <option key={provider.id} value={provider.id}>
                  {provider.name}
                </option>
              ))}
            </select>
          </Field>
        </div>
        <SlotPicker
          url={props.links.availability}
          date={date}
          providerId={providerId}
          serviceId={appointment.service?.id ?? null}
          appointmentId={appointment.id}
          value={slot}
          onChange={setSlot}
        />
        {appointment.series_id && <ScopePicker value={scope} onChange={setScope} />}
      </form>
    </Modal>
  );
}

/**
 * Confirms a drag or resize. Only a recurring appointment needs it: the
 * move can apply to the rest of the series.
 */
export function MoveScopeDialog({
  move,
  props,
  onClose,
}: {
  move: { appointment: AppointmentItem; start: string; duration: number } | null;
  props: CalendarProps;
  onClose: () => void;
}) {
  const [scope, setScope] = useState<EditScope>('this');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setScope('this');
    setError(null);
  }, [move]);

  if (!move) {
    return null;
  }

  return (
    <Modal
      open
      size="sm"
      onOpenChange={(open) => !open && onClose()}
      title="Move recurring appointment"
      description={`${personName(move.appointment)} to ${shortDate(move.start.slice(0, 10))}, ${timeOf(move.start)}`}
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Keep as is
          </Button>
          <Button
            tone="primary"
            loading={saving}
            loadingLabel="Moving…"
            onClick={() =>
              send(
                `${props.links.index}/${move.appointment.id}/reschedule`,
                { scheduled_at: move.start.replace('T', ' '), duration_minutes: move.duration, scope },
                setSaving,
                setError,
                onClose,
                'This could not be moved.',
              )
            }
          >
            Move
          </Button>
        </>
      }
    >
      {error && (
        <p role="alert" className="mb-3 rounded-xl bg-clinic-danger-soft px-3 py-2.5 text-[12.5px] font-semibold text-clinic-danger">
          {error}
        </p>
      )}
      <ScopePicker value={scope} onChange={setScope} />
    </Modal>
  );
}

export function CancelDialog({
  appointment,
  props,
  onClose,
}: {
  appointment: AppointmentItem | null;
  props: CalendarProps;
  onClose: () => void;
}) {
  const [reason, setReason] = useState('');
  const [scope, setScope] = useState<EditScope>('this');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    setReason('');
    setScope('this');
    setError(null);
  }, [appointment]);

  if (!appointment) {
    return null;
  }

  const hoursAway = (Date.parse(`${appointment.start}Z`) - Date.parse(`${props.now}Z`)) / 3_600_000;
  const late = props.settings.cancellation_notice_hours > 0 && hoursAway < props.settings.cancellation_notice_hours;

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    router.post(
      `${props.links.index}/${appointment.id}/cancel`,
      { reason: reason || null, scope },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: onClose,
        onError: (errors) => setError(Object.values(errors)[0] ?? 'This could not be cancelled.'),
        onFinish: () => setSaving(false),
      },
    );
  };

  return (
    <Modal
      open
      size="sm"
      onOpenChange={(open) => !open && onClose()}
      title="Cancel appointment"
      description={`${personName(appointment)} · ${shortDate(appointment.start.slice(0, 10))}, ${timeOf(appointment.start)}`}
      footer={
        <>
          <Button tone="ghost" onClick={onClose}>
            Keep it
          </Button>
          <Button tone="danger" type="submit" form="cancel-form" loading={saving} loadingLabel="Cancelling…">
            Cancel appointment
          </Button>
        </>
      }
    >
      <form id="cancel-form" onSubmit={submit} className="space-y-4">
        {late && (
          <p className="rounded-xl bg-clinic-warning-soft px-3 py-2.5 text-[12.5px] font-medium text-clinic-warning">
            This is within the clinic's {props.settings.cancellation_notice_hours}-hour notice period. It will be recorded as a late
            cancellation.
          </p>
        )}
        {error && (
          <p role="alert" className="rounded-xl bg-clinic-danger-soft px-3 py-2.5 text-[12.5px] font-semibold text-clinic-danger">
            {error}
          </p>
        )}
        <Field label="Reason (optional)" htmlFor="cancel-reason">
          <textarea id="cancel-reason" maxLength={500} className={textareaClass} value={reason} onChange={(e) => setReason(e.target.value)} />
        </Field>
        {appointment.series_id && <ScopePicker value={scope} onChange={setScope} />}
      </form>
    </Modal>
  );
}
