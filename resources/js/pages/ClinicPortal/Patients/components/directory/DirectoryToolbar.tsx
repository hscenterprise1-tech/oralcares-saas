import { useEffect, useId, useRef, useState } from 'react';
import { BookOpen, ChevronDown, MapPin, Search, SlidersHorizontal, Stethoscope, UserRoundCheck, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Switch } from '@/clinic/ui/controls';
import type { DirectoryFilters, DirectoryView } from '../types';

export type FilterOptions = {
  statuses: { value: string; label: string }[];
  specialties: { key: string; label: string }[];
  /** Empty when there is only one branch to choose from (no filter is shown). */
  branches: { id: number; name: string }[];
  /** Empty when the doctor filter is not offered (a doctor's own list). */
  doctors: { id: number; name: string }[];
  views: DirectoryView[];
};

const SEARCH_DELAY_MS = 350;

const VIEW_LABEL: Record<DirectoryView, string> = { mine: 'My Patients', today: 'Today', all: 'All Patients' };

/** The four filter hues of the approved design: one colour per filter, in the field, its chip and its icon. */
const HUES = {
  teal: { on: 'border-cx-m-teal-ring bg-cx-m-teal-bg', icon: 'bg-cx-m-teal-bg text-cx-m-teal', iconOn: 'bg-cx-m-teal text-white', ink: 'text-cx-teal-ink', chip: 'border-cx-m-teal-ring bg-cx-m-teal-bg text-cx-teal-ink' },
  blue: { on: 'border-cx-m-blue-ring bg-cx-m-blue-bg', icon: 'bg-cx-m-blue-bg text-cx-m-blue', iconOn: 'bg-cx-m-blue text-white', ink: 'text-cx-blue-ink', chip: 'border-cx-m-blue-ring bg-cx-m-blue-bg text-cx-blue-ink' },
  violet: { on: 'border-cx-m-purple-ring bg-cx-m-purple-bg', icon: 'bg-cx-m-purple-bg text-cx-m-purple', iconOn: 'bg-cx-m-purple text-white', ink: 'text-cx-violet-ink', chip: 'border-cx-m-purple-ring bg-cx-m-purple-bg text-cx-violet-ink' },
  green: { on: 'border-[#CFF2E1] bg-cx-green-bg', icon: 'bg-cx-green-bg text-cx-green-ink', iconOn: 'bg-cx-green-ink text-white', ink: 'text-cx-green-ink', chip: 'border-[#CFF2E1] bg-cx-green-bg text-cx-green-ink' },
} as const;

type Hue = keyof typeof HUES;

/**
 * Search, the view (Today / All patients, and a doctor's own patients) and
 * the filters. Every control maps to a filter the server's directory query
 * supports (`PatientDirectory::query`); nothing is filtered in the browser.
 * Filters live behind one button and show as removable chips once applied.
 */
