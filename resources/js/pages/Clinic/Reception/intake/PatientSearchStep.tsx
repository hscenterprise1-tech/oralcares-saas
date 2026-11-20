import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { CalendarClock, Search, UserPlus, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { humanize, Spinner } from '@/clinic';
import { errorKind, requestJson } from '@/clinic/http';
import { SafetyFlags } from './SafetyFlags';
import { formatDay, maskPhone, type IntakeConfig, type LookupPatient } from './types';

const RECENT_FRESH_MS = 30_000;
/** "Seen here lately", per branch, for the page's life: shown at once on the next opening, re-read when stale. */
const recentByBranch = new Map<number, { at: number; patients: LookupPatient[] }>();

/**
 * Repeat Case, step one: find the patient. The search box has focus the
 * moment the dialog opens. Typing searches the server (debounced, at most
 * eight results, nothing preloaded but a short "seen here lately" list);
 * arrows move through the results and Enter picks the highlighted one —
 * only once the results for what is typed have arrived; while a search is
 * still running Enter does nothing, so nobody is chosen unseen. Each row
 * carries the last four digits of the mobile to tell namesakes apart. The
 * list is a listbox the search box controls, so a screen reader follows.
 */
export function PatientSearchStep({
  config,
  query,
  onQueryChange,
  onSelect,
  onRegisterNew = null,
}: {
  config: IntakeConfig;
  query: string;
  onQueryChange: (query: string) => void;
  onSelect: (patient: LookupPatient) => void;
  /** Nobody matches: offer New Case instead (when this member may register). */
  onRegisterNew?: (() => void) | null;
}) {
  const [results, setResults] = useState<LookupPatient[]>([]);
  // Which query the results answer: Enter never picks from an older search.
  const [resultsFor, setResultsFor] = useState('');
  const [recent, setRecent] = useState<LookupPatient[] | null>(null);
  const [state, setState] = useState<'idle' | 'loading' | 'error'>('idle');
  // The highlighted row: none until reception hovers or uses the arrow keys, so nothing looks chosen by itself.
  const [active, setActive] = useState(-1);
  const listId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const searching = query.trim().length >= 3;
  const shown = searching ? (resultsFor === query.trim() ? results : []) : (recent ?? []);

  useEffect(() => {
    inputRef.current?.focus();
    // The last list read at this branch shows at once; it is re-read when older than half a minute.
    const kept = recentByBranch.get(config.branch.id);
    if (kept) {
      setRecent(kept.patients);
    }
    if (!kept || Date.now() - kept.at > RECENT_FRESH_MS) {
      requestJson<{ patients: LookupPatient[] }>('GET', route('clinic.reception.check-in.recent'))
        .then((response) => {
          recentByBranch.set(config.branch.id, { at: Date.now(), patients: response.patients });
          setRecent(response.patients);
        })
        .catch(() => setRecent((current) => current ?? []));
    }
  }, [config.branch.id]);

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
        setActive(-1);
        setState('idle');
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
  const noMatch = searching && fresh && state === 'idle' && results.length === 0;

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    // Results for what is typed have not arrived yet: Enter waits for the receptionist to see them.
    if (event.key === 'Enter' && searching && !fresh) {
      event.preventDefault();

      return;
    }
    if (shown.length === 0) {
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((index) => (index + 1) % shown.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => (index <= 0 ? shown.length - 1 : index - 1));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      // Only a row reception has highlighted is taken: Enter alone never picks the top result.
      if (active >= 0 && active < shown.length) {
        pick(shown[active]);
      }
    }
  };

  function pick(patient: LookupPatient) {
    onSelect(patient);
  }

  const specialtyLabel = (key: string) => config.specialties.find((specialty) => specialty.key === key)?.label ?? humanize(key);

  return (
    <div className="space-y-2.5">
      <div className="relative">
        <Search className="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[#14917F]" aria-hidden="true" />
        <input
          ref={inputRef}
          type="search"
          role="combobox"
          aria-expanded={shown.length > 0}
          aria-controls={listId}
          aria-activedescendant={active >= 0 && active < shown.length ? `${listId}-${active}` : undefined}
          aria-autocomplete="list"
          aria-label="Find the patient by mobile, patient number or name"
          placeholder="Name, mobile or patient ID"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={onKeyDown}
          enterKeyHint="search"
          className="h-12 w-full rounded-xl border border-[#CFE3E0] bg-[#FBFDFD] pl-10 pr-11 text-[15px] text-[#0F2A44] placeholder:text-[#94A3B8] motion-safe:transition-[border-color,box-shadow,background-color] motion-safe:duration-150 hover:border-[#9FCFC7] focus:border-[#14917F] focus:bg-white focus:outline-none focus:ring-4 focus:ring-[#14917F]/15"
        />
        {state === 'loading' && <Spinner className="absolute right-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[#14917F]" />}
      </div>

      {/* No results is said once, in the box below; this line counts, or says what it is listing. */}
      {!noMatch && (
        <p className="flex items-center gap-1.5 text-[12px] font-semibold text-[#64748B]" aria-live="polite">
          {searching ? (
            <>
              <Search className="h-3.5 w-3.5" aria-hidden="true" />
              {state === 'loading' || !fresh ? 'Searching…' : `${results.length} matching ${results.length === 1 ? 'patient' : 'patients'} · choose one`}
            </>
          ) : (
            <>
              <Users className="h-3.5 w-3.5" aria-hidden="true" />
              {query.trim() !== '' ? 'Type at least 3 characters' : recent === null ? 'Loading recent patients…' : `Seen at ${config.branch.name} lately`}
            </>
          )}
        </p>
      )}

      {state === 'error' && (
        <p role="alert" className="rounded-xl bg-[#FFF1F2] px-3.5 py-2.5 text-[12.5px] font-medium text-[#B42318] ring-1 ring-[#FBD3D8]">
          The search did not finish. Check the connection and type again.
        </p>
      )}

      <ul id={listId} role="listbox" aria-label="Patients" className="space-y-1.5">
        {shown.map((patient, index) => {
          const isActive = index === active;
          const initials = patient.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase();
          const facts = [patient.uhid, maskPhone(patient.phone), [patient.age !== null ? `${patient.age}` : null, patient.gender ? humanize(patient.gender) : null].filter(Boolean).join(' · ')].filter(Boolean);

          return (
            <li
              key={patient.id}
              id={`${listId}-${index}`}
              role="option"
              aria-selected={isActive}
              onMouseEnter={() => setActive(index)}
              onClick={() => pick(patient)}
              className={cn(
                'group flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-1 motion-safe:fill-mode-both motion-safe:duration-200 motion-safe:transition-[background-color,border-color,box-shadow]',
                isActive ? 'border-[#14917F]/60 bg-[#F0FAF8] shadow-[0_6px_16px_-12px_rgba(15,107,95,0.7)]' : 'border-[#E6EBF2] bg-white hover:border-[#C9D6E4]',
              )}
            >
              <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-lg text-[12.5px] font-bold', isActive ? 'bg-[#14917F] text-white' : 'bg-[#EEF3FA] text-[#1769AA]')} aria-hidden="true">
                {initials}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-[14px] font-semibold text-[#0F2A44]">{patient.name}</p>
                <p className="truncate text-[12px] tabular-nums text-[#475569]">{facts.join(' · ')}</p>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[11.5px]">
                  {patient.specialties.length > 0 && <span className="font-semibold text-[#0F6B5F]">{patient.specialties.map(specialtyLabel).join(', ')}</span>}
                  {patient.last_seen && <span className="text-[#64748B]">Last visit {formatDay(patient.last_seen.at)}</span>}
                  {patient.appointment && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-[#E8F1FB] px-2 py-px font-semibold text-[#0B4A7D]">
                      <CalendarClock className="h-3 w-3" aria-hidden="true" />
                      Booked {patient.appointment.time.slice(11, 16)}
                    </span>
                  )}
                  {patient.in_queue && <span className="rounded-full bg-[#FFF3DC] px-2 py-px font-semibold text-[#92400E]">In today&apos;s queue</span>}
                </div>
                <SafetyFlags flags={patient.safety_flags} compact className="mt-1" />
              </div>
              <span aria-hidden="true" className={cn('hidden rounded-lg px-2.5 py-1 text-[11.5px] font-semibold motion-safe:transition-colors sm:inline', isActive ? 'bg-[#14917F] text-white' : 'text-[#94A3B8] group-hover:text-[#475569]')}>
                Select ↵
              </span>
            </li>
          );
        })}
      </ul>

      {noMatch && (
        <div role="status" className="rounded-xl border border-dashed border-[#CFD8E3] bg-[#FAFBFD] px-4 py-4 text-center motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-200">
          <p className="text-[13px] text-[#475569]">
            No patient matches “<span className="font-semibold text-[#0F2A44]">{query.trim()}</span>”. Check the spelling or number.
          </p>
          {onRegisterNew && (
            <button
              type="button"
              onClick={onRegisterNew}
              className="mt-2.5 inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#E8F6F3] px-3 text-[13px] font-semibold text-[#0F6B5F] motion-safe:transition-colors hover:bg-[#D9F0EB] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#14917F]/40 [@media(pointer:coarse)]:h-11"
            >
              <UserPlus className="h-4 w-4" aria-hidden="true" />
              Register as a new patient
            </button>
          )}
        </div>
      )}
    </div>
  );
}
