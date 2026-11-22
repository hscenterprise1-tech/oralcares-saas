import { type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarClock, CalendarPlus, ClipboardPlus, Mail, Pencil, Phone, Printer, Receipt, Stethoscope, UserRoundCog } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS_RING, TONE, dsButtonClasses } from '@/clinic/ds';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { ageSex, lastSeen, shortDate } from '../format';
import { LifecycleBadge, MaskedMobile, PatientAvatar } from '../patientVisuals';
import { withDoctorTitle } from '@/clinic/ui/visuals';
import { SafetyStrip } from './SafetyStrip';
import type { ConditionEditor, Patient360Action, SafetyEditor, Patient360Context, Patient360Identity } from './types';

/** The fact tiles' colour families, matching Owner Dashboard canonical chips. */
const FACT_STYLES = {
  blue: {
    card: 'border border-cx-line/80 bg-[#F8FAFD] rounded-xl p-3 shadow-none',
    iconBg: 'bg-gradient-to-br from-[#1E78C8] to-[#125898] text-white shadow-sm',
    label: 'text-[#1769AA]',
  },
  teal: {
    card: 'border border-cx-line/80 bg-[#F8FAFD] rounded-xl p-3 shadow-none',
    iconBg: 'bg-gradient-to-br from-[#179B9B] to-[#0E6C79] text-white shadow-sm',
    label: 'text-[#0D9488]',
  },
  violet: {
    card: 'border border-cx-line/80 bg-[#F8FAFD] rounded-xl p-3 shadow-none',
    iconBg: 'bg-gradient-to-br from-[#3B5BDB] to-[#2B44A8] text-white shadow-sm',
    label: 'text-[#6366F1]',
  },
  green: {
    card: 'border border-cx-line/80 bg-[#F8FAFD] rounded-xl p-3 shadow-none',
    iconBg: 'bg-gradient-to-br from-[#16A34A] to-[#15803D] text-white shadow-sm',
    label: 'text-[#16A34A]',
  },
} as const;


/** Buttons matching Owner Dashboard: solid primary blue, hairline secondary. */
const PRIMARY =
  'inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[#1769AA] px-4 text-[13.5px] font-semibold text-white shadow-[0_1px_2px_rgba(23,59,96,0.18)] hover:bg-[#135A93] active:scale-[0.98] transition-all';
const SECONDARY =
  'inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-[#D5DDE7] bg-white px-3.5 text-[13.5px] font-semibold text-[#173B60] hover:border-[#1769AA]/50 hover:bg-[#F5F8FC] active:scale-[0.98] transition-all';

/**
 * Patient 360's header, in the clinical-workspace layout: the way back and
 * the patient's actions on one line; the patient's identity beside a large
 * avatar; the facts the desk asks first as icon tiles; and the safety
 * information — allergies and alerts for everyone, notes for clinicians — in
 * their own tinted panels. Only actions the server marks available, and only
 * data it sent for this member, are shown.
 */
