import { useEffect, useRef, useState, type FormEvent } from 'react';
import { CalendarCheck } from 'lucide-react';
import { ERROR_COPY, errorKind, requestJson } from '@/clinic/http';
import { Button } from '@/clinic/ui/Button';
import { Spinner } from '@/clinic/ui/Spinner';
import { shortDate, timeOf } from '../lib/dates';
import { humanize, initials, providerTone } from '../lib/status';
import type { BookingDraft, CalendarProps, Slot } from '../lib/types';
import { Field, inputClass, Modal, selectClass } from './Modal';

/**
 * Find next available: the earliest free times for a service, with any or a
 * chosen provider, from a date. Each result opens Book appointment filled in.
 */
export function FindNextDialog({
  open,
  onOpenChange,
  props,
  onBook,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  props: CalendarProps;
  onBook: (draft: BookingDraft) => void;
}) {
  const { providers, services, options, links, today, settings } = props;
  const [serviceId, setServiceId] = useState('');
  const [providerId, setProviderId] = useState('');
  const [from, setFrom] = useState(today);
  const [period, setPeriod] = useState('any');
  const [result, setResult] = useState<{ duration_minutes: number; slots: Slot[] } | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const abort = useRef<AbortController | null>(null);

  useEffect(() => {
    if (open) {
      setResult(null);
      setError(null);
      setFrom(today);
    }
  }, [open, today]);

  const search = async (event?: FormEvent) => {
    event?.preventDefault();
    abort.current?.abort();
    const controller = new AbortController();
    abort.current = controller;
    setLoading(true);
    setError(null);
    try {
      setResult(
        await requestJson('GET', links.availability, {
          query: { provider_id: providerId || null, service_id: serviceId || null, from, days: 14, period, limit: 12 },
          signal: controller.signal,
        }),
      );
    } catch (exception) {
      const kind = errorKind(exception);
      if (kind !== 'aborted') {
        setError(ERROR_COPY[kind]);
      }
    } finally {
      if (abort.current === controller) {
        setLoading(false);
      }
    }
  };

  return (
    <Modal
      open={open}
      onOpenChange={onOpenChange}
      title="Find next available"
      description="The earliest free times in the next two weeks, from each provider's schedule, leave and bookings."
      footer={
        <>
          <Button tone="ghost" onClick={() => onOpenChange(false)}>
            Close
          </Button>
          <Button tone="primary" type="submit" form="find-next-form" loading={loading} loadingLabel="Searching…">
            Find times
          </Button>
        </>
      }
    >
      <form id="find-next-form" onSubmit={search} className="grid gap-4 sm:grid-cols-2">
        <Field label="Service" htmlFor="fn-service">
          <select id="fn-service" className={selectClass} value={serviceId} onChange={(e) => setServiceId(e.target.value)}>
            <option value="">General appointment ({settings.default_duration_minutes} min)</option>
            {services.map((service) => (
              <option key={service.id} value={service.id}>
                {service.name}
                {service.duration_minutes ? ` · ${service.duration_minutes} min` : ''}
              </option>
            ))}
          </select>
        </Field>
        <Field label="Doctor / therapist" htmlFor="fn-provider">
          <select id="fn-provider" className={selectClass} value={providerId} onChange={(e) => setProviderId(e.target.value)}>
            <option value="">Any available</option>
            {providers.map((provider) => (
              <option key={provider.id} value={provider.id}>
                {provider.name}
              </option>
            ))}
          </select>
        </Field>
        <Field label="From" htmlFor="fn-from">
          <input id="fn-from" type="date" min={today} className={inputClass} value={from} onChange={(e) => setFrom(e.target.value || today)} />
        </Field>
        <Field label="Time of day" htmlFor="fn-period">
          <select id="fn-period" className={selectClass} value={period} onChange={(e) => setPeriod(e.target.value)}>
            {options.periods.map((value) => (
              <option key={value} value={value}>
                {value === 'any' ? 'Any time' : humanize(value)}
              </option>
            ))}
          </select>
        </Field>
      </form>

      <div className="mt-5" aria-live="polite">
        {loading && (
          <p className="flex items-center gap-2 text-[12.5px] text-clinic-secondary">
            <Spinner className="h-4 w-4" /> Checking schedules…
          </p>
        )}
        {error && <p className="text-[12.5px] font-medium text-clinic-danger">{error}</p>}
        {result &&
          !loading &&
          (result.slots.length === 0 ? (
            <p className="rounded-xl bg-clinic-canvas px-3 py-3 text-[12.5px] text-clinic-secondary">
              No free {result.duration_minutes}-minute time in the next two weeks. Try another provider or a later date.
            </p>
          ) : (
            <ul className="divide-y divide-clinic-border rounded-2xl border border-clinic-border">
              {result.slots.map((slot) => (
                <li key={`${slot.provider_id}-${slot.start}`} className="flex items-center gap-3 px-3 py-2">
                  <CalendarCheck className="h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                  <span className="min-w-0 flex-1">
                    <span className="block text-[13px] font-bold text-clinic-ink">
                      {shortDate(slot.start.slice(0, 10))}, {timeOf(slot.start)}
                    </span>
                    <span className="flex items-center gap-1.5 text-[11.5px] text-clinic-secondary">
                      <span className={`flex h-4 w-4 items-center justify-center rounded-full text-[8px] font-bold ${providerTone(slot.provider_id)}`}>
                        {initials(slot.provider_name)}
                      </span>
                      <span className="truncate">
                        {slot.provider_name} · {result.duration_minutes} min
                      </span>
                    </span>
                  </span>
                  <Button
                    size="sm"
                    tone="primary"
                    onClick={() => onBook({ start: slot.start, providerId: slot.provider_id, serviceId: serviceId ? Number(serviceId) : null })}
                  >
                    Book
                  </Button>
                </li>
              ))}
            </ul>
          ))}
      </div>
    </Modal>
  );
}
