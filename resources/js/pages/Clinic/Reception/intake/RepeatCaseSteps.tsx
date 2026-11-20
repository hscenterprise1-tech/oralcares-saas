import { useEffect, useId, useState, type KeyboardEvent, type ReactNode } from 'react';
import { Activity, CalendarClock, ChevronRight, ClipboardList, IndianRupee, PackageCheck, Phone, RefreshCw, Search, ShieldCheck, Stethoscope, UserCheck, UserPlus, X } from 'lucide-react';
import type { PhysioCare } from '@/clinic/patients/PhysioCare';
import { cn } from '@/lib/utils';
import { BillingPaymentSummary, type PaymentState } from './BillingPaymentSummary';
import { IconChip } from './IconChip';
import { SafetyFlags } from './SafetyFlags';
import { formatDay, maskPhone, type IntakeConfig, type LookupPatient, type PractitionerOption } from './types';

/*
 * Repeat Case, step by step, on the same surface and in the same language as
 * New Case: slate type, teal for what is chosen, 50px fields, 12–16px radii and
 * no card inside a card. Presentation only: the dialog
 * (`ReceptionIntakeDialog`) keeps the state, the rules and the requests.
 */

/** Pastel avatar tiles, as New Case and the search rows draw a patient. */
const AVATAR_TONES = [
  'bg-teal-50 text-teal-700 ring-teal-200',
  'bg-blue-50 text-blue-700 ring-blue-200',
  'bg-violet-50 text-violet-700 ring-violet-200',
  'bg-indigo-50 text-indigo-700 ring-indigo-200',
  'bg-rose-50 text-rose-700 ring-rose-200',
  'bg-emerald-50 text-emerald-700 ring-emerald-200',
];

const FIELD =
  'h-[50px] w-full rounded-xl border border-[#DCE6F3] bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] transition-colors hover:border-[#B9C6D8] focus:border-[#1769AA] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20 disabled:opacity-60';

const LABEL = 'mb-1.5 block text-[12px] font-semibold leading-none text-slate-700';

function initialsOf(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
}

function Avatar({ patient, size = 'md' }: { patient: Pick<LookupPatient, 'id' | 'name'>; size?: 'sm' | 'md' }) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid shrink-0 place-items-center font-semibold ring-1 ring-inset',
        size === 'md' ? 'h-11 w-11 rounded-xl text-[14px]' : 'h-10 w-10 rounded-xl text-[13px]',
        AVATAR_TONES[patient.id % AVATAR_TONES.length],
      )}
    >
      {initialsOf(patient.name)}
    </span>
  );
}

/** Small status pills a row or the patient bar carries: in the queue, booked today. */
function StatusPills({ patient }: { patient: LookupPatient }) {
  return (
    <>
      {patient.in_queue && (
        <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">
          <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true" />
          In today’s queue
        </span>
      )}
      {patient.appointment && !patient.in_queue && (
        <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-800 ring-1 ring-inset ring-blue-200">
          <CalendarClock className="h-3 w-3" aria-hidden="true" />
          Booked {patient.appointment.time.slice(11, 16)}
        </span>
      )}
    </>
  );
}

/** Mobile (masked), age and sex in one line: the facts that confirm who this is. */
function factsOf(patient: LookupPatient, masked = true): string[] {
  return [
    masked ? maskPhone(patient.phone) : patient.phone,
    patient.age !== null ? `${patient.age} yrs` : null,
    patient.gender ? patient.gender.charAt(0).toUpperCase() + patient.gender.slice(1) : null,
  ].filter((fact): fact is string => Boolean(fact));
}

/* ------------------------------------------------------------------ search */

/**
 * Find the patient: one prominent field (name, mobile or UHID), then one list
 * of fixed height — the recent patients while the field is empty, the matches
 * once it has three characters — so the dialog never moves while it searches.
 * Arrow keys move through the rows, Enter picks the highlighted one; nothing
 * is picked for the receptionist. A record merged into another cannot be
 * chosen.
 */
