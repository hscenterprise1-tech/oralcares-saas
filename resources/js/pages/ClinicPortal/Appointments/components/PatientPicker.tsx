import { useEffect, useId, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';
import { ERROR_COPY, errorKind, requestJson } from '@/clinic/http';
import { Spinner } from '@/clinic/ui/Spinner';
import type { PatientHit } from '../lib/types';
import { inputClass } from './Modal';

export interface PickedPatient {
  id: number;
  name: string;
  uhid: string | null;
  phone: string | null;
}

/**
 * Find an existing patient by name, mobile or ID, through the clinic's
 * patient search (server-side, bounded, throttled).
 */
export function PatientPicker({
  searchUrl,
  value,
  onChange,
  error,
  disabled,
}: {
  searchUrl: string;
  value: PickedPatient | null;
  onChange: (patient: PickedPatient | null) => void;
  error?: string | null;
  disabled?: boolean;
}) {
  const id = useId();
  const [query, setQuery] = useState('');
  const [hits, setHits] = useState<PatientHit[]>([]);
  const [loading, setLoading] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const abort = useRef<AbortController | null>(null);

  useEffect(() => {
    const term = query.trim();
    if (term.length < 2) {
      setHits([]);
      setFailure(null);

      return;
    }
    const timer = window.setTimeout(async () => {
      abort.current?.abort();
      const controller = new AbortController();
      abort.current = controller;
      setLoading(true);
      setFailure(null);
      try {
        setHits(await requestJson<PatientHit[]>('GET', searchUrl, { query: { q: term }, signal: controller.signal }));
      } catch (exception) {
        const kind = errorKind(exception);
        if (kind !== 'aborted') {
          setFailure(ERROR_COPY[kind]);
        }
      } finally {
        if (abort.current === controller) {
          setLoading(false);
        }
      }
    }, 250);

    return () => window.clearTimeout(timer);
  }, [query, searchUrl]);

  useEffect(() => () => abort.current?.abort(), []);

  if (value) {
    return (
      <div className="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-clinic-border bg-clinic-canvas px-3 py-2">
        <span className="min-w-0">
          <span className="block truncate text-[13px] font-bold text-clinic-ink">{value.name}</span>
          <span className="block truncate text-[11.5px] text-clinic-secondary">
            {[value.uhid, value.phone].filter(Boolean).join(' · ')}
          </span>
        </span>
        {!disabled && (
          <button
            type="button"
            onClick={() => onChange(null)}
            aria-label="Choose a different patient"
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-clinic-secondary hover:bg-white hover:text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-8 sm:w-8"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        )}
      </div>
    );
  }

  return (
    <div>
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-clinic-secondary" aria-hidden="true" />
        <input
          id={id}
          type="search"
          autoComplete="off"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Name, mobile or patient ID"
          aria-invalid={Boolean(error) || undefined}
          aria-label="Search patients"
          className={`${inputClass} pl-9`}
        />
        {loading && <Spinner className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-clinic-secondary" />}
      </div>
      {failure && <p className="mt-1.5 text-[12px] font-medium text-clinic-danger">{failure}</p>}
      {hits.length > 0 && (
        <ul className="mt-1.5 max-h-56 overflow-y-auto rounded-xl border border-clinic-border bg-white p-1" aria-label="Matching patients">
          {hits.map((hit) => (
            <li key={hit.id}>
              <button
                type="button"
                onClick={() => {
                  onChange({ id: hit.id, name: hit.name, uhid: hit.uhid, phone: hit.phone });
                  setQuery('');
                  setHits([]);
                }}
                className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-2.5 py-1.5 text-left hover:bg-clinic-inset focus-visible:bg-clinic-inset focus-visible:outline-none"
              >
                <span className="min-w-0">
                  <span className="block truncate text-[13px] font-semibold text-clinic-ink">{hit.name}</span>
                  <span className="block truncate text-[11.5px] text-clinic-secondary">
                    {[hit.uhid, hit.phone, hit.age !== null ? `${hit.age} y` : null].filter(Boolean).join(' · ')}
                  </span>
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
      {!loading && !failure && query.trim().length >= 2 && hits.length === 0 && (
        <p className="mt-1.5 text-[12px] text-clinic-secondary">No patient matches “{query.trim()}”.</p>
      )}
      {error && (
        <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
          {error}
        </p>
      )}
    </div>
  );
}
