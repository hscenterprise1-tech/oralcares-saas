import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Plus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/clinic/ui/Button';
import { shortDate } from '../lib/dates';
import type { AppointmentSettings, CalendarProps } from '../lib/types';
import { Field, inputClass, Modal, selectClass } from '@/clinic/ui/Modal';

const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const SLOTS = [5, 10, 15, 20, 30, 60];

type Form = Omit<AppointmentSettings, 'configured'>;

/**
 * The clinic's appointment rules. Branch opening hours and each provider's
 * own schedule stay in Branches and Staff; the working days and hours here
 * apply to a branch that has not set its own.
 */
export function SettingsDialog({
  open,
  onOpenChange,
  props,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  props: CalendarProps;
}) {
  const [form, setForm] = useState<Form>(() => strip(props.settings));
  const [holiday, setHoliday] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) {
      setForm(strip(props.settings));
      setErrors({});
    }
  }, [open, props.settings]);

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((current) => ({ ...current, [key]: value }));

  const submit = (event: FormEvent) => {
    event.preventDefault();
    setSaving(true);
    router.put(props.links.settings, form as unknown as Record<string, never>, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => onOpenChange(false),
      onError: (received) => setErrors(received as Record<string, string>),
      onFinish: () => setSaving(false),
    });
  };

  const firstError = Object.values(errors)[0];

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      size="lg"
      title="Appointment settings"
      description="Applies to every branch of the clinic. Each change is recorded in the settings audit."
      footer={
        <>
          <Button tone="ghost" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button tone="primary" type="submit" form="appointment-settings-form" loading={saving} loadingLabel="Saving…">
            Save settings
          </Button>
        </>
      }
    >
      <form id="appointment-settings-form" onSubmit={submit} className="space-y-5">
        {firstError && (
          <p role="alert" className="rounded-xl bg-clinic-danger-soft px-3 py-2.5 text-[12.5px] font-semibold text-clinic-danger">
            {firstError}
          </p>
        )}

        <Section title="Slots">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <Field label="Slot length" htmlFor="st-slot">
              <select id="st-slot" className={selectClass} value={form.slot_minutes} onChange={(e) => set('slot_minutes', Number(e.target.value))}>
                {SLOTS.map((value) => (
                  <option key={value} value={value}>
                    {value} min
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Default length" htmlFor="st-default" hint="When no service sets one.">
              <input id="st-default" type="number" min={5} max={480} className={inputClass} value={form.default_duration_minutes} onChange={(e) => set('default_duration_minutes', Number(e.target.value))} />
            </Field>
            <Field label="Buffer between" htmlFor="st-buffer" hint="Minutes kept free.">
              <input id="st-buffer" type="number" min={0} max={120} className={inputClass} value={form.buffer_minutes} onChange={(e) => set('buffer_minutes', Number(e.target.value))} />
            </Field>
          </div>
        </Section>

        <Section title="Working days and hours" note="Used where a branch has no opening hours of its own.">
          <div className="mb-3 flex flex-wrap gap-1.5" role="group" aria-label="Working days">
            {DAYS.map((label, day) => {
              const on = form.working_days.includes(day);

              return (
                <button
                  key={label}
                  type="button"
                  aria-pressed={on}
                  onClick={() => set('working_days', on ? form.working_days.filter((value) => value !== day) : [...form.working_days, day].sort())}
                  className={cn(
                    'min-h-11 min-w-12 rounded-full px-3 text-[12.5px] font-semibold ring-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:min-h-9',
                    on ? 'bg-cx-nav-active text-cx-nav-teal-strong ring-cx-nav-teal/50' : 'bg-white text-clinic-secondary ring-clinic-border',
                  )}
                >
                  {label}
                </button>
              );
            })}
          </div>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <Field label="Opens" htmlFor="st-start">
              <input id="st-start" type="time" className={inputClass} value={form.day_start} onChange={(e) => set('day_start', e.target.value)} />
            </Field>
            <Field label="Closes" htmlFor="st-end">
              <input id="st-end" type="time" className={inputClass} value={form.day_end} onChange={(e) => set('day_end', e.target.value)} />
            </Field>
            <Field label="Break from" htmlFor="st-break-start">
              <input id="st-break-start" type="time" className={inputClass} value={form.break_start ?? ''} onChange={(e) => set('break_start', e.target.value || null)} />
            </Field>
            <Field label="Break until" htmlFor="st-break-end">
              <input id="st-break-end" type="time" className={inputClass} value={form.break_end ?? ''} onChange={(e) => set('break_end', e.target.value || null)} />
            </Field>
          </div>
        </Section>

        <Section title="Holidays" note="No bookings on these dates at any branch.">
          <div className="flex gap-2">
            <input
              type="date"
              aria-label="Holiday date"
              min={props.today}
              className={cn(inputClass, 'max-w-[200px]')}
              value={holiday}
              onChange={(e) => setHoliday(e.target.value)}
            />
            <Button
              icon={Plus}
              disabled={!holiday || form.holidays.includes(holiday)}
              onClick={() => {
                set('holidays', [...form.holidays, holiday].sort());
                setHoliday('');
              }}
            >
              Add
            </Button>
          </div>
          {form.holidays.length > 0 && (
            <ul className="mt-2 flex flex-wrap gap-1.5">
              {form.holidays.map((day) => (
                <li key={day} className="inline-flex items-center gap-1 rounded-full bg-clinic-inset py-1 pl-3 pr-1 text-[12px] font-semibold text-clinic-ink">
                  {shortDate(day)} {day.slice(0, 4)}
                  <button
                    type="button"
                    aria-label={`Remove ${day}`}
                    onClick={() => set('holidays', form.holidays.filter((value) => value !== day))}
                    className="flex h-8 w-8 items-center justify-center rounded-full hover:bg-white"
                  >
                    <X className="h-3.5 w-3.5" aria-hidden="true" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Section>

        <Section title="Policies">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <Field label="Cancellation notice (hours)" htmlFor="st-notice" hint="Later ones are marked late.">
              <input id="st-notice" type="number" min={0} max={168} className={inputClass} value={form.cancellation_notice_hours} onChange={(e) => set('cancellation_notice_hours', Number(e.target.value))} />
            </Field>
            <Field label="No-show after (min)" htmlFor="st-noshow">
              <input id="st-noshow" type="number" min={5} max={240} className={inputClass} value={form.no_show_after_minutes} onChange={(e) => set('no_show_after_minutes', Number(e.target.value))} />
            </Field>
            <Field label="Most repeats" htmlFor="st-repeats">
              <input id="st-repeats" type="number" min={2} max={52} className={inputClass} value={form.max_recurring_occurrences} onChange={(e) => set('max_recurring_occurrences', Number(e.target.value))} />
            </Field>
          </div>
          <div className="mt-3 grid gap-1 sm:grid-cols-2">
            <Toggle label="Recurring appointments" checked={form.recurring_enabled} onChange={(value) => set('recurring_enabled', value)} />
            <Toggle label="Online booking" checked={form.online_booking_enabled} onChange={(value) => set('online_booking_enabled', value)} />
          </div>
        </Section>
      </form>
    </Modal>
  );
}

function strip(settings: AppointmentSettings): Form {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { configured, ...rest } = settings;

  return rest;
}

function Section({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
  return (
    <section>
      <h3 className="text-[13px] font-bold text-clinic-ink">{title}</h3>
      {note && <p className="mb-2 text-[11.5px] text-clinic-secondary">{note}</p>}
      <div className={note ? '' : 'mt-2'}>{children}</div>
    </section>
  );
}

function Toggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
  return (
    <label className="flex min-h-11 cursor-pointer items-center gap-2.5 text-[13px] text-clinic-ink">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="h-4 w-4 rounded border-clinic-border-strong text-clinic-brand-strong focus:ring-clinic-brand-strong"
      />
      {label}
    </label>
  );
}
