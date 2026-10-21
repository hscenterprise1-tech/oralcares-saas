import type { InputHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { Field, Input, Switch, useFieldId } from '@/clinic/ui/controls';
import type { StepFormProps } from './types';

/*
 * Small building blocks the step forms share. Values are whatever the server
 * sent; the forms only change the keys the section validates.
 */

export function asString(value: unknown): string {
  return value === null || value === undefined ? '' : String(value);
}

/** A number field's value for the server: a number, or null when cleared. */
export function asNumber(raw: string): number | null {
  if (raw.trim() === '') {
    return null;
  }
  const parsed = Number(raw);

  return Number.isFinite(parsed) ? parsed : null;
}

export function asArray<T>(value: unknown): T[] {
  return Array.isArray(value) ? (value as T[]) : [];
}

/** The first error for a key or anything under it (`services` also matches `services.0.price`). */
export function errorUnder(errors: Record<string, string>, key: string): string | undefined {
  return errors[key] ?? Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1];
}

export function TextField({
  form,
  name,
  label,
  required,
  hint,
  type = 'text',
  className,
  ...rest
}: {
  form: StepFormProps;
  name: string;
  label: string;
  required?: boolean;
  hint?: ReactNode;
  type?: string;
  className?: string;
} & Omit<InputHTMLAttributes<HTMLInputElement>, 'form' | 'name' | 'value' | 'onChange' | 'type'>) {
  const id = useFieldId();
  const error = form.errors[name];

  return (
    <Field label={label} htmlFor={id} error={error} hint={hint} required={required} className={className}>
      <Input
        id={id}
        name={name}
        type={type}
        value={asString(form.values[name])}
        onChange={(event) => form.set(name, event.target.value)}
        invalid={Boolean(error)}
        aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
        disabled={form.disabled}
        {...rest}
      />
    </Field>
  );
}

export function NumberField({
  form,
  name,
  label,
  required,
  hint,
  min,
  max,
  step,
  suffix,
  className,
}: {
  form: StepFormProps;
  name: string;
  label: string;
  required?: boolean;
  hint?: ReactNode;
  min?: number;
  max?: number;
  step?: number | 'any';
  suffix?: string;
  className?: string;
}) {
  const id = useFieldId();
  const error = form.errors[name];

  return (
    <Field label={label} htmlFor={id} error={error} hint={hint} required={required} className={className}>
      <div className="relative">
        <Input
          id={id}
          name={name}
          type="number"
          inputMode="decimal"
          min={min}
          max={max}
          step={step}
          value={asString(form.values[name])}
          onChange={(event) => form.set(name, asNumber(event.target.value))}
          invalid={Boolean(error)}
          aria-describedby={error ? `${id}-error` : hint ? `${id}-hint` : undefined}
          disabled={form.disabled}
          className={cn(suffix && 'pr-14')}
        />
        {suffix && (
          <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-cx-text-3" aria-hidden="true">
            {suffix}
          </span>
        )}
      </div>
    </Field>
  );
}

export function SwitchField({ form, name, label, description }: { form: StepFormProps; name: string; label: string; description?: ReactNode }) {
  return (
    <div className="rounded-xl border border-cx-line px-4 py-3">
      <Switch checked={Boolean(form.values[name])} onCheckedChange={(checked) => form.set(name, checked)} label={label} description={description} disabled={form.disabled} />
      {form.errors[name] && (
        <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
          {form.errors[name]}
        </p>
      )}
    </div>
  );
}

/** Read-only facts the owner cannot change here (plan, specialty, email…). */
export function LockedFacts({ facts, note }: { facts: { label: string; value: string | null }[]; note?: string }) {
  if (facts.length === 0) {
    return null;
  }

  return (
    <div className="rounded-xl bg-cx-slate-bg px-4 py-3">
      <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-3">
        {facts.map((fact) => (
          <div key={fact.label} className="min-w-0">
            <dt className="text-[11.5px] font-semibold uppercase tracking-wide text-cx-text-3">{fact.label}</dt>
            <dd className="mt-0.5 break-words text-[13px] font-medium text-cx-nav-ink">{fact.value || '—'}</dd>
          </div>
        ))}
      </dl>
      {note && <p className="mt-2.5 text-[12px] text-cx-text-2">{note}</p>}
    </div>
  );
}

export const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

type DayRow = { day: number; open: boolean } & Record<string, unknown>;

/**
 * A week of opening hours: one row per day, open or closed, with a start and
 * an end. `startKey`/`endKey` are the section's own field names
 * (`opens`/`closes` for the clinic, `start`/`end` for a practitioner).
 */
export function WeekHours({ form, startKey, endKey }: { form: StepFormProps; startKey: string; endKey: string }) {
  const days = asArray<DayRow>(form.values.days);
  const update = (index: number, patch: Partial<DayRow>) =>
    form.set(
      'days',
      days.map((row, at) => (at === index ? { ...row, ...patch } : row)),
    );
  const sectionError = form.errors.days;

  return (
    <fieldset disabled={form.disabled} className="space-y-2">
      <legend className="sr-only">Opening hours for each day</legend>
      {sectionError && (
        <p role="alert" className="text-[12px] font-medium text-clinic-danger">
          {sectionError}
        </p>
      )}
      <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
        {days.map((row, index) => {
          const name = DAY_NAMES[row.day] ?? `Day ${row.day}`;
          const rowError = form.errors[`days.${row.day}`] ?? errorUnder(form.errors, `days.${index}`);

          return (
            <li key={row.day} className="px-4 py-3">
              <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <div className="w-32 shrink-0">
                  <Switch checked={row.open} onCheckedChange={(open) => update(index, { open })} label={name} />
                </div>
                {row.open ? (
                  <div className="flex flex-wrap items-center gap-2">
                    <label className="sr-only" htmlFor={`day-${row.day}-start`}>
                      {name} opens
                    </label>
                    <Input
                      id={`day-${row.day}-start`}
                      type="time"
                      value={asString(row[startKey])}
                      onChange={(event) => update(index, { [startKey]: event.target.value })}
                      invalid={Boolean(rowError)}
                      className="h-10 w-[120px]"
                    />
                    <span className="text-[12.5px] text-cx-text-3">to</span>
                    <label className="sr-only" htmlFor={`day-${row.day}-end`}>
                      {name} closes
                    </label>
                    <Input
                      id={`day-${row.day}-end`}
                      type="time"
                      value={asString(row[endKey])}
                      onChange={(event) => update(index, { [endKey]: event.target.value })}
                      invalid={Boolean(rowError)}
                      className="h-10 w-[120px]"
                    />
                  </div>
                ) : (
                  <span className="text-[12.5px] font-medium text-cx-text-3">Closed</span>
                )}
              </div>
              {rowError && (
                <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
                  {rowError}
                </p>
              )}
            </li>
          );
        })}
      </ul>
    </fieldset>
  );
}