export function DirectoryToolbar({
  filters,
  options,
  loading,
  onChange,
}: {
  filters: DirectoryFilters;
  options: FilterOptions;
  loading: boolean;
  onChange: (patch: Partial<DirectoryFilters>) => void;
}) {
  const [query, setQuery] = useState(filters.q);
  const [open, setOpen] = useState(false);
  const searchId = useId();
  const panelId = useId();
  const timer = useRef<number | null>(null);
  const wrapper = useRef<HTMLDivElement>(null);

  // A visit can change the search (reset filters, a link): follow it.
  useEffect(() => setQuery(filters.q), [filters.q]);
  useEffect(() => () => (timer.current !== null ? window.clearTimeout(timer.current) : undefined), []);

  // The panel closes on Escape and on a click outside it.
  useEffect(() => {
    if (!open) {
      return;
    }
    const onKey = (event: KeyboardEvent) => event.key === 'Escape' && setOpen(false);
    const onPointer = (event: PointerEvent) => wrapper.current && !wrapper.current.contains(event.target as Node) && setOpen(false);
    document.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onPointer);

    return () => {
      document.removeEventListener('keydown', onKey);
      document.removeEventListener('pointerdown', onPointer);
    };
  }, [open]);

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
  const doctorLabel = filters.practitioner_id === 'none' ? 'Not assigned' : options.doctors.find((d) => String(d.id) === filters.practitioner_id)?.name;
  const chips = [
    filters.status && { key: 'status', hue: 'teal', label: 'Status', value: statusLabel ?? filters.status, clear: { status: '' } },
    filters.practitioner_id && { key: 'doctor', hue: 'green', label: 'Doctor', value: doctorLabel ?? 'Selected doctor', clear: { practitioner_id: '' } },
    filters.branch_id && { key: 'branch', hue: 'violet', label: 'Branch', value: branchLabel ?? 'Selected branch', clear: { branch_id: '' } },
    filters.specialty && { key: 'specialty', hue: 'blue', label: 'Specialty', value: specialtyLabel ?? filters.specialty, clear: { specialty: '' } },
    filters.active_care && { key: 'care', hue: 'teal', label: '', value: 'In active care', clear: { active_care: '' } },
  ].filter(Boolean) as { key: string; hue: Hue; label: string; value: string; clear: Partial<DirectoryFilters> }[];
  const reset: Partial<DirectoryFilters> = { status: '', specialty: '', branch_id: '', practitioner_id: '', active_care: '' };

  return (
    <div ref={wrapper}>
      <div className="relative mt-4 flex flex-wrap items-center gap-2 md:flex-nowrap">
        <div className="relative min-w-0 flex-[1_1_100%] md:flex-1">
          <label htmlFor={searchId} className="sr-only">
            Search patients
          </label>
          <Search className="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-cx-m-blue" aria-hidden="true" />
          <input
            id={searchId}
            type="search"
            value={query}
            onChange={(event) => search(event.target.value)}
            placeholder="Name, patient ID or mobile"
            autoComplete="off"
            maxLength={80}
            className="h-11 w-full rounded-md border border-cx-line-strong bg-white pl-[42px] pr-11 text-[16px] text-cx-text shadow-[0_1px_2px_rgba(16,24,40,0.05)] placeholder:text-cx-text-2 hover:border-[#A9C8EE] focus:border-cx-cyan focus:outline-none focus:ring-[3px] focus:ring-cx-cyan/20 md:text-[15px] [&::-webkit-search-cancel-button]:hidden"
          />
          <div className="absolute right-1 top-1/2 flex -translate-y-1/2 items-center">
            {loading && <span className="mr-2.5 h-4 w-4 rounded-full border-2 border-cx-m-blue-ring border-t-cx-m-blue motion-safe:animate-spin" aria-hidden="true" />}
            {query && !loading && (
              <button
                type="button"
                onClick={() => search('')}
                aria-label="Clear search"
                className="grid h-9 w-9 place-items-center rounded-sm text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
              >
                <X className="h-4 w-4" aria-hidden="true" />
              </button>
            )}
          </div>
        </div>

        {options.views.length > 1 && (
          <div role="group" aria-label="Which patients" className="flex h-11 min-w-0 flex-1 rounded-md border border-cx-line-strong bg-white p-[3px] shadow-[0_1px_2px_rgba(16,24,40,0.05)] md:flex-none">
            {options.views.map((view) => (
              <button
                key={view}
                type="button"
                aria-pressed={filters.view === view}
                onClick={() => filters.view !== view && onChange({ view })}
                className={cn(
                  'h-full flex-1 whitespace-nowrap rounded-[9px] px-2.5 text-[14px] motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan md:flex-none md:px-3',
                  filters.view === view ? 'bg-cx-m-teal-bg font-semibold text-cx-teal-ink' : 'font-medium text-cx-text-2 hover:text-cx-text',
                )}
              >
                {VIEW_LABEL[view]}
              </button>
            ))}
          </div>
        )}

        <button
          type="button"
          aria-expanded={open}
          aria-controls={panelId}
          onClick={() => setOpen((value) => !value)}
          className={cn(
            'inline-flex h-11 shrink-0 items-center gap-2 rounded-md border bg-white pl-[9px] pr-[9px] text-[14px] font-semibold text-cx-slate-ink shadow-[0_1px_2px_rgba(16,24,40,0.05)] motion-safe:transition-colors hover:border-[#9CCFE0] hover:bg-cx-cyan-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan md:pr-3',
            open ? 'border-cx-m-teal bg-cx-m-teal-bg' : chips.length > 0 ? 'border-[#9EDDD3]' : 'border-cx-line-strong',
          )}
        >
          <span className="grid h-[26px] w-[26px] place-items-center rounded-[7px] bg-cx-m-teal-bg text-cx-teal-ink" aria-hidden="true">
            <SlidersHorizontal className="h-[15px] w-[15px]" />
          </span>
          <span className="sr-only md:not-sr-only">Filters</span>
          {chips.length > 0 && (
            <span className="min-w-5 rounded-full bg-cx-m-teal px-1.5 text-center text-[12px] leading-5 text-white">
              {chips.length}
              <span className="sr-only"> applied</span>
            </span>
          )}
          <ChevronDown className={cn('hidden h-4 w-4 text-cx-text-3 motion-safe:transition-transform md:block', open && 'rotate-180')} aria-hidden="true" />
        </button>

        {open && (
          <div
            id={panelId}
            role="region"
            aria-label="Filters"
            className="w-full flex-[1_1_100%] rounded-[14px] border border-cx-line bg-white p-3.5 shadow-[0_1px_2px_rgba(16,24,40,0.05)] md:absolute md:right-0 md:top-[52px] md:z-40 md:w-[560px] md:max-w-full md:p-4 md:shadow-[0_2px_6px_rgba(16,24,40,0.06),0_24px_48px_-20px_rgba(30,64,120,0.35)]"
          >
            <div className="mb-3 flex items-baseline justify-between gap-3">
              <p className="text-[15px] font-semibold leading-[22px] text-cx-text">Filter patients</p>
              <p className="hidden text-[12px] leading-4 text-cx-text-2 md:block">Applied as you choose</p>
            </div>
            <div className="grid gap-2.5 md:grid-cols-2 md:gap-3">
              {options.statuses.length > 0 && (
                <FilterSelect
                  hue="teal"
                  icon={UserRoundCheck}
                  label="Status"
                  value={filters.status}
                  placeholder="Current patients"
                  options={options.statuses.map((s) => ({ value: s.value, label: s.label }))}
                  onChange={(status) => onChange({ status })}
                />
              )}
              {options.doctors.length > 0 && (
                <FilterSelect
                  hue="green"
                  icon={Stethoscope}
                  label="Assigned doctor"
                  value={filters.practitioner_id}
                  placeholder="Any doctor"
                  options={[{ value: 'none', label: 'Not assigned' }, ...options.doctors.map((d) => ({ value: String(d.id), label: d.name }))]}
                  onChange={(practitioner_id) => onChange({ practitioner_id })}
                />
              )}
              {options.branches.length > 1 && (
                <FilterSelect
                  hue="violet"
                  icon={MapPin}
                  label="Branch"
                  value={filters.branch_id}
                  placeholder="All my branches"
                  options={options.branches.map((b) => ({ value: String(b.id), label: b.name }))}
                  onChange={(branch_id) => onChange({ branch_id })}
                />
              )}
              {options.specialties.length > 1 && (
                <FilterSelect
                  hue="blue"
                  icon={BookOpen}
                  label="Specialty"
                  value={filters.specialty}
                  placeholder="All specialties"
                  options={options.specialties.map((s) => ({ value: s.key, label: s.label }))}
                  onChange={(specialty) => onChange({ specialty })}
                />
              )}
              <div className="flex h-11 items-center rounded-[10px] border border-cx-line-strong px-3 md:h-10 md:self-end">
                <Switch checked={filters.active_care === '1'} onCheckedChange={(on) => onChange({ active_care: on ? '1' : '' })} label="In active care" />
              </div>
            </div>
            <div className="mt-4 flex items-center justify-end gap-2 border-t border-cx-line pt-3">
              <button
                type="button"
                disabled={chips.length === 0}
                onClick={() => onChange(reset)}
                className="h-11 flex-1 rounded-[10px] border border-cx-line-strong bg-white px-3.5 text-[14px] font-semibold text-cx-slate-ink hover:bg-cx-cyan-soft disabled:cursor-not-allowed disabled:text-cx-text-3 md:h-10 md:flex-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
              >
                Reset
              </button>
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="h-11 flex-1 rounded-[10px] border border-cx-m-teal-ring bg-cx-m-teal-bg px-3.5 text-[14px] font-semibold text-cx-teal-ink hover:bg-cx-m-teal-ring md:h-10 md:flex-none focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan"
              >
                Done
              </button>
            </div>
          </div>
        )}
      </div>

      {chips.length > 0 && (
        <div className="mt-2.5 flex flex-wrap items-center gap-1.5" aria-label="Applied filters">
          {chips.map((chip) => (
            <span key={chip.key} className={cn('inline-flex h-9 items-center gap-0.5 whitespace-nowrap rounded-full border pl-2.5 pr-0.5 text-[13px] font-semibold md:h-[30px]', HUES[chip.hue].chip)}>
              {chip.label && <span className="mr-1 font-medium">{chip.label}:</span>}
              {chip.value}
              <button
                type="button"
                onClick={() => onChange(chip.clear)}
                aria-label={`Remove filter ${chip.label ? `${chip.label}: ` : ''}${chip.value}`}
                className="grid h-8 w-8 place-items-center rounded-full hover:bg-white/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan md:h-[26px] md:w-[26px]"
              >
                <X className="h-3.5 w-3.5" aria-hidden="true" />
              </button>
            </span>
          ))}
          <button
            type="button"
            onClick={() => onChange(reset)}
            className="h-9 rounded-sm px-2 text-[13px] font-semibold text-cx-blue-ink hover:bg-cx-blue-bg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan md:h-[30px]"
          >
            Clear all
          </button>
        </div>
      )}
    </div>
  );
}

