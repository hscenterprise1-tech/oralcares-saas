import type { MouseEvent, ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ChevronRight, ClipboardCheck, Pencil, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Avatar, DsButton, FOCUS_RING, Pill, Shimmer, type DsTone } from '@/clinic/ds';
import type { DeskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import { MaskedMobile } from '../patientVisuals';
import type { DirectoryPatient } from '../types';

/**
 * The two record markers that matter at the desk (`patients.status`): a
 * recorded death, and a duplicate merged into another record. Everything
 * else is simply a patient — there is no active / inactive state to show.
 */
function recordMarker(status: string): { label: string; tone: DsTone } | null {
  switch (status) {
    case 'deceased':
      return { label: 'Deceased', tone: 'slate' };
    case 'merged':
      return { label: 'Merged', tone: 'violet' };
    default:
      return null;
  }
}

/** New or Repeat: the case of the latest attended visit (`patient_visits.registration_type`), never a patient status. */
const CASE: Record<'new' | 'repeat', { label: string; tone: DsTone }> = {
  new: { label: 'New', tone: 'blue' },
  repeat: { label: 'Repeat', tone: 'violet' },
};

/** Treatment progress (`patient_treatments`), separate from the visit and the queue. */
const PROGRESS: Record<'ongoing' | 'complete', { label: string; tone: DsTone }> = {
  ongoing: { label: 'Ongoing', tone: 'amber' },
  complete: { label: 'Complete', tone: 'green' },
};

export interface RowActions {
  /** Patient 360 for this patient, when that page is available. */
  profileHref: (patient: DirectoryPatient) => string | null;
  /** The edit page, only for members who may edit and a record that can be edited. */
  editHref: (patient: DirectoryPatient) => string | null;
  /** Check-in through the desk's Repeat Case, only where it may start and the patient can take a new visit. */
  checkIn: ((patient: DirectoryPatient) => void) | null;
}

function canCheckIn(patient: DirectoryPatient): boolean {
  // Deceased and merged records take no new visits; someone already checked in today and not yet seen is already in the queue.
  return patient.status !== 'deceased' && patient.status !== 'merged' && (!patient.today_visit || patient.today_visit.status === 'completed');
}

/** Clicks on a button or link inside a row act on their own; anywhere else on the row opens the patient. */
function fromControl(event: MouseEvent): boolean {
  return (event.target as HTMLElement).closest('a,button,[role="button"]') !== null;
}

/**
 * The directory's patients, kept to what the desk works with: who the
 * patient is (name and patient ID), their mobile, the case (New or Repeat),
 * the assigned doctor or therapist, treatment progress, and the row's
 * actions. From `md` a table where the whole row opens Patient 360 (the
 * doctor and treatment columns join from `lg`); below `md` one card per
 * patient with the same facts. Only what the server sent is shown; a value
 * it does not have is "—".
 */
export function PatientTable({ patients, mode, actions, busy }: { patients: DirectoryPatient[]; mode: DeskMode; actions: RowActions; busy: boolean }) {
  return (
    <div className={cn(busy && 'pointer-events-none opacity-60 motion-safe:transition-opacity')}>
      {/* Tablet and desktop: the table. */}
      <table className="hidden w-full table-fixed border-separate border-spacing-0 text-left md:table">
        <caption className="sr-only">Patients</caption>
        <thead>
          <tr className="text-[12px] font-semibold text-[#64748B]">
            {/* Widths add up per breakpoint; the doctor column takes what is left (from lg). */}
            <th scope="col" className="w-[40%] border-b border-[#E6EBF1] py-2.5 pl-5 pr-3 lg:w-[32%]">
              Patient
            </th>
            <th scope="col" className="w-[22%] border-b border-[#E6EBF1] px-3 py-2.5 lg:w-[16%]">
              Mobile
            </th>
            <th scope="col" className="w-[16%] border-b border-[#E6EBF1] px-3 py-2.5 lg:w-[11%]">
              Case
            </th>
            <th scope="col" className="hidden border-b border-[#E6EBF1] px-3 py-2.5 lg:table-cell">
              {mode.doctor}
            </th>
            <th scope="col" className="hidden w-[12%] border-b border-[#E6EBF1] px-3 py-2.5 lg:table-cell">
              Treatment
            </th>
            <th scope="col" className="w-[22%] border-b border-[#E6EBF1] py-2.5 pl-3 pr-5 text-right lg:w-[13%]">
              <span className="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {patients.map((patient) => {
            const href = actions.profileHref(patient);

            return (
              <tr
                key={patient.id}
                onClick={(event) => href && !fromControl(event) && router.visit(href)}
                className={cn('group align-middle motion-safe:transition-colors', href && 'cursor-pointer hover:bg-[#F8FAFC]')}
              >
                <td className="border-b border-[#EEF2F6] py-3 pl-5 pr-3">
                  <PatientIdentity patient={patient} href={href} />
                </td>
                <td className="border-b border-[#EEF2F6] px-3 py-3">
                  <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
                </td>
                <td className="border-b border-[#EEF2F6] px-3 py-3">
                  <CaseType patient={patient} />
                </td>
                <td className="hidden border-b border-[#EEF2F6] px-3 py-3 lg:table-cell">
                  <Clinician patient={patient} />
                </td>
                <td className="hidden border-b border-[#EEF2F6] px-3 py-3 lg:table-cell">
                  <Progress patient={patient} />
                </td>
                <td className="border-b border-[#EEF2F6] py-3 pl-3 pr-5">
                  <div className="flex items-center justify-end gap-1">
                    <RowButtons patient={patient} actions={actions} compact />
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {/* Phones: a card per patient. */}
      <ul className="divide-y divide-[#EEF2F6] md:hidden">
        {patients.map((patient) => (
          <li key={patient.id} className="px-4 py-4">
            <PatientIdentity patient={patient} href={actions.profileHref(patient)} />
            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5 rounded-xl bg-[#F6F8FB] px-3.5 py-3">
              <Fact label="Mobile">
                <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
              </Fact>
              <Fact label="Case">
                <CaseType patient={patient} />
              </Fact>
              <Fact label={mode.doctor}>
                <Clinician patient={patient} />
              </Fact>
              <Fact label="Treatment">
                <Progress patient={patient} />
              </Fact>
            </dl>
            <div className="mt-3 flex flex-wrap gap-2">
              <RowButtons patient={patient} actions={actions} />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}

function PatientIdentity({ patient, href }: { patient: DirectoryPatient; href: string | null }) {
  const marker = recordMarker(patient.status);

  return (
    <div className="flex min-w-0 items-center gap-3">
      <Avatar name={patient.name} />
      <div className="min-w-0">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          {href ? (
            <Link href={href} className={cn('min-w-0 rounded text-[14px] font-semibold leading-5 text-[#173B60] [overflow-wrap:anywhere] hover:text-[#1769AA] hover:underline', FOCUS_RING)}>
              {patient.name}
            </Link>
          ) : (
            <span className="min-w-0 text-[14px] font-semibold leading-5 text-[#173B60] [overflow-wrap:anywhere]">{patient.name}</span>
          )}
          {marker && <Pill tone={marker.tone}>{marker.label}</Pill>}
          {patient.critical_alerts > 0 && (
            <Pill tone="rose" icon={ShieldAlert}>
              {patient.critical_alerts} {patient.critical_alerts === 1 ? 'alert' : 'alerts'}
            </Pill>
          )}
        </div>
        <p className="mt-0.5 truncate text-[12.5px] tabular-nums text-[#64748B]">{patient.uhid ?? 'No patient ID'}</p>
      </div>
    </div>
  );
}

function CaseType({ patient }: { patient: DirectoryPatient }) {
  const value = patient.case_type ? CASE[patient.case_type] : null;

  return value ? <Pill tone={value.tone}>{value.label}</Pill> : <Quiet>—</Quiet>;
}

function Progress({ patient }: { patient: DirectoryPatient }) {
  const value = patient.treatment_progress ? PROGRESS[patient.treatment_progress] : null;

  return value ? <Pill tone={value.tone}>{value.label}</Pill> : <Quiet>—</Quiet>;
}

function Clinician({ patient }: { patient: DirectoryPatient }) {
  if (!patient.assigned_doctor) {
    return <Quiet>Not assigned</Quiet>;
  }

  return (
    <div className="min-w-0">
      <p className="truncate text-[13px] font-medium text-[#173B60]">{patient.assigned_doctor.name}</p>
      {!patient.assigned_doctor.at_clinic && <p className="text-[12px] text-[#A35A06]">No longer at the clinic</p>}
    </div>
  );
}

function RowButtons({ patient, actions, compact = false }: { patient: DirectoryPatient; actions: RowActions; compact?: boolean }) {
  const href = actions.profileHref(patient);
  const edit = actions.editHref(patient);
  const checkIn = actions.checkIn && canCheckIn(patient) ? actions.checkIn : null;

  if (compact) {
    return (
      <>
        {checkIn && (
          <DsButton size="sm" icon={ClipboardCheck} onClick={() => checkIn(patient)} aria-label={`Check in ${patient.name}`}>
            <span className="hidden xl:inline">Check in</span>
          </DsButton>
        )}
        {edit && (
          <Link href={edit} aria-label={`Edit ${patient.name}`} title="Edit" className={cn('flex h-9 w-9 items-center justify-center rounded-lg text-[#64748B] hover:bg-[#EAF2FA] hover:text-[#1769AA]', FOCUS_RING)}>
            <Pencil className="h-4 w-4" aria-hidden="true" />
          </Link>
        )}
        {href && (
          <Link href={href} aria-label={`Open ${patient.name}'s record`} title="Open record" className={cn('flex h-9 w-9 items-center justify-center rounded-lg text-[#64748B] hover:bg-[#EAF2FA] hover:text-[#1769AA]', FOCUS_RING)}>
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Link>
        )}
      </>
    );
  }

  return (
    <>
      {href && (
        <Link href={href} className={cn('inline-flex h-10 flex-1 items-center justify-center gap-1.5 rounded-[10px] bg-[#1769AA] px-3 text-[13.5px] font-semibold text-white hover:bg-[#135A93]', FOCUS_RING)}>
          Open record
          <ChevronRight className="h-4 w-4" aria-hidden="true" />
        </Link>
      )}
      {checkIn && (
        <DsButton icon={ClipboardCheck} onClick={() => checkIn(patient)} className="flex-1">
          Check in
        </DsButton>
      )}
      {edit && (
        <Link href={edit} className={cn('inline-flex h-10 items-center justify-center gap-1.5 rounded-[10px] border border-[#D5DDE7] bg-white px-3 text-[13.5px] font-semibold text-[#173B60] hover:bg-[#F5F8FC]', FOCUS_RING)}>
          <Pencil className="h-4 w-4" aria-hidden="true" />
          Edit
        </Link>
      )}
    </>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11.5px] font-semibold text-[#64748B]">{label}</dt>
      <dd className="mt-0.5 min-w-0">{children}</dd>
    </div>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return <span className="whitespace-nowrap text-[13px] text-[#64748B]">{children}</span>;
}

/** The table's shape while the first page loads. */
export function PatientTableSkeleton() {
  return (
    <ul aria-label="Loading patients" className="divide-y divide-[#EEF2F6]">
      {[0, 1, 2, 3, 4, 5].map((index) => (
        <li key={index} className="flex items-center gap-3 px-5 py-4">
          <Shimmer className="h-10 w-10 shrink-0 rounded-full" />
          <span className="flex-1 space-y-2">
            <Shimmer className="h-3.5 w-1/3" />
            <Shimmer className="h-3 w-1/5" />
          </span>
          <Shimmer className="hidden h-3.5 w-24 md:block" />
          <Shimmer className="hidden h-3.5 w-20 lg:block" />
        </li>
      ))}
    </ul>
  );
}
