import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { CalendarClock, Search, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { humanize, PersonAvatar, Spinner } from '@/clinic';
import { errorKind, requestJson } from '@/clinic/http';
import { SafetyFlags } from './SafetyFlags';
import { formatDay, type IntakeConfig, type LookupPatient } from './types';

/**
 * Repeat Case, step one: find the patient. The search box has focus the
 * moment the dialog opens. Typing searches the server (debounced, at most
 * eight results, nothing preloaded but a short "seen here lately" list);
 * arrows move through the results and Enter picks one. The list is a
 * listbox the search box controls, so a screen reader follows the choice.
 */
export function PatientSearchStep({
  config,
  query,
  onQueryChange,
  onSelect,
}: {
  config: IntakeConfig;
  query: string;
  onQueryChange: (query: string) => void;
  onSelect: (patient: LookupPatient) => void;
}) {
  const [results, setResults] = useState<LookupPatient[]>([]);
  // Which query the results answer: Enter never picks from an older search.
  const [resultsFor, setResultsFor] = useState('');
  const pendingEnter = useRef(false);
  const [recent, setRecent] = useState<LookupPatient[] | null>(null);
  const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');
  const [active, setActive] = useState(0);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const searching = query.trim().length >= 3;
  const shown = searching ? (resultsFor === query.trim() ? results : []) : (recent ?? []);

  useEffect(() => {
    inputRef.current?.focus();
    requestJson<{ patients: LookupPatient[] }>('GET', route('clinic.reception.check-in.recent'))
      .then((response) => setRecent(response.patients))
      .catch(() => setRecent([]));
  }, []);

  useEffect(() => {
    if (!searching) {
      setResults([]);
      setState('idle');

      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setState('loading');
      try {
        const response = await requestJson<{ patients: LookupPatient[] }>('GET', route('clinic.reception.check-in.search'), { query: { q: query.trim() }, signal: controller.signal });
        setResults(response.patients);
        setResultsFor(query.trim());
        setActive(0);
        setState('idle');
        // Enter pressed while searching: take the top result now it has arrived.
        if (pendingEnter.current && response.patients.length > 0) {
          pendingEnter.current = false;
          onSelect(response.patients[0]);
        }
      } catch (error) {
        if (errorKind(error) !== 'aborted') {
          setState('error');
        }
      }
    }, 250);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [query, searching]);

  const fresh = !searching || resultsFor === query.trim();

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter' && searching && !fresh) {
      event.preventDefault();
      pendingEnter.current = true;

      return;
    }
    pendingEnter.current = false;
    if (shown.length === 0) {
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((index) => (index + 1) % shown.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => (index - 1 + shown.length) % shown.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      onSelect(shown[Math.min(active, shown.length - 1)]);
    }
  };

  const specialtyLabel = (key: string) => config.specialties.find((specialty) => specialty.key === key)?.label ?? humanize(key);

  return (
    <div className="space-y-4">
      <div className="relative">
        <Search className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-clinic-secondary" aria-hidden="true" />
        <input
          ref={inputRef}
          type="search"
          role="combobox"
          aria-expanded={shown.length > 0}
          aria-controls={listId}
          aria-activedescendant={shown.length > 0 ? `${listId}-${Math.min(active, shown.length - 1)}` : undefined}
          aria-autocomplete="list"
          aria-label="Find the patient by mobile, patient number or name"
          placeholder="Mobile, patient number or name"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={onKeyDown}
          enterKeyHint="search"
          className="h-14 w-full rounded-2xl border border-clinic-border-strong bg-clinic-surface pl-12 pr-12 text-[16px] text-clinic-ink placeholder:text-clinic-muted focus:border-clinic-ink focus:outline-none focus:ring-2 focus:ring-clinic-brand-strong/40"
        />
        {state === 'loading' && <Spinner className="absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-clinic-secondary" />}
      </div>

      <p className="flex items-center gap-1.5 text-[12px] font-semibold text-clinic-secondary">
        {searching ? (
          <>
            <Search className="h-3.5 w-3.5" aria-hidden="true" />
            {state === 'loading' ? 'Searching…' : `${results.length === 0 ? 'No' : results.length} matching ${results.length === 1 ? 'patient' : 'patients'}`}
          </>
        ) : (
          <>
            <Users className="h-3.5 w-3.5" aria-hidden="true" />
            {query.trim() !== '' ? 'Type at least 3 characters' : `Seen at ${config.branch.name} lately`}
          </>
        )}
      </p>

      {state === 'error' && <p role="alert" className="text-[13px] font-medium text-clinic-danger">The search did not finish. Check the connection and type again.</p>}

      <ul id={listId} role="listbox" aria-label="Patients" className="space-y-2">
        {shown.map((patient, index) => {
          const facts = [patient.uhid, [patient.age !== null ? `${patient.age}` : null, patient.gender ? humanize(patient.gender) : null].filter(Boolean).join(' · ')].filter(Boolean);

          return (
            <li
              key={patient.id}
              id={`${listId}-${index}`}
              role="option"
              aria-selected={index === active}
              onMouseEnter={() => setActive(index)}
              onClick={() => onSelect(patient)}
              className={cn(
                'flex cursor-pointer items-center gap-3 rounded-2xl border px-3.5 py-3 motion-safe:transition-colors',
                index === active ? 'border-clinic-ink bg-clinic-inset' : 'border-clinic-border bg-clinic-surface hover:border-clinic-border-strong',
              )}
            >
              <PersonAvatar name={patient.name} />
              <div className="min-w-0 flex-1">
                <p className="truncate text-[14px] font-bold text-clinic-ink">{patient.name}</p>
                <p className="truncate text-[12.5px] text-clinic-secondary">{facts.join(' · ')}</p>
                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-clinic-ink">
                  {patient.specialties.length > 0 && <span className="font-semibold">{patient.specialties.map(specialtyLabel).join(', ')}</span>}
                  {patient.last_seen && <span className="text-clinic-secondary">Last visit {formatDay(patient.last_seen.at)}</span>}
                  {patient.appointment && (
                    <span className="inline-flex items-center gap-1 font-semibold text-clinic-brand-strong">
                      <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" />
                      Booked today {patient.appointment.time.slice(11, 16)}
                    </span>
                  )}
                  {patient.in_queue && <span className="font-semibold text-amber-800">Already in today&apos;s queue</span>}
                </div>
                <SafetyFlags flags={patient.safety_flags} compact className="mt-1.5" />
              </div>
              <span aria-hidden="true" className={cn('hidden rounded-full px-3 py-1.5 text-[12px] font-semibold sm:inline', index === active ? 'bg-clinic-ink text-white' : 'text-clinic-secondary')}>
                Select ↵
              </span>
            </li>
          );
        })}
      </ul>

      {searching && state === 'idle' && results.length === 0 && (
        <p className="rounded-2xl border border-dashed border-clinic-border px-4 py-5 text-center text-[13px] text-clinic-secondary">
          No patient matches “{query.trim()}”. Check the number, or register them with New Case.
        </p>
      )}
    </div>
  );
}