/** A native select dressed as the design's hued field: its icon tile fills with the hue once a value is chosen. */
function FilterSelect({
  hue,
  icon: Icon,
  label,
  value,
  placeholder,
  options,
  onChange,
}: {
  hue: Hue;
  icon: typeof Search;
  label: string;
  value: string;
  placeholder: string;
  options: { value: string; label: string }[];
  onChange: (value: string) => void;
}) {
  const id = useId();
  const on = value !== '';
  const shown = options.find((option) => option.value === value)?.label ?? placeholder;
  const tone = HUES[hue];

  return (
    <div className="min-w-0">
      <label htmlFor={id} className="mb-1.5 block text-[12px] font-semibold leading-4 text-cx-slate-ink">
        {label}
      </label>
      <div
        className={cn(
          'relative flex h-11 items-center gap-2 rounded-[10px] border pl-2 pr-8 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-cx-cyan md:h-10',
          on ? tone.on : 'border-cx-line-strong bg-white',
        )}
      >
        <span className={cn('grid h-6 w-6 shrink-0 place-items-center rounded-[7px]', on ? tone.iconOn : tone.icon)} aria-hidden="true">
          <Icon className="h-3.5 w-3.5" />
        </span>
        <span className={cn('min-w-0 flex-1 truncate text-[14px]', on ? cn('font-semibold', tone.ink) : 'font-medium text-cx-text')} aria-hidden="true">
          {shown}
        </span>
        <ChevronDown className={cn('pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2', on ? tone.ink : 'text-cx-text-3')} aria-hidden="true" />
        <select id={id} value={value} onChange={(event) => onChange(event.target.value)} className="absolute inset-0 h-full w-full cursor-pointer opacity-0">
          <option value="">{placeholder}</option>
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>
    </div>
  );
}