export function PatientHeader({
  patient,
  context,
  actions,
  practitioners,
  backHref,
  governance,
  openRequests,
  governanceEndpoints,
  conditionEditor,
  safetyEditor,
}: {
  patient: Patient360Identity;
  context: Patient360Context;
  actions: Patient360Action[];
  practitioners: { id: number; name: string; specialty: string | null }[];
  backHref: string;
  governance: Record<string, 'direct' | 'request' | null>;
  openRequests: string[];
  governanceEndpoints: { request: string; direct: string } | null;
  conditionEditor: ConditionEditor | null;
  safetyEditor: SafetyEditor | null;
}) {
  const intake = useIntake();
  const available = (key: string) => actions.find((action) => action.key === key && action.available);
  const identity = [
    ageSex(patient.age, patient.gender),
    patient.date_of_birth ? `DOB ${shortDate(patient.date_of_birth)}` : null,
    patient.blood_group ? `Blood group ${patient.blood_group}` : null,
  ].filter(Boolean) as string[];
  // Check-in is the desk's own popup (Repeat Case); it is offered where that popup is.
  const checkIn = available('check_in') && intake.available && patient.status !== 'merged';
  // Into the consultation: the open one, or a new one for a clinician who may start it.
  const consultation = available('continue_consultation') ?? available('start_consultation');
  const edit = available('edit');
  const print = available('print');
  // A signed consultation still to bill: its checkout (the server offers it only to members who may bill).
  const checkout = available('checkout');

  const buttons = (
    <>
      {checkIn && (
        <button
          type="button"
          onClick={() =>
            intake.open('repeat', {
              id: patient.id,
              uhid: patient.uhid,
              name: patient.name,
            })
          }
          className={PRIMARY}
        >
          <ClipboardPlus className="h-[18px] w-[18px]" aria-hidden="true" />
          Check in
        </button>
      )}
      {consultation?.url &&
        (consultation.key === 'continue_consultation' ? (
          <Link href={consultation.url} className={checkIn ? SECONDARY : PRIMARY}>
            <Stethoscope className="h-4 w-4" aria-hidden="true" />
            Continue consultation
          </Link>
        ) : (
          <button type="button" onClick={() => router.post(consultation.url as string)} className={checkIn ? SECONDARY : PRIMARY}>
            <Stethoscope className="h-4 w-4" aria-hidden="true" />
            {consultation.label}
          </button>
        ))}
      {checkout?.url && (
        <Link href={checkout.url} className={checkIn || consultation?.url ? SECONDARY : PRIMARY}>
          <Receipt className="h-4 w-4" aria-hidden="true" />
          {checkout.label}
        </Link>
      )}
      {edit?.url && (
        <Link href={edit.url} className={SECONDARY}>
          <Pencil className="h-4 w-4" aria-hidden="true" />
          Edit Profile
        </Link>
      )}
      {print?.url && (
        <a href={print.url} target="_blank" rel="noopener" className={SECONDARY}>
          <Printer className="h-4 w-4" aria-hidden="true" />
          Print
        </a>
      )}
    </>
  );
  const hasButtons = Boolean(checkIn || consultation?.url || checkout?.url || edit?.url || print?.url);

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link
          href={backHref}
          className="inline-flex h-9 items-center gap-1.5 rounded-xl px-2.5 text-[13.5px] font-bold text-slate-600 hover:bg-slate-100/80 hover:text-slate-900 transition-colors"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          Back to Patients
        </Link>
        {hasButtons && <div className="hidden flex-wrap items-center justify-end gap-2 md:flex">{buttons}</div>}
      </div>

      {/* Hero Workspace Card */}
      <section
        aria-label="Patient"
        className="relative mt-3 overflow-hidden rounded-2xl border border-cx-line/80 bg-white shadow-cx-card"
      >
        <div className="relative flex items-center gap-4 px-5 py-5 sm:gap-6 sm:px-6">
          <div className="shrink-0 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 p-0.5 shadow-md shadow-blue-500/25">
            <div className="rounded-[14px] bg-white p-0.5">
              <PatientAvatar name={patient.name} size="portrait" quiet={patient.status === 'merged'} />
            </div>
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
              <h1 className="min-w-0 text-[24px] font-black leading-7 tracking-tight text-slate-900 [overflow-wrap:anywhere] sm:text-[28px] sm:leading-8">
                {patient.name}
              </h1>
              <LifecycleBadge status={patient.status} />
            </div>
            {(patient.uhid || identity.length > 0) && (
              <div className="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                {patient.uhid && (
                  <span className="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50/80 px-2.5 py-0.5 font-mono text-[12px] font-bold text-blue-700">
                    <span className="font-semibold text-blue-500">UHID</span>
                    <span className="tracking-[0.02em]">{patient.uhid}</span>
                  </span>
                )}
                {identity.length > 0 && (
                  <p className="flex flex-wrap items-center gap-x-2 text-[13.5px] font-medium leading-5 text-slate-600">
                    {identity.map((fact, index) => (
                      <span key={fact} className="whitespace-nowrap">
                        {index > 0 && (
                          <span aria-hidden="true" className="mr-2 text-slate-300">
                            ·
                          </span>
                        )}
                        {fact}
                      </span>
                    ))}
                  </p>
                )}
              </div>
            )}
          </div>
        </div>

        {/* Phones: the actions as one row of equal buttons */}
        {hasButtons && <div className="relative flex gap-2 px-4 pb-4 md:hidden [&>*]:min-w-0 [&>*]:flex-1 [&>*]:px-2 max-[420px]:[&_svg]:hidden">{buttons}</div>}

        {/* Facts on pastel cards: 5 columns from desktop, 2 on phones */}
        <dl
          className={cn(
            'relative mx-4 mb-4 grid grid-cols-2 gap-3 sm:mx-6 sm:mb-5 xl:gap-3.5',
            patient.email ? 'sm:grid-cols-3 xl:grid-cols-5' : 'sm:grid-cols-4',
          )}
        >
          <Fact tile="blue" icon={Phone} label="Mobile">
            <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
          </Fact>
          {patient.email && (
            <Fact tile="blue" icon={Mail} label="Email">
              <span className="block text-[13.5px] font-medium leading-5 text-slate-800 [overflow-wrap:anywhere]">{breakable(patient.email)}</span>
            </Fact>
          )}
          <Fact tile="teal" icon={Stethoscope} label="Assigned Doctor">
            <Value empty={!patient.practitioner}>{patient.practitioner ? withDoctorTitle(patient.practitioner) : 'Unassigned'}</Value>
          </Fact>
          <Fact tile="green" icon={CalendarPlus} label="Registered">
            <Value empty={!patient.registered_on}>{shortDate(patient.registered_on) ?? 'Not recorded'}</Value>
            {patient.registered_branch && <Sub>{patient.registered_branch}</Sub>}
          </Fact>
          <Fact tile="violet" icon={CalendarClock} label="Last Visit">
            <Value empty={!patient.last_visit_on}>{lastSeen(patient.last_visit_on) ?? 'No visits yet'}</Value>
            {patient.branch && <Sub>{patient.branch}</Sub>}
          </Fact>
        </dl>

        {/* Safety Strip: allergies, medical conditions, and clinical notes */}
        <div className="relative border-t border-slate-100 bg-slate-50/50 px-4 py-4 sm:px-6">
          <SafetyStrip context={context} editor={conditionEditor} safetyEditor={safetyEditor} readOnly={patient.status === 'merged'} />
        </div>
      </section>
    </div>
  );
}

