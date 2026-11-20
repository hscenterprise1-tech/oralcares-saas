import { useState, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import * as Menu from '@radix-ui/react-dropdown-menu';
import { ArrowLeft, CalendarCheck2, CalendarPlus, ClipboardPlus, HeartOff, MoreHorizontal, Pencil, Phone, Printer, Receipt, Stethoscope, Trash2, UserRoundCog, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { ageSex, lastSeen } from '../../format';
import { MaskedMobile } from '../../patientVisuals';
import type { Patient360Action, Patient360Context, Patient360Identity, Patient360State } from '../types';
import { AssignDoctorDialog } from './AssignDoctorDialog';
import { DeletePatientDialog } from './DeletePatientDialog';
import { MedicalHistoryLine } from './MedicalHistory';
import { BTN, BTN_PRIMARY, FOCUS, IconTile, Pill, day, type Tone } from './ui';

const MENU_ITEM = 'flex min-h-10 cursor-pointer items-center gap-2.5 rounded-[10px] px-3 py-2 text-[13.5px] font-medium text-cx-nav-ink outline-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-60 data-[highlighted]:bg-cx-slate-bg';

const STATUS: Record<string, { label: string; tone: Tone }> = {
  active: { label: 'Active', tone: 'mint' },
  inactive: { label: 'Inactive', tone: 'slate' },
  merged: { label: 'Merged', tone: 'violet' },
};

function initials(name: string): string {
  return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
}

function Fact({ icon, tone, label, children }: { icon: LucideIcon; tone: Tone; label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col items-start gap-2 rounded-[16px] bg-white/80 p-3 ring-1 ring-inset ring-white sm:flex-row sm:items-center sm:gap-3">
      <IconTile icon={icon} tone={tone} size="sm" />
      <div className="min-w-0">
        <dt className="text-[11px] font-semibold uppercase tracking-wide text-cx-text-3">{label}</dt>
        <dd className="text-[13.5px] font-semibold leading-5 text-cx-nav-ink [overflow-wrap:anywhere]">{children}</dd>
      </div>
    </div>
  );
}

/**
 * The persistent patient header (spec §3, rebuilt): a soft gradient banner
 * with the patient's identity, the facts the desk asks first, the clinic's
 * modules, the New Case medical-history line, and only the actions the
 * server sent and marked available.
 */
export function PatientHeaderCard({
  patient,
  state,
  context,
  actions,
  modules,
  backHref,
  governance,
  openRequests,
  governanceEndpoints,
  onOpenMedicalHistory,
  practitioners = [],
}: {
  patient: Patient360Identity;
  state: Patient360State;
  context: Patient360Context;
  actions: Patient360Action[];
  modules: ('dental' | 'physio')[];
  backHref: string;
  governance: Record<string, 'direct' | 'request' | null>;
  openRequests: string[];
  governanceEndpoints: { request: string; direct: string } | null;
  /** Opens the New Case medical-history form; absent when this member may not edit it. */
  onOpenMedicalHistory?: () => void;
  /** The clinic's practitioners, for Assign doctor. */
  practitioners?: { id: number; name: string; specialty: string | null }[];
}) {
  const intake = useIntake();
  const [deleting, setDeleting] = useState(false);
  const [assigning, setAssigning] = useState(false);
  // The dialog is built here, so the server's offer is enough (`Patient360SectionPolicy::actions`).
  const transfer = actions.find((action) => action.key === 'transfer' && action.url);
  const merged = patient.status === 'merged';
  const deleteMode = merged || !governanceEndpoints ? null : (governance['patient.delete'] ?? null);
  const deletePending = openRequests.includes(`patient.delete:${patient.id}`);
  const available = (key: string) => actions.find((action) => action.key === key && action.available);
  const checkIn = available('check_in') && intake.available && !merged;
  const consultation = available('continue_consultation') ?? available('start_consultation');
  const checkout = available('checkout');
  const edit = available('edit');
  const print = available('print');
  const status = STATUS[patient.status] ?? STATUS.active;
  const primaryTaken = Boolean(checkIn || consultation?.url);
  const next = state.next_appointment;

  const primary = (
    <>
      {checkIn && (
        <button type="button" onClick={() => intake.open('repeat', { id: patient.id, uhid: patient.uhid, name: patient.name })} className={BTN_PRIMARY}>
          <ClipboardPlus className="h-4 w-4" aria-hidden="true" />
          Check in
        </button>
      )}
      {consultation?.url &&
        (consultation.key === 'continue_consultation' ? (
          <Link href={consultation.url} className={checkIn ? BTN : BTN_PRIMARY}>
            <Stethoscope className="h-4 w-4" aria-hidden="true" />
            Continue consultation
          </Link>
        ) : (
          <button type="button" onClick={() => router.post(consultation.url as string)} className={checkIn ? BTN : BTN_PRIMARY}>
            <Stethoscope className="h-4 w-4" aria-hidden="true" />
            {consultation.label}
          </button>
        ))}
      {checkout?.url && (
        <Link href={checkout.url} className={primaryTaken ? BTN : BTN_PRIMARY}>
          <Receipt className="h-4 w-4" aria-hidden="true" />
          {checkout.label}
        </Link>
      )}
      {edit?.url && (
        <Link href={edit.url} className={BTN}>
          <Pencil className="h-4 w-4" aria-hidden="true" />
          Edit profile
        </Link>
      )}
      {(print?.url || deleteMode || transfer) && (
        <Menu.Root modal={false}>
          <Menu.Trigger className={cn(BTN, 'w-10 px-0')} aria-label="More actions">
            <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
          </Menu.Trigger>
          <Menu.Portal>
            <Menu.Content align="end" sideOffset={6} className="z-50 min-w-[230px] rounded-2xl border border-cx-line bg-white p-2 shadow-xl">
              {print?.url && (
                <Menu.Item onSelect={() => window.open(print.url as string, '_blank', 'noopener')} className={MENU_ITEM}>
                  <Printer className="h-4 w-4 text-cx-text-2" aria-hidden="true" />
                  Print summary
                </Menu.Item>
              )}
              {transfer && !merged && (
                <Menu.Item onSelect={() => setAssigning(true)} className={MENU_ITEM}>
                  <UserRoundCog className="h-4 w-4 text-cx-text-2" aria-hidden="true" />
                  Assign doctor
                </Menu.Item>
              )}
              {deleteMode && (
                <Menu.Item disabled={deletePending} onSelect={() => setDeleting(true)} className={cn(MENU_ITEM, 'text-cx-rose-ink')}>
                  <Trash2 className="h-4 w-4" aria-hidden="true" />
                  <span className="min-w-0">
                    Delete patient
                    {deletePending && <span className="block text-[12px] font-normal text-cx-text-3">Waiting for the owner’s decision</span>}
                    {!deletePending && deleteMode === 'request' && <span className="block text-[12px] font-normal text-cx-text-3">Asks the owner</span>}
                  </span>
                </Menu.Item>
              )}
            </Menu.Content>
          </Menu.Portal>
        </Menu.Root>
      )}
    </>
  );

  return (
    <header>
      <Link href={backHref} className={cn('mb-2 inline-flex h-9 items-center gap-1.5 rounded-full px-2.5 text-[13px] font-semibold text-cx-text-2 transition-colors hover:bg-white/70 hover:text-cx-nav-ink', FOCUS)}>
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Patients
      </Link>

      <section aria-label="Patient" className="relative isolate overflow-clip rounded-[26px] border border-white/80 shadow-[0_2px_6px_rgba(15,23,42,0.05),0_24px_48px_-30px_rgba(40,40,120,0.4)]">
        <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_52%,#EFE9FE_100%)]" aria-hidden="true" />
        <div className="absolute -right-24 -top-24 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(44,198,180,0.22),transparent_70%)]" aria-hidden="true" />
        <div className="absolute -bottom-32 right-40 -z-10 h-72 w-72 rounded-full bg-[radial-gradient(circle,rgba(107,72,222,0.16),transparent_70%)]" aria-hidden="true" />


        <div className="flex flex-col gap-5 px-5 pb-5 pt-5 sm:px-7 lg:flex-row lg:items-start lg:justify-between">
          <div className="flex min-w-0 items-center gap-4">
            <span className="grid h-16 w-16 shrink-0 place-items-center rounded-[20px] bg-white text-[20px] font-semibold text-cx-blue-ink shadow-[0_8px_20px_-12px_rgba(30,50,110,0.5)] ring-4 ring-white/60 sm:h-[72px] sm:w-[72px] sm:text-[22px]" aria-hidden="true">
              {initials(patient.name)}
            </span>
            <div className="min-w-0">
              <h1 className="text-[22px] font-semibold leading-7 tracking-[-0.02em] text-[#1D2E4A] [overflow-wrap:anywhere] sm:text-[26px] sm:leading-8">{patient.name}</h1>
              <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                {patient.uhid && <span className="rounded-full bg-white px-2.5 py-0.5 font-mono text-[12px] font-semibold text-cx-blue-ink shadow-[inset_0_0_0_1px_rgba(43,111,216,0.18)]">{patient.uhid}</span>}
                {ageSex(patient.age, patient.gender) && <Pill tone="slate" className="bg-white/80">{ageSex(patient.age, patient.gender)}</Pill>}
                <Pill tone={status.tone}>{status.label}</Pill>
              </div>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2 sm:justify-end [&>a]:min-w-0 [&>a]:flex-1 [&>button:not([aria-label='More actions'])]:flex-1 sm:[&>a]:flex-none sm:[&>button]:flex-none">{primary}</div>
        </div>

        <dl className="grid grid-cols-2 gap-2 px-4 pb-4 sm:gap-2.5 sm:px-7 sm:pb-5 xl:grid-cols-4">
          <Fact icon={Phone} tone="blue" label="Mobile">
            <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
          </Fact>
          <Fact icon={CalendarPlus} tone="mint" label="Registered">
            {day(patient.registered_on) ?? '—'}
            {patient.registered_branch && <span className="block text-[11.5px] font-medium text-cx-text-3">{patient.registered_branch}</span>}
          </Fact>
          <Fact icon={Stethoscope} tone="teal" label="Assigned doctor">
            {patient.practitioner ? (/^dr\.?\s/i.test(patient.practitioner) ? patient.practitioner : `Dr. ${patient.practitioner}`) : 'Unassigned'}
          </Fact>
          <Fact icon={CalendarCheck2} tone="violet" label={next ? 'Next appointment' : 'Last visit'}>
            {next ? `${next.is_today ? 'Today' : next.date_label}, ${next.time_label}` : (lastSeen(patient.last_visit_on) ?? 'No visits yet')}
            {next?.practitioner && <span className="block text-[11.5px] font-medium text-cx-text-3">{next.practitioner}</span>}
          </Fact>
        </dl>

        {context.clinical && (
          <div className="border-t border-white/70 bg-white/55 px-5 py-2.5 sm:px-7">
            <MedicalHistoryLine context={context} onOpen={onOpenMedicalHistory} />
          </div>
        )}
      </section>

      {assigning && transfer?.url && <AssignDoctorDialog url={transfer.url} practitioners={practitioners} current={patient.practitioner} onClose={() => setAssigning(false)} />}
      {deleteMode && governanceEndpoints && deleting &&<DeletePatientDialog onClose={() => setDeleting(false)} mode={deleteMode} patient={{ id: patient.id, name: patient.name }} endpoints={governanceEndpoints} />}
    </header>
  );
}
