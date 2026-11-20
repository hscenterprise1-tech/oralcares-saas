import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Activity, ArrowRight, ChevronLeft, ChevronRight, ClipboardCheck, ClipboardList, Eye, EyeOff, MapPin, Search, SearchX, ShieldAlert, Smile, TriangleAlert, UserPlus, UserRound, Users, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useClinic } from '../../../context';
import { Avatar, DsButton, DsButtonLink, EmptyNote, FOCUS_RING, Panel, Pill, Shimmer, TONE, isDental, isPhysio, specialtyTone, type DsTone } from '../../../ds';
import { ClinicPortalShell } from '../../../shell/ClinicPortalShell';
import { useIntake } from '../../../shell/IntakeContext';
import { deskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import type { DirectoryProps, DirectoryView } from '@/pages/ClinicPortal/Patients/components/types';
import { headingFor, lensesFor, toRow, type RegisterRow } from './model';
import { useRegisterQuery } from './useRegisterQuery';

/** Each lens's dot, in the front desk's colour families. */
const LENS_TONE: Record<DirectoryView, DsTone> = { today: 'amber', mine: 'violet', all: 'blue' };

/**
 * The Patient Directory, rebuilt in the approved front-desk design language
 * (`@/clinic/ds`, the Reception Dashboard's kit) inside the approved portal
 * shell. Its layout is the directory's own: the search and lenses up front,
 * the patients as a list, and on wide screens the selected patient's
 * summary beside it (↑ ↓ to move, Enter to open the record). Everything
 * shown is the server's (`PatientDirectory` through the `model` adapter).
 */
export function PatientRegister(props: DirectoryProps) {
  return (
    <ClinicPortalShell title={headingFor(props.audience, props.filters.view, 'doctor').title}>
      <Register {...props} />
    </ClinicPortalShell>
  );
}

/** Whether the summary stands beside the list (`xl`), asked at the moment of a click; the layout itself is CSS. */
function summaryBeside(): boolean {
  return window.matchMedia('(min-width: 1280px)').matches;
}

function Register(props: DirectoryProps) {
  const { patients, filters, options, audience, links } = props;
  const clinic = useClinic();
  const intake = useIntake();
  const mode = deskMode(clinic);
  const register = useRegisterQuery(filters, links.index);
  // Rebuilt only when a visit brings new rows (the clinic and the options do not change within a page).
  const rows = useMemo(() => patients.data.map((patient) => toRow(patient, { links, options }, deskMode(clinic))), [patients.data, links, options, clinic]);
  const [selectedId, setSelectedId] = useState<number | null>(rows[0]?.id ?? null);
  const listRef = useRef<HTMLUListElement>(null);

  // A new page of rows keeps the selection if that patient is still listed, else selects the first.
  useEffect(() => {
    setSelectedId((current) => (current !== null && rows.some((row) => row.id === current) ? current : (rows[0]?.id ?? null)));
  }, [rows]);

  const heading = headingFor(audience, filters.view, mode.doctor);
  const lenses = lensesFor(options.views);
  const selected = rows.find((row) => row.id === selectedId) ?? null;
  const multiSpecialty = options.specialties.length > 1;
  const multiBranch = (clinic?.accessible_branch_count ?? 0) > 1;
  const branches = clinic && multiBranch && clinic.accessible_branches.length >= clinic.accessible_branch_count ? clinic.accessible_branches : [];
  const doctors = filters.view === 'mine' ? [] : options.doctors;
  const activeFilters = (['specialty', 'branch_id', 'practitioner_id', 'active_care'] as const).filter((key) => filters[key]).length;
  const filtered = Boolean(filters.q || filters.status || activeFilters > 0);
  const branchName = branches.find((branch) => String(branch.id) === filters.branch_id)?.name;
  const list = listHeading(filters.q, filters.view);

  const open = (row: RegisterRow) => {
    if (row.href) {
      router.visit(row.href);
    }
  };

  // ↑ ↓ Home End move through the list; Enter opens the record.
  const onListKey = (event: KeyboardEvent<HTMLUListElement>) => {
    const buttons = Array.from(listRef.current?.querySelectorAll<HTMLButtonElement>('button[data-row]') ?? []);
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
    if (index === -1) {
      return;
    }
    const target = event.key === 'ArrowDown' ? index + 1 : event.key === 'ArrowUp' ? index - 1 : event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : null;
    if (target !== null) {
      event.preventDefault();
      const next = buttons[Math.max(0, Math.min(buttons.length - 1, target))];
      next.focus();
      setSelectedId(Number(next.dataset.row));
    }
  };

  const registerAction =
    intake.available && intake.canRegister ? (
      <DsButton variant="primary" size="lg" icon={UserPlus} onClick={() => intake.open('new')}>
        Register patient
      </DsButton>
    ) : links.create ? (
      <DsButtonLink href={links.create} variant="primary" size="lg" icon={UserPlus}>
        Register patient
      </DsButtonLink>
    ) : null;

  return (
    <div className="space-y-5 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-300">
      {/* The header in the front desk's treatment: the soft blue-lavender-mint card, the title, the action, and the search inside it. */}
      <section aria-labelledby="register-title" className="rounded-3xl border border-[#DCE6F3] bg-gradient-to-br from-[#EAF2FF] via-[#F5F1FF] to-[#E6F6F2] px-4 py-5 shadow-[0_1px_2px_rgba(15,23,42,0.04),0_18px_40px_-28px_rgba(23,59,96,0.45)] sm:px-6">
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#173B60] text-white shadow-[0_6px_14px_-8px_rgba(23,59,96,0.8)]">
              <Users className="h-5 w-5" strokeWidth={2} aria-hidden="true" />
            </span>
            <div className="min-w-0">
              <h1 id="register-title" className="text-[22px] font-bold leading-8 tracking-tight text-[#173B60] sm:text-[24px]">
                {heading.title}
              </h1>
              <p className="text-[14px] leading-[22px] text-[#475569]">{heading.line}</p>
            </div>
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2.5">
            {intake.available && intake.canRegister && links.create && (
              <DsButtonLink href={links.create} variant="secondary" size="lg" icon={ClipboardList} className="hidden shadow-[0_1px_2px_rgba(15,23,42,0.06)] md:inline-flex">
                Full form
              </DsButtonLink>
            )}
            {registerAction}
          </div>
        </div>

        <div className="relative mt-4">
          <label htmlFor="register-search" className="sr-only">
            Search patients by name, patient ID or mobile
          </label>
          <Search className="pointer-events-none absolute left-4 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[#64748B]" aria-hidden="true" />
          <input
            id="register-search"
            type="search"
            value={register.query}
            onChange={(event) => register.search(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Escape' && register.query !== '') {
                event.preventDefault();
                register.search('');
              }
              if (event.key === 'ArrowDown') {
                event.preventDefault();
                listRef.current?.querySelector<HTMLButtonElement>('button[data-row]')?.focus();
              }
            }}
            placeholder="Name, patient ID or mobile"
            autoComplete="off"
            className="h-12 w-full rounded-xl border border-[#DCE6F3] bg-white pl-11 pr-12 text-[15px] text-[#173B60] shadow-[0_1px_2px_rgba(15,23,42,0.04)] placeholder:text-[#64748B] focus:border-[#1769AA] focus:outline-none focus:ring-2 focus:ring-[#1769AA]/25"
          />
          {register.query !== '' && (
            <button type="button" onClick={() => register.search('')} aria-label="Clear search" className={cn('absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg text-[#64748B] hover:bg-[#F1F5F9]', FOCUS_RING)}>
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
        </div>
      </section>

      {/* Which patients, and the filters. */}
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        {lenses.length > 1 ? (
          <div role="tablist" aria-label="Which patients" className="flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-[#EEF2F7] p-1 [scrollbar-width:none]">
            {lenses.map((lens) => {
              const current = lens.key === filters.view;

              return (
                <button
                  key={lens.key}
                  type="button"
                  role="tab"
                  id={`lens-${lens.key}`}
                  aria-selected={current}
                  aria-controls="register-panel"
                  onClick={() => !current && register.change({ view: lens.key })}
                  className={cn(
                    'flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-3 text-[13px] font-semibold motion-safe:transition-colors [@media(pointer:coarse)]:h-11',
                    FOCUS_RING,
                    current ? 'bg-white text-[#173B60] shadow-[0_1px_3px_rgba(15,23,42,0.12)]' : 'text-[#64748B] hover:text-[#173B60]',
                  )}
                >
                  <span aria-hidden="true" className={cn('h-2 w-2 rounded-full', TONE[LENS_TONE[lens.key]].solid)} />
                  {lens.label}
                </button>
              );
            })}
          </div>
        ) : (
          <span />
        )}
        <div className="flex flex-wrap items-center gap-2">
          {multiSpecialty &&
            options.specialties.map((specialty) => {
              const on = filters.specialty === specialty.key;
              const tone = TONE[specialtyTone(specialty.key)];
              const Icon = isPhysio(specialty.key) ? Activity : isDental(specialty.key) ? Smile : null;

              return (
                <button
                  key={specialty.key}
                  type="button"
                  aria-pressed={on}
                  onClick={() => register.change({ specialty: on ? '' : specialty.key })}
                  className={cn(
                    'inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold motion-safe:transition-colors [@media(pointer:coarse)]:h-11',
                    FOCUS_RING,
                    on ? cn('border-transparent', tone.soft, tone.ink) : 'border-[#D5DDE7] bg-white text-[#475569] hover:border-[#1769AA]/50',
                  )}
                >
                  {Icon && <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
                  {specialty.label}
                </button>
              );
            })}
          {doctors.length > 0 && (
            <ChipSelect
              label={mode.doctor}
              value={filters.practitioner_id}
              onChange={(value) => register.change({ practitioner_id: value })}
              options={[{ value: 'none', label: 'Not assigned' }, ...doctors.map((doctor) => ({ value: String(doctor.id), label: doctor.name }))]}
              anyLabel={`Any ${mode.doctor.toLowerCase()}`}
            />
          )}
          {branches.length > 1 && (
            <ChipSelect label="Branch" value={filters.branch_id} onChange={(value) => register.change({ branch_id: value })} options={branches.map((branch) => ({ value: String(branch.id), label: branch.name }))} anyLabel="All my branches" />
          )}
          <button
            type="button"
            aria-pressed={filters.active_care === '1'}
            onClick={() => register.change({ active_care: filters.active_care === '1' ? '' : '1' })}
            className={cn(
              'inline-flex h-9 items-center rounded-full border px-3 text-[13px] font-semibold [@media(pointer:coarse)]:h-11',
              FOCUS_RING,
              filters.active_care === '1' ? cn('border-transparent', TONE.green.soft, TONE.green.ink) : 'border-[#D5DDE7] bg-white text-[#475569] hover:border-[#1769AA]/50',
            )}
          >
            In active care
          </button>
          {activeFilters > 0 && (
            <button type="button" onClick={() => register.change({ specialty: '', branch_id: '', practitioner_id: '', active_care: '' })} className={cn('h-9 rounded-lg px-2 text-[13px] font-semibold text-[#1769AA] hover:bg-[#EAF2FA]', FOCUS_RING)}>
              Clear filters
            </button>
          )}
        </div>
      </div>

      {/* The list, and beside it on wide screens the selected patient — the same two-column rhythm as the front desk. */}
      <div id="register-panel" role={lenses.length > 1 ? 'tabpanel' : undefined} aria-labelledby={lenses.length > 1 ? `lens-${filters.view}` : undefined} className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
        <section aria-labelledby="register-list-title" className="relative min-w-0 overflow-hidden rounded-2xl border border-[#E6EBF1] bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04),0_10px_28px_-22px_rgba(23,59,96,0.35)]">
          <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[#E6EBF1] bg-gradient-to-r from-[#F4F8FD] to-white px-4 py-3.5 sm:px-5">
            <div className="flex min-w-0 items-center gap-3">
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#173B60] text-white shadow-[0_6px_14px_-8px_rgba(23,59,96,0.8)]">
                {filters.q ? <Search className="h-5 w-5" aria-hidden="true" /> : <Users className="h-5 w-5" aria-hidden="true" />}
              </span>
              <div className="min-w-0">
                <h2 id="register-list-title" className="truncate text-[17px] font-semibold leading-6 text-[#173B60]">
                  {list.title}
                </h2>
                <p className="truncate text-[12.5px] leading-5 text-[#64748B]">{list.subtitle}</p>
              </div>
            </div>
            {multiBranch && (
              <Pill tone="violet" icon={MapPin}>
                {branchName ?? `All ${clinic?.accessible_branch_count} branches`}
              </Pill>
            )}
          </header>
          {register.loading && <span aria-hidden="true" className="absolute inset-x-0 top-0 h-0.5 bg-[#1769AA] motion-safe:animate-pulse" />}

          <div aria-live="polite" aria-busy={register.loading || undefined}>
            {register.failed ? (
              <EmptyNote
                icon={TriangleAlert}
                tone="rose"
                title="Patients could not be loaded"
                action={
                  <DsButton variant="secondary" onClick={register.retry}>
                    Try again
                  </DsButton>
                }
              >
                Nothing was changed. Check the connection and try again.
              </EmptyNote>
            ) : rows.length === 0 && register.loading ? (
              <ListSkeleton />
            ) : rows.length === 0 ? (
              <EmptyList filtered={filtered} query={filters.q} view={filters.view} canSeeAll={options.views.includes('all') && filters.view !== 'all'} onClear={register.clearAll} onSeeAll={() => register.change({ view: 'all' })} registerAction={registerAction} />
            ) : (
              <>
                <div className="hidden grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)_minmax(0,1.6fr)_minmax(0,1fr)] gap-4 border-b border-[#E6EBF1] px-5 py-2.5 text-[12px] font-semibold text-[#64748B] lg:grid">
                  <span>Patient</span>
                  <span>Mobile</span>
                  <span>{multiSpecialty ? 'Specialty and care' : 'Active care'}</span>
                  <span>{filters.view === 'today' ? 'Today’s visit' : 'Last activity'}</span>
                </div>
                <ul ref={listRef} onKeyDown={onListKey} className={cn('divide-y divide-[#EEF2F6]', register.loading && 'opacity-60')}>
                  {rows.map((row) => (
                    <PatientLine
                      key={row.id}
                      row={row}
                      selected={row.id === selectedId}
                      showSpecialty={multiSpecialty}
                      showBranch={multiBranch}
                      todayLens={filters.view === 'today'}
                      onActivate={() => (summaryBeside() ? setSelectedId(row.id) : open(row))}
                      onOpen={() => open(row)}
                    />
                  ))}
                </ul>
              </>
            )}
          </div>

          {!register.failed && (patients.prev_cursor || patients.next_cursor) && (
            <nav aria-label="Pages of patients" className="flex items-center justify-between gap-3 border-t border-[#E6EBF1] px-4 py-3 sm:px-5">
              <DsButton size="sm" icon={ChevronLeft} disabled={!patients.prev_cursor || register.loading} onClick={() => register.page(patients.prev_cursor)}>
                Newer
              </DsButton>
              <span className="text-[12.5px] tabular-nums text-[#64748B]">{rows.length} on this page</span>
              <DsButton size="sm" disabled={!patients.next_cursor || register.loading} onClick={() => register.page(patients.next_cursor)}>
                Older
                <ChevronRight className="h-4 w-4" aria-hidden="true" />
              </DsButton>
            </nav>
          )}
        </section>

        {rows.length > 0 && !register.failed && (
          <aside aria-label="Selected patient" className="sticky top-24 hidden xl:block">
            {selected ? (
              <PatientSummary
                row={selected}
                doctorWord={mode.doctor}
                showSpecialty={multiSpecialty}
                canCheckIn={intake.available && selected.lifecycle === null}
                onCheckIn={() => intake.open('repeat', { id: selected.id, uhid: selected.uhid, name: selected.name })}
              />
            ) : (
              <Panel title="Patient summary" icon={UserRound} tone="blue">
                <p className="text-[13px] text-[#64748B]">Choose a patient to see their summary.</p>
              </Panel>
            )}
          </aside>
        )}
      </div>
    </div>
  );
}

function listHeading(query: string, view: DirectoryView): { title: string; subtitle: string } {
  if (query) {
    return { title: 'Search results', subtitle: `Matching “${query}”` };
  }
  if (view === 'today') {
    return { title: 'Checked in today', subtitle: 'In the order they arrived' };
  }
  if (view === 'mine') {
    return { title: 'Assigned to you', subtitle: 'Patients you look after' };
  }

  return { title: 'All patients', subtitle: 'Newest registrations first' };
}

/** A filter as a chip over the browser's own list (reliable on every device and screen reader). */
function ChipSelect({ label, value, options, anyLabel, onChange }: { label: string; value: string; options: { value: string; label: string }[]; anyLabel: string; onChange: (value: string) => void }) {
  const chosen = options.find((option) => option.value === value);

  return (
    <label
      className={cn(
        'relative inline-flex h-9 items-center rounded-full border text-[13px] font-semibold focus-within:ring-2 focus-within:ring-[#1769AA] focus-within:ring-offset-2 [@media(pointer:coarse)]:h-11',
        chosen ? cn('border-transparent', TONE.blue.soft, TONE.blue.ink) : 'border-[#D5DDE7] bg-white text-[#475569] hover:border-[#1769AA]/50',
      )}
    >
      <span className="pointer-events-none whitespace-nowrap pl-3 pr-1">{label}:</span>
      <span className="pointer-events-none max-w-[11rem] truncate pr-7">{chosen?.label ?? 'Any'}</span>
      <svg aria-hidden="true" viewBox="0 0 12 12" className="pointer-events-none absolute right-2.5 h-3 w-3" fill="none" stroke="currentColor" strokeWidth="1.8">
        <path d="M3 4.5 6 7.5 9 4.5" />
      </svg>
      <select aria-label={label} value={value} onChange={(event) => onChange(event.target.value)} className="absolute inset-0 cursor-pointer appearance-none rounded-full opacity-0">
        <option value="">{anyLabel}</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  );
}

function SpecialtyPill({ specialty, label }: { specialty: string; label: string }) {
  return (
    <Pill tone={specialtyTone(specialty)} icon={isPhysio(specialty) ? Activity : isDental(specialty) ? Smile : undefined}>
      {label}
    </Pill>
  );
}

function PatientLine({
  row,
  selected,
  showSpecialty,
  showBranch,
  todayLens,
  onActivate,
  onOpen,
}: {
  row: RegisterRow;
  selected: boolean;
  showSpecialty: boolean;
  showBranch: boolean;
  todayLens: boolean;
  onActivate: () => void;
  onOpen: () => void;
}) {
  const care = row.care[0];
  const when = todayLens && row.today ? <Pill tone={row.today.tone}>{row.today.label}</Pill> : <span className="text-[13px] tabular-nums text-[#173B60]">{row.lastActivity ?? 'No activity yet'}</span>;

  return (
    <li className="relative">
      {/* The selection shows only where the summary does (xl); below that a row simply opens the record. */}
      {selected && <span aria-hidden="true" className="absolute inset-y-0 left-0 hidden w-[3px] bg-[#1769AA] xl:block" />}
      <button
        type="button"
        data-row={row.id}
        aria-current={selected || undefined}
        onClick={onActivate}
        onKeyDown={(event) => {
          if (event.key === 'Enter') {
            event.preventDefault();
            onOpen();
          }
        }}
        className={cn(
          'grid w-full grid-cols-1 gap-2 px-4 py-3.5 text-left motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#1769AA] sm:px-5 lg:grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)_minmax(0,1.6fr)_minmax(0,1fr)] lg:items-center lg:gap-4',
          selected ? 'hover:bg-[#F8FAFC] xl:bg-[#F5F9FF] xl:hover:bg-[#F5F9FF]' : 'hover:bg-[#F8FAFC]',
        )}
      >
        <span className="flex min-w-0 items-center gap-3">
          <Avatar name={row.name} />
          <span className="min-w-0">
            <span className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
              <span className="truncate text-[14px] font-semibold text-[#173B60]">{row.name}</span>
              {row.lifecycle && <Pill tone="slate">Merged</Pill>}
              {row.alerts > 0 && (
                <Pill tone="rose" icon={ShieldAlert}>
                  {row.alerts} {row.alerts === 1 ? 'alert' : 'alerts'}
                </Pill>
              )}
            </span>
            <span className="mt-0.5 block truncate text-[12.5px] text-[#64748B]">{[row.uhid, row.facts].filter(Boolean).join(' · ')}</span>
          </span>
        </span>

        {/* Below lg: mobile and the visit share the second line; from lg each has its column. */}
        <span className="flex items-center justify-between gap-3 pl-[52px] lg:block lg:pl-0">
          <span className="text-[13px] tabular-nums text-[#475569]">{row.phoneTail ? `••• ${row.phoneTail}` : 'No mobile'}</span>
          <span className="lg:hidden">{when}</span>
        </span>

        <span className={cn('min-w-0 pl-[52px] lg:pl-0', !showSpecialty && !care && 'hidden lg:block')}>
          {(showSpecialty && row.specialties.length > 0) || care ? (
            <span className="flex min-w-0 flex-wrap items-center gap-1.5">
              {showSpecialty && row.specialties.map((specialty) => <SpecialtyPill key={specialty.key} specialty={specialty.key} label={specialty.label} />)}
              {care && (
                <span className="truncate text-[12.5px] text-[#475569]">
                  {care.title} · {care.total ? `${care.done}/${care.total}` : care.done} {care.unit}
                </span>
              )}
            </span>
          ) : (
            <span className="text-[13px] text-[#64748B]">—</span>
          )}
        </span>

        <span className="hidden min-w-0 lg:block">
          {when}
          {showBranch && (todayLens ? row.today?.branch : row.lastBranch) && (
            <span className="mt-0.5 block truncate text-[12px] text-[#64748B]">
              {todayLens ? row.today?.branch : row.lastBranch}
              {!todayLens && row.otherBranches > 0 && ` +${row.otherBranches}`}
            </span>
          )}
        </span>
      </button>
    </li>
  );
}

/** The selected patient, in a front-desk side panel: who they are, how to reach them, their care, and the two actions that matter. */
function PatientSummary({ row, doctorWord, showSpecialty, canCheckIn, onCheckIn }: { row: RegisterRow; doctorWord: string; showSpecialty: boolean; canCheckIn: boolean; onCheckIn: () => void }) {
  const [reveal, setReveal] = useState(false);

  useEffect(() => setReveal(false), [row.id]);

  return (
    <Panel title="Patient summary" icon={UserRound} tone="blue">
      <div className="flex items-start gap-3">
        <Avatar name={row.name} />
        <div className="min-w-0">
          <p className="text-[16px] font-semibold leading-6 text-[#173B60] [overflow-wrap:anywhere]">{row.name}</p>
          <p className="text-[12.5px] text-[#64748B]">{[row.uhid, row.facts].filter(Boolean).join(' · ') || 'No details recorded'}</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {showSpecialty && row.specialties.map((specialty) => <SpecialtyPill key={specialty.key} specialty={specialty.key} label={specialty.label} />)}
            {row.lifecycle && <Pill tone="slate">Merged</Pill>}
            {row.alerts > 0 && (
              <Pill tone="rose" icon={ShieldAlert}>
                {row.alerts} safety {row.alerts === 1 ? 'alert' : 'alerts'}
              </Pill>
            )}
            {row.today && <Pill tone={row.today.tone}>{row.today.label}</Pill>}
          </div>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-2">
        {row.href ? (
          <DsButtonLink href={row.href} variant="primary" className={canCheckIn ? '' : 'col-span-2'}>
            Open record
            <ArrowRight className="h-4 w-4" aria-hidden="true" />
          </DsButtonLink>
        ) : (
          <p className="col-span-2 text-[12.5px] text-[#64748B]">The full record cannot be opened here yet.</p>
        )}
        {canCheckIn && (
          <DsButton variant="secondary" icon={ClipboardCheck} onClick={onCheckIn} className={row.href ? '' : 'col-span-2'}>
            Check in
          </DsButton>
        )}
      </div>

      <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 rounded-xl bg-[#F6F8FB] px-3.5 py-3">
        <SummaryFact label="Mobile">
          {row.phone ? (
            <span className="flex items-center gap-1">
              <span className="tabular-nums">{reveal ? row.phone : `••• ${row.phoneTail ?? ''}`}</span>
              <button type="button" onClick={() => setReveal((on) => !on)} aria-label={reveal ? 'Hide the mobile number' : 'Show the full mobile number'} className={cn('-my-1 flex h-8 w-8 items-center justify-center rounded-lg text-[#64748B] hover:bg-white', FOCUS_RING)}>
                {reveal ? <EyeOff className="h-4 w-4" aria-hidden="true" /> : <Eye className="h-4 w-4" aria-hidden="true" />}
              </button>
            </span>
          ) : (
            <span className="tabular-nums">{row.phoneTail ? `••• ${row.phoneTail}` : <Quiet>Not recorded</Quiet>}</span>
          )}
        </SummaryFact>
        <SummaryFact label={doctorWord}>
          {row.doctor ? (
            <>
              {row.doctor.name}
              {!row.doctor.atClinic && <span className="block text-[12px] text-[#A35A06]">No longer at the clinic</span>}
            </>
          ) : (
            <Quiet>Not assigned</Quiet>
          )}
        </SummaryFact>
        <SummaryFact label="Last activity">
          {row.lastActivity ?? <Quiet>No activity yet</Quiet>}
          {row.lastBranch && <span className={cn('block text-[12px]', TONE.violet.ink)}>{row.lastBranch}</span>}
        </SummaryFact>
        <SummaryFact label="Registered">{row.registered ?? <Quiet>Not recorded</Quiet>}</SummaryFact>
        {row.email && (
          <SummaryFact label="Email" className="col-span-2">
            <span className="[overflow-wrap:anywhere]">{row.email}</span>
          </SummaryFact>
        )}
      </dl>

      <div className="mt-4">
        <p className="text-[12px] font-semibold text-[#64748B]">Active care</p>
        {row.care.length === 0 ? (
          <p className="mt-1 text-[13px] text-[#475569]">Nothing under way.</p>
        ) : (
          <ul className="mt-2 space-y-3">
            {row.care.map((care) => {
              const tone = care.specialty ? specialtyTone(care.specialty) : 'blue';
              const share = care.total ? Math.min(1, care.done / care.total) : null;

              return (
                <li key={care.id}>
                  <p className="truncate text-[13.5px] font-semibold text-[#173B60]">{care.title}</p>
                  <p className="text-[12.5px] tabular-nums text-[#475569]">{care.total ? `${care.done} of ${care.total} ${care.unit}` : `${care.done} ${care.unit} so far`}</p>
                  {share !== null && (
                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-[#EEF2F6]" role="progressbar" aria-valuemin={0} aria-valuemax={care.total ?? 0} aria-valuenow={care.done} aria-label={`${care.title}: ${care.done} of ${care.total} ${care.unit}`}>
                      <div className={cn('h-full rounded-full', TONE[tone].solid)} style={{ width: `${Math.round(share * 100)}%` }} />
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </Panel>
  );
}

function SummaryFact({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={cn('min-w-0', className)}>
      <dt className="text-[12px] font-semibold text-[#64748B]">{label}</dt>
      <dd className="mt-0.5 min-w-0 text-[13.5px] font-medium leading-5 text-[#173B60]">{children}</dd>
    </div>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return <span className="font-normal text-[#64748B]">{children}</span>;
}

function ListSkeleton() {
  return (
    <ul aria-label="Loading patients" className="divide-y divide-[#EEF2F6]">
      {[0, 1, 2, 3, 4].map((index) => (
        <li key={index} className="flex items-center gap-3 px-5 py-4">
          <Shimmer className="h-10 w-10 rounded-full" />
          <span className="flex-1 space-y-2">
            <Shimmer className="h-3.5 w-1/3" />
            <Shimmer className="h-3 w-1/5" />
          </span>
        </li>
      ))}
    </ul>
  );
}

function EmptyList({
  filtered,
  query,
  view,
  canSeeAll,
  onClear,
  onSeeAll,
  registerAction,
}: {
  filtered: boolean;
  query: string;
  view: DirectoryView;
  canSeeAll: boolean;
  onClear: () => void;
  onSeeAll: () => void;
  registerAction: ReactNode;
}) {
  if (filtered) {
    return (
      <EmptyNote
        icon={SearchX}
        tone="slate"
        title={query ? `No patient matches “${query}”` : 'No patient matches these filters'}
        action={
          <DsButton variant="secondary" onClick={onClear}>
            Clear search and filters
          </DsButton>
        }
      >
        {query ? 'Search looks at the start of names, patient IDs and mobile numbers. Try fewer letters or the last digits of the mobile.' : 'Loosen a filter to see more patients.'}
      </EmptyNote>
    );
  }
  if (view === 'today') {
    return (
      <EmptyNote icon={Users} tone="amber" title="Nobody checked in yet today" action={canSeeAll ? <DsButton variant="secondary" onClick={onSeeAll}>See all patients</DsButton> : undefined}>
        Patients appear here as they are checked in.
      </EmptyNote>
    );
  }
  if (view === 'mine') {
    return (
      <EmptyNote icon={UserRound} tone="violet" title="No patients assigned to you yet" action={canSeeAll ? <DsButton variant="secondary" onClick={onSeeAll}>See all patients</DsButton> : undefined}>
        A patient appears here once you are their assigned doctor.
      </EmptyNote>
    );
  }

  return (
    <EmptyNote icon={Users} tone="blue" title="No patients registered yet" action={registerAction}>
      Registered patients appear here, newest first.
    </EmptyNote>
  );
}
