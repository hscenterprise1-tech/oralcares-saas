import { CalendarClock, MapPin, Phone, RefreshCw, Search, Stethoscope, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { humanize } from '@/clinic';
import { FormGroup, VisitStep } from './NewCaseForm';
import { PatientSearchStep } from './PatientSearchStep';
import { PhysioCareAtCheckIn } from './PhysioCareAtCheckIn';
import { SafetyFlags } from './SafetyFlags';
import { formatDay, type IntakeConfig, type LookupPatient, type VisitForm } from './types';
import type { VisitErrors } from './VisitFields';

/*
 * Repeat Case, on the same single white surface as New Case: find the
 * patient (or, once chosen, who they are) beside today's visit. Search-first:
 * nothing is chosen until reception picks a row; the visit controls are New
 * Case's own (`VisitStep`), so a doctor booked or assigned is kept when
 * valid. The fee is said once, in the footer, as in New Case. The state,
 * rules and request are the dialog's (`ReceptionIntakeDialog`).
 */
export function RepeatCaseForm({
  config,
  patient,
  query,
  onQueryChange,
  onSelect,
  onChangePatient,
  busy,
  visit,
  onVisit,
  visitErrors,
  noun,
  specialtyLabel,
  onRegisterNew,
}: {
  config: IntakeConfig;
  patient: LookupPatient | null;
  query: string;
  onQueryChange: (query: string) => void;
  onSelect: (patient: LookupPatient) => void;
  onChangePatient: () => void;
  busy: boolean;
  visit: VisitForm;
  onVisit: (patch: Partial<VisitForm>) => void;
  visitErrors: VisitErrors;
  noun: string;
  specialtyLabel: (key: string) => string;
  /** No match: register them with New Case instead (absent when this member may not register). */
  onRegisterNew: (() => void) | null;
}) {
  return (
    // Patient beside today's visit on a laptop or wider; stacked, with a light separator, on a tablet or phone.
    <div className="grid grid-cols-1 lg:grid-cols-[minmax(0,1.12fr)_minmax(0,1fr)] lg:gap-x-7">
      <div className="min-w-0">
        <FormGroup id="rc-group-patient" title={patient ? 'Patient' : 'Find the patient'} icon={patient ? UserRound : Search} first>
          {patient ? (
            <SelectedPatient patient={patient} specialtyLabel={specialtyLabel} onChange={onChangePatient} disabled={busy} physio={config.specialties.some((specialty) => specialty.key === 'physiotherapy')} />
          ) : (
            <PatientSearchStep config={config} query={query} onQueryChange={onQueryChange} onSelect={onSelect} onRegisterNew={onRegisterNew} />
          )}
        </FormGroup>
      </div>
      <div className="relative min-w-0 max-lg:mt-3.5 max-lg:pt-3 max-lg:before:absolute max-lg:before:inset-x-0 max-lg:before:top-0 max-lg:before:h-px max-lg:before:bg-gradient-to-r max-lg:before:from-[#CDEBE5] max-lg:before:via-[#DCE8F7] max-lg:before:to-transparent lg:border-l lg:border-[#EDF1F6] lg:pl-7">
        <FormGroup id="rc-group-visit" title="Today’s visit" icon={Stethoscope} first>
          <VisitStep config={config} visit={visit} onVisit={onVisit} errors={visitErrors} noun={noun} idPrefix="rc-visit" paired="below-lg" />
        </FormGroup>
      </div>
    </div>
  );
}

/**
 * Who was chosen, on a soft tint that belongs to the sheet (not a card of
 * its own): name and number, mobile in full (to confirm identity), age and
 * gender, last visit and branch, the address on file, safety flags, and —
 * where the clinic practises it — their physiotherapy at a glance. Today's
 * booking and an existing place in the queue are said right here.
 */
function SelectedPatient({ patient, specialtyLabel, onChange, disabled, physio }: { patient: LookupPatient; specialtyLabel: (key: string) => string; onChange: () => void; disabled: boolean; physio: boolean }) {
  const initials = patient.name.split(/\s+/).map((part) => part[0]).slice(0, 2).join('').toUpperCase();
  const facts = [patient.uhid, [patient.age !== null ? `${patient.age} yrs` : null, patient.gender ? humanize(patient.gender) : null].filter(Boolean).join(' · ')].filter(Boolean);

  return (
    <div className="space-y-3 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-1 motion-safe:duration-200">
      <div className="rounded-2xl bg-gradient-to-br from-[#F2FAF8] to-[#F3F7FD] p-3.5 ring-1 ring-[#DCEFEA]">
        <div className="flex items-start gap-3">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white text-[14px] font-bold text-[#0F6B5F] shadow-[0_4px_12px_-8px_rgba(15,107,95,0.7)]" aria-hidden="true">
            {initials}
          </span>
          <div className="min-w-0 flex-1">
            <p className="break-words text-[16px] font-bold leading-6 tracking-tight text-[#0F2A44]">{patient.name}</p>
            <p className="text-[12.5px] text-[#475569]">{facts.join(' · ')}</p>
          </div>
          <button
            type="button"
            onClick={onChange}
            disabled={disabled}
            className="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-[12.5px] font-semibold text-[#1769AA] motion-safe:transition-colors hover:bg-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#14917F]/40 disabled:opacity-50 [@media(pointer:coarse)]:h-11"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
            Change
          </button>
        </div>
        <dl className="mt-3 grid grid-cols-1 gap-x-4 gap-y-1.5 text-[12.5px] sm:grid-cols-2">
          {patient.phone && (
            <div className="flex min-w-0 items-center gap-1.5 text-[#334155]">
              <Phone className="h-3.5 w-3.5 shrink-0 text-[#94A3B8]" aria-hidden="true" />
              <dt className="sr-only">Mobile</dt>
              <dd className="truncate font-semibold tabular-nums">{patient.phone}</dd>
            </div>
          )}
          <div className="flex min-w-0 items-start gap-1.5 text-[#334155]">
            <CalendarClock className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#94A3B8]" aria-hidden="true" />
            <dt className="sr-only">Last visit</dt>
            <dd className="min-w-0 break-words">{patient.last_seen ? `Last visit ${formatDay(patient.last_seen.at)} · ${patient.last_seen.branch}` : 'No visits yet'}</dd>
          </div>
          {patient.address && (
            <div className="flex min-w-0 items-start gap-1.5 text-[#475569] sm:col-span-2">
              <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-[#94A3B8]" aria-hidden="true" />
              <dt className="sr-only">Address</dt>
              <dd className="break-words">{patient.address}</dd>
            </div>
          )}
        </dl>
        {patient.specialties.length > 0 && <p className="mt-2 text-[12px] font-semibold text-[#0F6B5F]">{patient.specialties.map(specialtyLabel).join(', ')}</p>}
        {patient.safety_flags.length > 0 && <SafetyFlags flags={patient.safety_flags} compact className="mt-2.5" />}
      </div>

      {patient.in_queue && (
        <p role="status" className="rounded-xl bg-[#FFF8EB] px-3.5 py-2.5 text-[12.5px] leading-5 text-[#7A4B06] ring-1 ring-[#F5DFB3]">
          <span className="font-semibold">Already in today’s queue</span>
          {patient.in_queue.case_short ? ` · case ${patient.in_queue.case_short}` : ''} · {humanize(patient.in_queue.status).toLowerCase()}. They do not need checking in again.
        </p>
      )}
      {patient.appointment && !patient.in_queue && (
        <p role="status" className={cn('flex items-center gap-2 rounded-xl bg-[#F3F8FD] px-3.5 py-2.5 text-[12.5px] leading-5 text-[#0B4A7D] ring-1 ring-[#D7E6F5]')}>
          <CalendarClock className="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>
            <span className="font-semibold">Booked for {patient.appointment.time.slice(11, 16)} today</span> — checking in links the appointment{patient.appointment.doctor ? ` with ${patient.appointment.doctor.name}` : ''}.
          </span>
        </p>
      )}
      {physio && <PhysioCareAtCheckIn patientId={patient.id} />}
    </div>
  );
}
