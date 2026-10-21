import { useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Check, Search, Users } from 'lucide-react';
import { ContextBadge } from '../ui/badges';
import { PersonAvatar } from '../ui/PersonAvatar';
import { contextForSpecialty } from '../ui/visuals';
import { cn } from '@/lib/utils';
import type { PractitionerContext, PractitionerOption } from './types';

/** Above this many doctors the picker gets a filter box. */
const FILTER_FROM = 7;

export function contextLabel(context: PractitionerContext | null | undefined): string | null {
  if (!context) {
    return null;
  }
  const parts = [context.module_label, context.area_label && context.area_label !== context.module_label ? context.area_label : null].filter(Boolean);

  return parts.length > 0 ? parts.join(' · ') : null;
}

/**
 * Today's doctors as cards — name, qualifications, and what choosing them
 * means ("Dental · Orthodontics") — plus "Next available". Reception picks a
 * person; the specialty follows from them. A radio group: one tab stop,
 * arrow keys move, Enter or Space chooses (never submits the step).
 */
export function PractitionerPicker({
  doctors,
  value,
  onChange,
  idPrefix,
  invalid,
  describedBy,
}: {
  doctors: PractitionerOption[];
  value: string;
  onChange: (doctorId: string) => void;
  idPrefix: string;
  invalid?: boolean;
  describedBy?: string;
}) {
  const [filter, setFilter] = useState('');
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const needle = filter.trim().toLowerCase();
  const shown = useMemo(
    () =>
      needle === ''
        ? doctors
        : doctors.filter((doctor) => [doctor.name, doctor.credentials, ...doctor.contexts.map((context) => contextLabel(context))].some((text) => text?.toLowerCase().includes(needle))),
    [doctors, needle],
  );
  const options: { value: string; doctor: PractitionerOption | null }[] = [{ value: '', doctor: null }, ...shown.map((doctor) => ({ value: String(doctor.id), doctor }))];
  const current = Math.max(0, options.findIndex((option) => option.value === value));

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      onChange(options[index].value);

      return;
    }
    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;
    if (step === 0) {
      return;
    }
    event.preventDefault();
    const next = (index + step + options.length) % options.length;
    onChange(options[next].value);
    refs.current[next]?.focus();
  };

  return (
    <div className="space-y-2.5">
      {doctors.length >= FILTER_FROM && (
        <label className="relative block">
          <span className="sr-only">Find a doctor</span>
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-clinic-secondary" aria-hidden="true" />
          <input
            type="search"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            onKeyDown={(event) => event.key === 'Enter' && event.preventDefault()}
            placeholder="Find by name or specialty"
            autoComplete="off"
            className="h-11 w-full rounded-xl border border-clinic-border bg-clinic-surface pl-9 pr-3 text-[14px] text-clinic-ink placeholder:text-clinic-secondary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
          />
        </label>
      )}

      <div
        id={`${idPrefix}-doctor`}
        role="radiogroup"
        aria-label="Doctor"
        aria-describedby={describedBy}
        aria-invalid={invalid || undefined}
        className="grid max-h-[340px] grid-cols-1 gap-2 overflow-y-auto overscroll-contain p-0.5 sm:grid-cols-2"
      >
        {options.map((option, index) => {
          const selected = option.value === value;
          const doctor = option.doctor;
          const primary = doctor?.contexts[0];

          return (
            <button
              key={option.value || 'any'}
              ref={(element) => {
                refs.current[index] = element;
              }}
              type="button"
              role="radio"
              aria-checked={selected}
              tabIndex={index === current ? 0 : -1}
              // The step opens on the chosen doctor, so the keyboard starts there.
              data-autofocus={index === current ? true : undefined}
              onClick={() => onChange(option.value)}
              onKeyDown={(event) => onKeyDown(event, index)}
              className={cn(
                'relative flex min-h-[64px] w-full items-start gap-3 rounded-2xl border p-3 text-left motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong focus-visible:ring-offset-1',
                selected ? 'border-clinic-ink bg-clinic-inset/60 ring-1 ring-clinic-ink' : 'border-clinic-border bg-clinic-surface hover:border-clinic-border-strong',
              )}
            >
              {doctor ? (
                <PersonAvatar name={doctor.name} />
              ) : (
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-700" aria-hidden="true">
                  <Users className="h-4 w-4" />
                </span>
              )}
              <span className="min-w-0 flex-1">
                <span className="block truncate text-[14px] font-bold text-clinic-ink">{doctor ? doctor.name : 'Next available doctor'}</span>
                {doctor ? (
                  <>
                    {doctor.credentials && <span className="block truncate text-[12px] font-semibold text-clinic-secondary">{doctor.credentials}</span>}
                    <span className="mt-1 flex flex-wrap items-center gap-1.5">
                      {primary && contextLabel(primary) && <ContextBadge context={contextForSpecialty(primary.module)} label={contextLabel(primary)} className="whitespace-normal" />}
                      {doctor.contexts.length > 1 && <span className="text-[11.5px] font-semibold text-clinic-secondary">+{doctor.contexts.length - 1} more</span>}
                      <span className="text-[11.5px] text-clinic-secondary">{doctor.shift_label}</span>
                    </span>
                  </>
                ) : (
                  <span className="block text-[12px] text-clinic-secondary">The queue gives the patient to whoever is free.</span>
                )}
              </span>
              {selected && <Check className="h-4 w-4 shrink-0 text-clinic-ink" aria-hidden="true" />}
            </button>
          );
        })}
        {shown.length === 0 && doctors.length > 0 && <p className="px-1 py-2 text-[12.5px] text-clinic-secondary sm:col-span-2">No doctor matches “{filter.trim()}”.</p>}
      </div>
    </div>
  );
}
