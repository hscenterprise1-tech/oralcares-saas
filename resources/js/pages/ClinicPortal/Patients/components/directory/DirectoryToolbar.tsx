import { useEffect, useId, useRef, useState } from 'react';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Spinner } from '@/clinic/ui/Spinner';
import { Select, Switch } from '@/clinic/ui/controls';
import type { DirectoryFilters } from '../types';

export type FilterOptions = {
  statuses: { value: string; label: string }[];
  specialties: { key: string; label: string }[];
  /** Empty when there is only one branch to choose from (no filter is shown). */
  branches: { id: number; name: string }[];
};

const SEARCH_DELAY_MS = 350;

/**
 * Search and filters for the directory. Every control maps to a filter the
 * server's directory query supports (`PatientDirectory::query`); nothing is
 * filtered in the browser.
 */
export function DirectoryToolbar({
  filters,
  options,
  isPractitioner,
  loading,
  onChange,
}: {
  filters: DirectoryFilters;
  options: FilterOptions;
  isPractitioner: boolean;
  loading: boolean;
  onChange: (patch: Partial<DirectoryFilters>) => void;
}) {
  const [query, setQuery] = useState(filters.q);
  const searchId = useId();
  const timer = useRef<number | null>(null);

  // A visit can change the search (reset filters, a link): follow it.
  useEffect(() => setQuery(filters.q), [filters.q]);
  useEffect(() => () => (timer.current !== null ? window.clearTimeout(timer.current) : undefined), []);

  const search = (value: string) => {
    setQuery(value);
    if (timer.current !== null) {
      window.clearTimeout(timer.current);
    }
    timer.current = window.setTimeout(() => onChange({ q: value.trim() }), SEARCH_DELAY_MS);
  };

  const statusLabel = options.statuses.find((s) => s.value === filters.status)?.label;
  const specialtyLabel = options.specialties.find((s) => s.key === filters.specialty)?.label;
  const branchLabel = options.branches.find((b) => String(b.id) === filters.branch_id)?.name;
  const chips = [
    filters.status && { key: 'status', label: `Status: ${statusLabel ?? filters.status}`, clear: { status: '' } },
    filters.specialty && { key: 'specialty', label: `Specialty: ${specialtyLabel ?? filters.specialty}`, clear: { specialty: '' } },
    filters.branch_id && { key: 'branch', label: `Branch: ${branchLabel ?? 'Selected branch'}`, clear: { branch_id: '' } },
    filters.active_care && { key: 'care', label: 'In active care', clear: { active_care: '' } },
  ].filter(Boolean) as { key: string; label: string; clear: Partial<DirectoryFilters> }[];

  return (
    <div className="space-y-3 border-b border-cx-line px-4 py-4 sm:px-5">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
        {isPractitioner && (
          <div role="group" aria-label="Which patients" className="inline-flex shrink-0 rounded-full bg-cx-slate-bg p-1">
            {(
              [
                ['mine', 'My patients'],
                ['all', 'All patients'],
              ] as const
            ).map(([tab, label]) => (
              <button
                key={tab}
                type="button"
                aria-pressed={filters.tab === tab}
                onClick={() => filters.tab !== tab && onChange({ tab })}
                className={cn(
                  'h-9 rounded-full px-4 text-[13px] font-semibold motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal [@media(pointer:coarse)]:min-h-11',
                  filters.tab === tab ? 'bg-white text-cx-nav-ink shadow-sm' : 'text-cx-text-2 hover:text-cx-nav-ink',
                )}
              >
                {label}
              </button>
            ))}
          </div>
        )}

        <div className="relative min-w-0 flex-1">
          <label htmlFor={searchId} className="sr-only">
            Search patients
          </label>
          <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-text-3" aria-hidden="true" />
          <input
            id={searchId}
            type="search"
            value={query}
            onChange={(event) => search(event.target.value)}
            placeholder="Name, mobile or UHID"
            autoComplete="off"
            maxLength={80}
            className="h-11 w-full rounded-xl border border-cx-line bg-white pl-10 pr-20 text-[14px] text-cx-nav-ink placeholder:text-cx-text-3 focus:border-cx-nav-teal focus:outline-none focus:ring-2 focus:ring-cx-nav-teal/25 [&::-webkit-search-cancel-button]:hidden"
          />
          <div className="absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
            {loading && <Spinner className="h-4 w-4 text-cx-text-3" />}
            {query && (
              <button
                type="button"
                onClick={() => search('')}
                aria-label="Clear search"
                className="flex h-8 w-8 items-center justify-center rounded-full text-cx-text-3 hover:bg-cx-slate-bg hover:text-cx-nav-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            )}
          </div>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-2.5">
        <span className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-cx-text-3">
          <SlidersHorizontal className="h-3.5 w-3.5" aria-hidden="true" />
          Filters
        </span>
        <div className="w-full sm:w-44">
          <Select
            aria-label="Status"
            value={filters.status}
            onChange={(event) => onChange({ status: event.target.value })}
            options={options.statuses.map((s) => ({ value: s.value, label: s.label }))}
            placeholder="Current patients"
            className="h-10"
          />
        </div>
        {options.specialties.length > 1 && (
          <div className="w-full sm:w-44">
            <Select
              aria-label="Specialty"
              value={filters.specialty}
              onChange={(event) => onChange({ specialty: event.target.value })}
              options={options.specialties.map((s) => ({ value: s.key, label: s.label }))}
              placeholder="All specialties"
              className="h-10"
            />
          </div>
        )}
        {options.branches.length > 1 && (
          <div className="w-full sm:w-48">
            <Select
              aria-label="Branch"
              value={filters.branch_id}
              onChange={(event) => onChange({ branch_id: event.target.value })}
              options={options.branches.map((b) => ({ value: String(b.id), label: b.name }))}
              placeholder="All my branches"
              className="h-10"
            />
          </div>
        )}
        <div className="flex h-10 items-center rounded-xl border border-cx-line bg-white px-3">
          <Switch checked={filters.active_care === '1'} onCheckedChange={(on) => onChange({ active_care: on ? '1' : '' })} label="In active care" />
        </div>
      </div>

      {chips.length > 0 && (
        <div className="flex flex-wrap items-center gap-2" aria-label="Active filters">
          {chips.map((chip) => (
            <button
              key={chip.key}
              type="button"
              onClick={() => onChange(chip.clear)}
              className="inline-flex h-8 items-center gap-1.5 rounded-full bg-cx-nav-active px-3 text-[12px] font-semibold text-cx-nav-teal-strong hover:bg-cx-nav-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
            >
              {chip.label}
              <X className="h-3.5 w-3.5" aria-hidden="true" />
              <span className="sr-only">(remove filter)</span>
            </button>
          ))}
          <button
            type="button"
            onClick={() => onChange({ status: '', specialty: '', branch_id: '', active_care: '' })}
            className="h-8 rounded-full px-2.5 text-[12px] font-semibold text-cx-text-2 underline-offset-2 hover:text-cx-nav-ink hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal"
          >
            Reset filters
          </button>
        </div>
      )}
    </div>
  );
}
