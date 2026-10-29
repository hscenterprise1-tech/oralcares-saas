import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { ERROR_COPY, errorKind, requestJson } from '@/clinic/http';
import { Spinner } from '@/clinic/ui/Spinner';
import { longDate, timeOf } from '../lib/dates';
import { initials, providerTone } from '../lib/status';
import type { Slot } from '../lib/types';

/**
 * The free times for a date, from the server's availability engine: the
 * provider's schedule, breaks, leave, existing bookings, the service's
 * length and the rooms they need. Past and taken times are never offered.
 * With "any provider", each time names who is free.
 */
export function SlotPicker({
  url,
  date,
  providerId,
  serviceId,
  appointmentId,
  value,
  onChange,
  preferStart,
}: {
  url: string;
  date: string;
  /** Null for any available provider. */
  providerId: number | null;
  serviceId: number | null;
  /** When moving an appointment: its own time counts as free. */
  appointmentId?: number;
  value: Slot | null;
  onChange: (slot: Slot | null) => void;
  /** A time to pick as soon as the slots arrive, when it is free. */
  preferStart?: string | null;
}) {
  const [slots, setSlots] = useState<Slot[] | null>(null);
  const [duration, setDuration] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const abort = useRef<AbortController | null>(null);

  useEffect(() => {
    if (!date) {
      return;
    }
    abort.current?.abort();
    const controller = new AbortController();
    abort.current = controller;
    setSlots(null);
    setError(null);
    onChange(null);
    requestJson<{ duration_minutes: number; slots: Slot[] }>('GET', url, {
      query: { from: date, days: 1, provider_id: providerId, service_id: serviceId, appointment_id: appointmentId, limit: 120 },
      signal: controller.signal,
    })
      .then((result) => {
        setSlots(result.slots);
        setDuration(result.duration_minutes);
        const preferred = preferStart ? result.slots.find((slot) => slot.start === preferStart) : undefined;
        if (preferred) {
          onChange(preferred);
        }
      })
      .catch((exception) => {
        const kind = errorKind(exception);
        if (kind !== 'aborted') {
          setError(ERROR_COPY[kind]);
        }
      });

    return () => controller.abort();
    // `onChange` is the parent's setter; a new identity must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url, date, providerId, serviceId, appointmentId]);

  const anyProvider = providerId === null;
  // One choice per time with "any": the first provider free then.
  const shown = anyProvider ? (slots ?? []).filter((slot, index, all) => all.findIndex((other) => other.start === slot.start) === index) : (slots ?? []);
  const others = (start: string) => (slots ?? []).filter((slot) => slot.start === start).length - 1;

  return (
    <div>
      <div className="mb-2 flex items-baseline justify-between gap-2">
        <p className="text-[12px] font-semibold text-clinic-ink">Free on {longDate(date)}</p>
        {duration && <p className="text-[11.5px] text-clinic-secondary">{duration} min</p>}
      </div>
      {error ? (
        <p role="alert" className="text-[12px] font-medium text-clinic-danger">
          {error}
        </p>
      ) : slots === null ? (
        <p className="flex items-center gap-2 py-2 text-[12px] text-clinic-secondary">
          <Spinner className="h-3.5 w-3.5" /> Checking schedules…
        </p>
      ) : shown.length === 0 ? (
        <p className="rounded-xl bg-clinic-canvas px-3 py-2.5 text-[12.5px] text-clinic-secondary">
          No free time that day{anyProvider ? '' : ' with this provider'}. Try another date{anyProvider ? '' : ' or any available provider'}.
        </p>
      ) : (
        <div role="radiogroup" aria-label="Free times" className="grid max-h-[208px] grid-cols-2 gap-1.5 overflow-y-auto pr-0.5 sm:grid-cols-3">
          {shown.map((slot) => {
            const selected = value?.start === slot.start && (!anyProvider || value.provider_id === slot.provider_id);
            const more = anyProvider ? others(slot.start) : 0;

            return (
              <button
                key={`${slot.provider_id}-${slot.start}`}
                type="button"
                role="radio"
                aria-checked={selected}
                onClick={() => onChange(slot)}
                className={cn(
                  'flex min-h-11 flex-col items-start justify-center rounded-xl px-3 py-1.5 text-left ring-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
                  selected ? 'bg-[#0F7C6E] text-white ring-[#0F7C6E]' : 'bg-emerald-50/70 text-emerald-900 ring-emerald-200 hover:bg-emerald-100',
                )}
              >
                <span className="text-[13px] font-bold tabular-nums">{timeOf(slot.start)}</span>
                {anyProvider && (
                  <span className={cn('flex max-w-full items-center gap-1 text-[11px] font-medium', selected ? 'text-white/85' : 'text-emerald-800')}>
                    <span className={cn('flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[8px] font-bold', providerTone(slot.provider_id))}>
                      {initials(slot.provider_name)}
                    </span>
                    <span className="truncate">
                      {slot.provider_name}
                      {more > 0 ? ` +${more}` : ''}
                    </span>
                  </span>
                )}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
