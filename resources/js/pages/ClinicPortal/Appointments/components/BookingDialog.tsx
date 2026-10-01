import { useEffect, useState, type FormEvent } from 'react';
import { router } from '@inertiajs/react';
import { Repeat, UserPlus, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import type { BookingDraft, CalendarProps, PatientRef, Slot } from '../lib/types';
import { Field, inputClass, Modal, selectClass, textareaClass } from './Modal';
import { PatientPicker } from './PatientPicker';
import { SlotPicker } from './SlotPicker';

type Errors = Record<string, string>;

/**
 * New Appointment: patient, service, doctor (or any available), date, a free
 * time, notes. The length comes from the service; the room or chair, the
 * source and the visit type are worked out on the server.
 *
 * A new caller is booked by name and mobile only. They are registered when
 * they arrive, not here.
 */
export function BookingDialog({
  open,
  onOpenChange,
  draft,
  props,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  draft: BookingDraft;
  props: CalendarProps;
}) {
  const { providers, services, settings, links, today, options } = props;
  const [mode, setMode] = useState<'existing' | 'new'>('existing');
  const [patient, setPatient] = useState<PatientRef | null>(null);
  const [newCase, setNewCase] = useState({ name: '', phone: '', not_a_duplicate: false });
  const [serviceId, setServiceId] = useState<number | null>(null);
  const [providerId, setProviderId] = useState<number | null>(null);
  const [date, setDate] = useState(today);
  const [slot, setSlot] = useState<Slot | null>(null);
  const [notes, setNotes] = useState('');
  const [recurring, setRecurring] = useState(false);
  const [frequency, setFrequency] = useState('weekly');
  const [occurrences, setOccurrences] = useState(4);
  const [errors, setErrors] = useState<Errors>({});
  const [saving, setSaving] = useState(false);

  // Fill from the draft each time the dialog opens.
  useEffect(() => {
    if (!open) {
      return;
    }
    setMode('existing');
    setPatient(draft.patient ?? null);
    setNewCase({ name: '', phone: '', not_a_duplicate: false });
    setServiceId(draft.serviceId ?? null);
    setProviderId(draft.providerId ?? (props.filters.providers.length === 1 ? props.filters.providers[0] : providers.length === 1 ? providers[0].id : null));
    setDate(draft.start ? draft.start.slice(0, 10) : props.date < today ? today : props.date);
    setSlot(null);
    setNotes('');
    setRecurring(false);
    setErrors({});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, draft]);

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!slot) {
      setErrors({ scheduled_at: 'Choose a free time.' });

      return;
    }
    setSaving(true);
    router.post(
      links.store,
      {
        patient_id: mode === 'existing' ? (patient?.id ?? null) : null,
        new_case: mode === 'new' ? newCase : null,
        service_id: serviceId,
        practitioner_id: slot.provider_id,
        scheduled_at: slot.start.replace('T', ' '),
        notes: notes || null,
        recurrence: recurring ? { frequency, occurrences } : null,
      },
      {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => onOpenChange(false),
        onError: (received) => setErrors(received as Errors),
        onFinish: () => setSaving(false),
      },
    );
  };

  const duplicateWarning = errors['new_case.phone']?.startsWith('Already registered');
  const topError = errors.scheduled_at ?? errors.recurrence ?? errors.practitioner_id ?? errors.service_id;

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title="Book appointment"
      description={`At ${props.branch.name}. The service sets the length; the room is assigned automatically.`}
      footer={
        <>
          <Button tone="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button tone="primary" type="submit" form="booking-form" loading={saving} loadingLabel="Booking…" disabled={!slot}>
            {recurring ? `Book ${occurrences} appointments` : 'Book appointment'}
          </Button>
        </>
      }
    >
      <form id="booking-form" onSubmit={submit} className="space-y-4" noValidate>
        {topError && (
          <p role="alert" className="rounded-xl bg-clinic-danger-soft px-3 py-2.5 text-[12.5px] font-semibold text-clinic-danger">
            {topError}
          </p>
        )}

        <div>
          <div className="mb-1.5 flex items-center justify-between gap-2">
            <span className="text-[12px] font-semibold text-clinic-ink">Patient</span>
            <div role="tablist" aria-label="Patient" className="flex rounded-full bg-clinic-inset p-0.5">
              {(
                [
                  ['existing', 'Registered', Users],
                  ['new', 'New case', UserPlus],
                ] as const
              ).map(([value, label, Icon]) => (
                <button
                  key={value}
                  type="button"
                  role="tab"
                  aria-selected={mode === value}
                  onClick={() => setMode(value)}
                  className={cn(
                    'inline-flex min-h-9 items-center gap-1.5 rounded-full px-3 text-[12px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong [@media(pointer:coarse)]:min-h-11',
                    mode === value ? 'bg-white text-clinic-ink shadow-sm' : 'text-clinic-secondary',
                  )}
                >
                  <Icon className="h-3.5 w-3.5" aria-hidden="true" /> {label}
                </button>
              ))}
            </div>
          </div>
          {mode === 'existing' ? (
            <>
              <PatientPicker searchUrl={links.patientSearch} value={patient} onChange={setPatient} />
              {errors.patient_id && (
                <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
                  {errors.patient_id}
                </p>
              )}
            </>
          ) : (
            <div className="rounded-2xl border border-clinic-border bg-clinic-canvas p-3">
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Name" htmlFor="nc-name" error={errors['new_case.name']}>
                  <input id="nc-name" className={inputClass} value={newCase.name} onChange={(e) => setNewCase({ ...newCase, name: e.target.value })} autoComplete="off" />
                </Field>
                <Field label="Mobile" htmlFor="nc-phone" error={duplicateWarning ? null : errors['new_case.phone']}>
                  <input
                    id="nc-phone"
                    inputMode="tel"
                    className={inputClass}
                    value={newCase.phone}
                    onChange={(e) => setNewCase({ ...newCase, phone: e.target.value, not_a_duplicate: false })}
                    autoComplete="off"
                  />
                </Field>
              </div>
              {duplicateWarning && (
                <div role="alert" className="mt-3 rounded-xl bg-clinic-warning-soft px-3 py-2.5 text-[12.5px] text-clinic-warning">
                  <p className="font-semibold">{errors['new_case.phone']}</p>
                  <label className="mt-2 flex min-h-11 cursor-pointer items-center gap-2 font-medium sm:min-h-0">
                    <input
                      type="checkbox"
                      checked={newCase.not_a_duplicate}
                      onChange={(e) => setNewCase({ ...newCase, not_a_duplicate: e.target.checked })}
                      className="h-4 w-4 rounded border-clinic-border-strong"
                    />
                    This is a different person on the same number
                  </label>
                </div>
              )}
              <p className="mt-2 text-[11.5px] text-clinic-secondary">
                Registration, the new case fee and check-in happen when they arrive.
              </p>
            </div>
          )}
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Service" htmlFor="bk-service" hint={services.length === 0 ? 'No services in the catalogue yet: the default length is used.' : undefined}>
            <select
              id="bk-service"
              className={selectClass}
              value={serviceId ?? ''}
              onChange={(e) => setServiceId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">General appointment ({settings.default_duration_minutes} min)</option>
              {services.map((service) => (
                <option key={service.id} value={service.id}>
                  {service.name}
                  {service.duration_minutes ? ` · ${service.duration_minutes} min` : ''}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Doctor / therapist" htmlFor="bk-provider">
            <select
              id="bk-provider"
              className={selectClass}
              value={providerId ?? ''}
              onChange={(e) => setProviderId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">Any available</option>
              {providers.map((provider) => (
                <option key={provider.id} value={provider.id}>
                  {provider.name}
                  {provider.specialty ? ` · ${provider.specialty}` : ''}
                </option>
              ))}
            </select>
          </Field>
        </div>

        <Field label="Date" htmlFor="bk-date" className="sm:max-w-[220px]">
          <input id="bk-date" type="date" min={today} className={inputClass} value={date} onChange={(e) => setDate(e.target.value || today)} />
        </Field>

        <SlotPicker
          url={links.availability}
          date={date}
          providerId={providerId}
          serviceId={serviceId}
          value={slot}
          onChange={setSlot}
          preferStart={draft.start ?? null}
        />

        <Field label="Notes (optional)" htmlFor="bk-notes" error={errors.notes}>
          <textarea id="bk-notes" maxLength={1000} rows={2} className={textareaClass} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </Field>

        {settings.recurring_enabled && (
          <details className="group rounded-2xl border border-clinic-border px-3 py-1" open={recurring}>
            <summary className="flex min-h-11 cursor-pointer list-none items-center gap-2 text-[12.5px] font-semibold text-clinic-secondary">
              <Repeat className="h-4 w-4" aria-hidden="true" /> Repeat this appointment
            </summary>
            <div className="pb-2">
              <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px] text-clinic-ink">
                <input
                  type="checkbox"
                  checked={recurring}
                  onChange={(e) => setRecurring(e.target.checked)}
                  className="h-4 w-4 rounded border-clinic-border-strong text-clinic-brand-strong focus:ring-clinic-brand-strong"
                />
                Repeat at the same time
              </label>
              {recurring && (
                <div className="mt-1 grid grid-cols-2 gap-3">
                  <Field label="Every" htmlFor="bk-frequency">
                    <select id="bk-frequency" className={selectClass} value={frequency} onChange={(e) => setFrequency(e.target.value)}>
                      {options.frequencies.map((value) => (
                        <option key={value} value={value}>
                          {value === 'daily' ? 'Day' : value === 'weekly' ? 'Week' : value === 'fortnightly' ? 'Two weeks' : 'Month'}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Appointments in total" htmlFor="bk-count" error={errors['recurrence.occurrences']}>
                    <input
                      id="bk-count"
                      type="number"
                      min={2}
                      max={settings.max_recurring_occurrences}
                      className={inputClass}
                      value={occurrences}
                      onChange={(e) => setOccurrences(Math.max(2, Math.min(settings.max_recurring_occurrences, Number(e.target.value) || 2)))}
                    />
                  </Field>
                </div>
              )}
            </div>
          </details>
        )}
      </form>
    </Modal>
  );
}