export function RepeatPatientSearch({
  query,
  onQueryChange,
  results,
  recent,
  searching,
  onSelect,
  onRegisterNew,
}: {
  query: string;
  onQueryChange: (query: string) => void;
  results: LookupPatient[];
  recent: LookupPatient[];
  searching: boolean;
  onSelect: (patient: LookupPatient) => void;
  /** No match: register them with New Case instead (absent when this member may not register). */
  onRegisterNew: (() => void) | null;
}) {
  const listId = useId();
  const typed = query.trim();
  const isSearch = typed.length >= 3;
  const rows = isSearch ? results : recent;
  const [active, setActive] = useState(-1);

  // A new list: nothing highlighted until the keyboard or the pointer says so.
  useEffect(() => setActive(-1), [rows]);

  const choosable = (patient: LookupPatient) => patient.status !== 'merged';

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (rows.length === 0) {
      return;
    }
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((current) => (current + 1) % rows.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((current) => (current <= 0 ? rows.length - 1 : current - 1));
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const patient = rows[active];
      if (patient && choosable(patient)) {
        onSelect(patient);
      }
    }
  };

  const caption = isSearch
    ? searching
      ? 'Searching…'
      : `${results.length} matching ${results.length === 1 ? 'patient' : 'patients'}`
    : typed.length > 0
      ? 'Type at least 3 characters'
      : 'Recent patients';

  return (
    <div className="space-y-3">
      <div className="relative">
        <Search className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" aria-hidden="true" />
        <input
          type="search"
          autoFocus
          role="combobox"
          aria-expanded={rows.length > 0}
          aria-controls={listId}
          aria-activedescendant={active >= 0 ? `${listId}-${active}` : undefined}
          aria-autocomplete="list"
          aria-label="Find the patient by name, mobile or UHID"
          placeholder="Name, mobile or UHID"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          onKeyDown={onKeyDown}
          enterKeyHint="search"
          className={cn(FIELD, 'pl-12 pr-11 text-[14.5px] font-medium [&::-webkit-search-cancel-button]:hidden')}
        />
        {query && (
          <button
            type="button"
            onClick={() => onQueryChange('')}
            aria-label="Clear search"
            className="absolute right-3 top-1/2 grid h-8 w-8 -translate-y-1/2 place-items-center rounded-full text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/40"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        )}
      </div>

      <p className="flex h-5 items-center justify-between px-0.5 text-[12px] font-semibold text-slate-500" aria-live="polite">
        <span>{caption}</span>
        {searching && <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-teal-600 border-t-transparent motion-reduce:animate-none" aria-hidden="true" />}
      </p>

      {/* One height whatever it holds (recent, matches, searching, nothing): the dialog stays put; only the list scrolls. */}
      <ul
        id={listId}
        role="listbox"
        aria-label="Patients"
        className="h-[min(420px,calc(100dvh-330px))] min-h-[220px] overflow-y-auto overscroll-contain rounded-2xl border border-[#DCE6F3] bg-white"
      >
        {rows.map((patient, index) => {
          const enabled = choosable(patient);

          return (
            <li
              key={patient.id}
              id={`${listId}-${index}`}
              role="option"
              aria-selected={index === active}
              aria-disabled={!enabled || undefined}
              onMouseEnter={() => setActive(index)}
              onClick={() => enabled && onSelect(patient)}
              className={cn(
                // The Owner Dashboard's list row: 56px at least, a hairline between rows, the canvas tint on hover.
                'group grid min-h-[60px] cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3.5 border-b border-cx-line px-4 py-3 last:border-b-0 motion-safe:transition-colors md:grid-cols-[auto_minmax(0,1.3fr)_minmax(0,1fr)_auto]',
                index === active ? 'bg-cx-teal-bg/60' : 'hover:bg-cx-nav-canvas',
                !enabled && 'cursor-not-allowed opacity-60',
              )}
            >
              <Avatar patient={patient} size="sm" />
              <span className="min-w-0">
                <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span className={cn('text-[14px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]', index === active && 'text-teal-800')}>{patient.name}</span>
                  {patient.uhid && <span className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-slate-600">{patient.uhid}</span>}
                  <StatusPills patient={patient} />
                </span>
                <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[12px] text-slate-500">
                  {factsOf(patient).map((fact, i) => (
                    <span key={fact} className={cn('inline-flex items-center gap-1', i === 0 && 'font-semibold tabular-nums text-slate-600')}>
                      {i === 0 && <Phone className="h-3 w-3 text-slate-400" aria-hidden="true" />}
                      {i > 0 && <span aria-hidden="true">·</span>}
                      {fact}
                    </span>
                  ))}
                  {patient.last_seen && <span className="md:hidden">· Last visit {formatDay(patient.last_seen.at)}</span>}
                </span>
              </span>
              {/* From a tablet up, the visit history gets its own column instead of crowding the name. */}
              <span className="hidden min-w-0 text-[12px] leading-5 text-slate-500 md:block">
                {patient.last_seen ? (
                  <>
                    <span className="block font-semibold text-slate-700">Last visit {formatDay(patient.last_seen.at)}</span>
                    <span className="block truncate">{patient.last_seen.branch}</span>
                  </>
                ) : (
                  <span>No visits yet</span>
                )}
              </span>
              <ChevronRight className={cn('h-5 w-5 text-slate-300 motion-safe:transition-transform', enabled && 'group-hover:translate-x-0.5 group-hover:text-teal-600')} aria-hidden="true" />
            </li>
          );
        })}

        {isSearch && !searching && results.length === 0 && (
          <li className="grid h-full place-items-center px-6 text-center">
            <div className="space-y-2.5">
              <p className="text-[13.5px] font-semibold text-slate-700">No patient matches “{typed}”</p>
              <p className="text-[12px] text-slate-500">Check the spelling, the mobile number or the UHID.</p>
              {onRegisterNew && (
                <button
                  type="button"
                  onClick={onRegisterNew}
                  className="mt-1 inline-flex h-10 items-center gap-1.5 rounded-xl bg-[#1769AA] px-4 text-[13px] font-semibold text-white shadow-xs transition-colors hover:bg-[#135A93] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/20"
                >
                  <UserPlus className="h-4 w-4" aria-hidden="true" />
                  Register as a new patient
                </button>
              )}
            </div>
          </li>
        )}

        {!isSearch && recent.length === 0 && (
          <li className="grid h-full place-items-center px-6 text-center text-[12.5px] text-slate-500">Type a name, a mobile number or a UHID to find the patient.</li>
        )}
      </ul>
    </div>
  );
}

/* ------------------------------------------------------------- patient bar */

/**
 * Who was chosen, once, at the top of every later step: name, UHID, mobile,
 * age and sex, the last visit, today's booking or place in the queue, and the
 * safety flags — on a soft tint that belongs to the dialog, not a card of its
 * own — with the way back to the search.
 */
export function RepeatPatientBar({ patient, onChange, disabled = false }: { patient: LookupPatient; onChange: () => void; disabled?: boolean }) {
  const facts = factsOf(patient);
  const lastSeen = patient.last_seen ? `Last visit ${formatDay(patient.last_seen.at)} · ${patient.last_seen.branch}` : null;

  return (
    <section aria-label="Patient" className="rounded-2xl bg-gradient-to-r from-teal-50/70 via-slate-50 to-blue-50/50 px-4 py-3.5 ring-1 ring-inset ring-slate-200/80">
      <div className="flex items-start gap-3.5">
        <Avatar patient={patient} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 className="text-[15.5px] font-semibold leading-6 text-cx-nav-ink [overflow-wrap:anywhere]">{patient.name}</h3>
            {patient.uhid && <span className="rounded-md bg-white px-1.5 py-0.5 font-mono text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">{patient.uhid}</span>}
            <StatusPills patient={patient} />
          </div>
          <p className="mt-0.5 text-[12.5px] text-slate-600">{[...facts, lastSeen].filter(Boolean).join(' · ')}</p>
          {patient.safety_flags.length > 0 && <SafetyFlags flags={patient.safety_flags} compact className="mt-2" />}
        </div>
        <button
          type="button"
          onClick={onChange}
          disabled={disabled}
          className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-xl bg-white px-3 text-[12.5px] font-semibold text-slate-700 shadow-2xs ring-1 ring-inset ring-slate-200 transition-colors hover:bg-slate-50 hover:text-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500/40 disabled:opacity-50 [@media(pointer:coarse)]:h-11"
        >
          <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
          <span className="max-sm:sr-only">Change</span>
        </button>
      </div>
    </section>
  );
}

/** One fact inside a section, as the Owner Dashboard lists them: a soft tile, the fact, one line of detail. */
function InfoRow({ icon, tone, label, detail, labelClassName }: { icon: typeof Stethoscope; tone: 'blue' | 'teal' | 'violet' | 'amber' | 'slate'; label: string; detail?: string; labelClassName?: string }) {
  return (
    <div className="flex min-w-0 items-center gap-3 rounded-md bg-white px-3 py-2.5 ring-1 ring-inset ring-cx-line/80">
      <IconChip icon={icon} tone={tone} size="sm" variant="soft" />
      <span className="min-w-0">
        <span className={cn('block text-[13.5px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]', labelClassName)}>{label}</span>
        {detail && <span className="block text-[12px] leading-4 text-cx-text-3">{detail}</span>}
      </span>
    </div>
  );
}

/**
 * A section of a step, opened as New Case and the Owner Dashboard open
 * theirs: the colour tile, the title, an optional caption and aside.
 */
function StepSection({
  icon,
  tone,
  title,
  caption,
  aside,
  children,
  className,
}: {
  icon: typeof Stethoscope;
  tone: 'teal' | 'blue' | 'violet';
  title: string;
  caption?: string;
  aside?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <section className={cn('min-w-0 space-y-3.5', className)}>
      <div className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <IconChip icon={icon} tone={tone} size="md" variant="solid" />
          <div className="min-w-0">
            <h4 className="text-[15px] font-semibold leading-5 tracking-tight text-cx-nav-ink">{title}</h4>
            {caption && <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{caption}</p>}
          </div>
        </div>
        {aside}
      </div>
      {children}
    </section>
  );
}

/* -------------------------------------------------------------- care choice */

/** A combined clinic, a patient under both: which care today. Nothing is chosen for the desk. */
export function RepeatCareChoice({ onChoose }: { onChoose: (stream: 'dental' | 'physio') => void }) {
  const option = (stream: 'dental' | 'physio', title: string, hint: string, icon: typeof Stethoscope, tone: 'blue' | 'teal') => (
    <button
      type="button"
      onClick={() => onChoose(stream)}
      className={cn(
        'group flex items-center gap-3.5 rounded-2xl border border-[#DCE6F3] bg-white p-4 text-left shadow-2xs transition-all hover:shadow-xs focus-visible:outline-none focus-visible:ring-2 motion-safe:active:scale-[0.99]',
        tone === 'blue' ? 'hover:border-blue-300 hover:bg-blue-50/40 focus-visible:ring-blue-500/15' : 'hover:border-teal-300 hover:bg-teal-50/40 focus-visible:ring-teal-500/15',
      )}
    >
      <IconChip icon={icon} tone={tone} size="md" variant="solid" />
      <span className="min-w-0 flex-1">
        <span className="block text-[14px] font-semibold text-cx-nav-ink">{title}</span>
        <span className="block text-[12px] text-slate-500">{hint}</span>
      </span>
      <ChevronRight className="h-5 w-5 text-slate-300 group-hover:text-slate-500" aria-hidden="true" />
    </button>
  );

  return (
    <StepSection icon={CalendarClock} tone="blue" title="Which visit is today?">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        {option('dental', 'Dental follow-up', 'With the assigned doctor, fee at check-in', Stethoscope, 'blue')}
        {option('physio', 'Physiotherapy session', 'Choose today’s therapist', Activity, 'teal')}
      </div>
    </StepSection>
  );
}

/* ------------------------------------------------------------------- physio */

/** The physiotherapists among today's practitioners at this branch; everyone, when none is recorded under physiotherapy. */
export function physioTherapists(config: IntakeConfig | null): PractitionerOption[] {
  const doctors = config?.doctors ?? [];
  const physio = doctors.filter((doctor) => doctor.module?.key === 'physiotherapy' || doctor.contexts.some((context) => context.specialty === 'physiotherapy' || context.module === 'physiotherapy'));

  return physio.length > 0 ? physio : doctors;
}

/** The therapist to offer first: one on shift, else the first listed. */
export function defaultTherapist(config: IntakeConfig | null): PractitionerOption | null {
  const therapists = physioTherapists(config);

  return therapists.find((therapist) => therapist.shift === 'on_shift') ?? therapists[0] ?? null;
}

/**
 * Physiotherapy repeat visit: the treatment and its package beside today's
 * therapist. The check-in costs nothing at the desk and uses no package
 * session — a session is counted only when the treatment is completed — so
 * the package is shown, never charged here. The therapist list is the
 * branch's practitioners for today, physiotherapists first.
 */
export function RepeatPhysioStep({
  config,
  care,
  treatmentId,
  onTreatment,
  loadingCare,
  therapistId,
  onTherapist,
  focus,
  onFocus,
  error,
  disabled = false,
}: {
  config: IntakeConfig | null;
  care: PhysioCare | null;
  /** The treatment this visit continues; with two or more under way the desk picks it. */
  treatmentId: number | null;
  onTreatment: (id: number) => void;
  loadingCare: boolean;
  therapistId: string;
  onTherapist: (id: string) => void;
  focus: string;
  onFocus: (value: string) => void;
  error?: string;
  disabled?: boolean;
}) {
  const therapists = physioTherapists(config);
  const treatment = care?.treatments.find((item) => item.id === treatmentId) ?? care?.treatments[0] ?? null;
  const usable = treatment?.packages.find((pkg) => pkg.remaining > 0) ?? null;
  const others = care && care.treatments.length > 1 ? care.treatments : [];

  return (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] md:gap-0 md:divide-x md:divide-slate-200/80">
      <StepSection icon={Activity} tone="teal" title="Treatment" caption="The care this visit continues" className="md:pr-6">
        {loadingCare ? null : treatment ? (
          <div className="space-y-2.5">
            {others.length > 0 && (
              <div role="radiogroup" aria-label="Which treatment" className="flex flex-wrap gap-1.5">
                {others.map((item) => (
                  <button
                    key={item.id}
                    type="button"
                    role="radio"
                    aria-checked={item.id === treatment.id}
                    disabled={disabled}
                    onClick={() => onTreatment(item.id)}
                    className={cn(
                      'min-h-9 rounded-full border px-3 text-[12.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35',
                      item.id === treatment.id ? 'border-transparent bg-[#6B48DE] text-white' : 'border-[#DCE6F3] bg-white text-cx-text-2 hover:border-[#B9C6D8]',
                    )}
                  >
                    {item.title}
                  </button>
                ))}
              </div>
            )}
            <InfoRow
              icon={ClipboardList}
              tone="violet"
              label={treatment.title}
              detail={`${treatment.sessions_completed} ${treatment.sessions_completed === 1 ? 'session' : 'sessions'} done${treatment.sessions_planned !== null ? ` of ${treatment.sessions_planned} planned` : ''}`}
            />
            {/* The package, as a dashboard figure on its wash: what is left of what was bought, and a meter. */}
            {usable ? (
              <div className="rounded-md bg-gradient-to-br from-[#EAFAF6] to-[#DCF3EE] px-4 py-3">
                <span className="flex items-center justify-between gap-3">
                  <span className="flex min-w-0 items-center gap-1.5 text-[12.5px] font-medium text-cx-teal-ink">
                    <PackageCheck className="h-4 w-4 shrink-0" strokeWidth={1.75} aria-hidden="true" />
                    <span className="truncate">{usable.name}</span>
                  </span>
                  <span className="shrink-0 text-[12px] text-cx-text-2">of {usable.sessions_total}</span>
                </span>
                <span className="mt-1 block text-[20px] font-semibold leading-6 text-cx-nav-ink tabular-nums">
                  {usable.remaining} {usable.remaining === 1 ? 'session' : 'sessions'} left
                </span>
                <span className="mt-2 block h-1.5 overflow-hidden rounded-full bg-white/80" aria-hidden="true">
                  <span className="block h-full rounded-full bg-[#0E9F87]" style={{ width: `${Math.round((usable.remaining / Math.max(usable.sessions_total, 1)) * 100)}%` }} />
                </span>
              </div>
            ) : (
              <div className="rounded-md bg-gradient-to-br from-[#F4F6FA] to-[#EBEFF5] px-4 py-3 text-[12.5px] font-medium text-cx-text-2">No package sessions left on this treatment.</div>
            )}
            {care?.amount_due !== null && care?.amount_due !== undefined && care.amount_due > 0 && (
              <InfoRow icon={IndianRupee} tone="amber" label={`₹${care.amount_due.toLocaleString('en-IN')} due`} detail="On earlier bills" labelClassName="text-cx-amber-ink" />
            )}
          </div>
        ) : (
          <InfoRow icon={ClipboardList} tone="slate" label="No treatment under way" detail="Today’s therapist can start one." />
        )}
        <p className="flex items-start gap-2 text-[12px] leading-5 text-cx-text-2">
          <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-cx-teal-ink" strokeWidth={1.75} aria-hidden="true" />
          No charge at check-in. A package session is used only when the therapist completes the treatment.
        </p>
      </StepSection>

      <StepSection
        icon={UserCheck}
        tone="blue"
        title="Today’s therapist"
        caption="For this session only"
        className="md:pl-6"
        aside={config?.branch?.name ? <span className="truncate text-[11.5px] font-semibold text-slate-500">{config.branch.name}</span> : undefined}
      >
        <div>
          <label htmlFor="repeat-therapist" className={LABEL}>
            Treating therapist <span className="text-rose-500">*</span>
          </label>
          <select
            id="repeat-therapist"
            value={therapistId}
            onChange={(event) => onTherapist(event.target.value)}
            disabled={disabled}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? 'repeat-therapist-error' : undefined}
            className={cn(FIELD, 'font-semibold', error && 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15')}
          >
            <option value="" disabled>
              Choose today’s therapist
            </option>
            {therapists.map((therapist) => (
              <option key={therapist.id} value={String(therapist.id)}>
                {therapist.name}
                {therapist.shift_label ? ` · ${therapist.shift_label}` : ''}
              </option>
            ))}
          </select>
          {error ? (
            <p id="repeat-therapist-error" className="mt-1.5 text-[11.5px] font-medium text-rose-600">
              {error}
            </p>
          ) : (
            <p className="mt-1.5 text-[11.5px] text-slate-500">The patient joins this therapist’s queue.</p>
          )}
        </div>
        <div>
          <label htmlFor="repeat-session-focus" className={LABEL}>
            Session focus <span className="font-normal text-slate-400">· optional</span>
          </label>
          <input
            id="repeat-session-focus"
            type="text"
            value={focus}
            onChange={(event) => onFocus(event.target.value)}
            placeholder="e.g. Mobilisation and strengthening"
            disabled={disabled}
            maxLength={255}
            className={FIELD}
          />
        </div>
      </StepSection>
    </div>
  );
}

/* ------------------------------------------------------------------- dental */

/**
 * Dental repeat visit: the assigned doctor continues (shown, never chosen
 * here), then the payment — Full, Partial or Pay Later as this member's
 * rights allow, in the same payment panel New Case uses. Check in completes
 * from the footer.
 */
export function RepeatDentalStep({
  config,
  doctor,
  caseTitle,
  module,
  paymentState,
  onPayment,
  disabled = false,
}: {
  config: IntakeConfig | null;
  doctor: PractitionerOption | null;
  caseTitle: string | null;
  module: string;
  paymentState: PaymentState;
  onPayment: (patch: Partial<PaymentState>) => void;
  disabled?: boolean;
}) {
  return (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)] md:gap-0 md:divide-x md:divide-slate-200/80">
      <StepSection icon={Stethoscope} tone="blue" title="Today’s visit" caption="With the assigned doctor" className="md:pr-6">
        <div className="space-y-2.5">
          <InfoRow icon={Stethoscope} tone="blue" label={doctor?.name ?? 'No doctor available'} detail={doctor?.shift_label ? `Assigned doctor · ${doctor.shift_label}` : 'Assigned doctor'} />
          {caseTitle && <InfoRow icon={ClipboardList} tone="violet" label={caseTitle} detail="Treatment continuing" />}
        </div>
        <p className="flex items-start gap-2 text-[12px] leading-5 text-cx-text-2">
          <UserCheck className="mt-0.5 h-4 w-4 shrink-0 text-cx-blue-ink" strokeWidth={1.75} aria-hidden="true" />
          The patient joins this doctor’s queue once you check them in.
        </p>
      </StepSection>

      <div className="min-w-0 md:pl-6">
        <BillingPaymentSummary config={config} doctor={doctor} module={module} paymentState={paymentState} onChange={onPayment} disabled={disabled} />
      </div>
    </div>
  );
}