function Fact({ tile, icon: Icon, label, children }: { tile: keyof typeof FACT_STYLES; icon: typeof Phone; label: string; children: ReactNode }) {
  const styles = FACT_STYLES[tile];

  return (
    <div className={cn('flex min-w-0 items-start gap-2.5 transition-all', styles.card)}>
      {/* Icon badge */}
      <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-[10px] shadow-sm max-[400px]:hidden sm:h-9 sm:w-9', styles.iconBg)} aria-hidden="true">
        <Icon className="h-4 w-4" strokeWidth={1.75} />
      </span>
      <div className="min-w-0 pt-0.5">
        <dt className={cn('text-[11px] font-semibold uppercase tracking-wider', styles.label)}>{label}</dt>
        <dd className="mt-0.5 min-w-0">{children}</dd>
      </div>
    </div>
  );
}

/** A long email or address breaks after its separators before it breaks mid-word. */
function breakable(text: string): ReactNode[] {
  return text.split(/(?<=[@._-])/).flatMap((part, index) => (index === 0 ? [part] : [<wbr key={index} />, part]));
}

function Value({ empty, children }: { empty?: boolean; children: ReactNode }) {
  return <span className={cn('block text-[13.5px] font-bold leading-5 [overflow-wrap:anywhere]', empty ? 'text-slate-400 font-normal' : 'text-slate-900')}>{children}</span>;
}

function Sub({ children }: { children: ReactNode }) {
  return <span className="mt-0.5 block text-[11.5px] font-medium leading-4 text-slate-500 [overflow-wrap:anywhere]">{children}</span>;
}

