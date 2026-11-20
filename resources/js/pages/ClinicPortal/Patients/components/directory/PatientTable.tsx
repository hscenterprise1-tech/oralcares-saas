import type { MouseEvent, ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, ChevronRight, FileText, Phone, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { FOCUS_RING, Shimmer, type DsTone } from '@/clinic/ds';
import type { DeskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import { LifecycleBadge, MaskedMobile, PatientAvatar } from '../patientVisuals';
import type { DirectoryPatient } from '../types';

/**
 * The record marker that matters at the desk (`patients.status`): a
 * duplicate merged into another record. Everything else is simply a patient.
 */
function recordMarker(status: string): { label: string; tone: DsTone } | null {
  switch (status) {
    case 'merged':
      return { label: 'Merged', tone: 'violet' };
    default:
      return null;
  }
}

/** New or Repeat: the case of the latest attended visit (`patient_visits.registration_type`). */
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

/** Clicks on a control inside a row act on their own; anywhere else opens the patient. */
function fromControl(event: MouseEvent): boolean {
  return (event.target as HTMLElement).closest('a,button,[role="button"]') !== null;
}

/**
 * PatientTable: Complete rebuild adhering to the Clinic Owner Dashboard visual language.
 *
 * Desktop (>= 1024px): A modern, clean data grid with soft pastel accents and prominent avatar.
 * Tablet (768px - 1023px): Tablet cards with prominent avatar and 4-cell facts grid.
 * Mobile (< 768px): Purpose-built mobile cards with 2x2 facts grid, touch targets, and zero overflow.
 */
export function PatientTable({
  patients,
  mode,
  actions,
  busy,
}: {
  patients: DirectoryPatient[];
  mode: DeskMode;
  actions: RowActions;
  busy: boolean;
}) {
  return (
    <div className={cn(busy && 'pointer-events-none opacity-60 motion-safe:transition-opacity')}>
      {/* Desktop (>= lg: 1024px, 1440px): Deliberate fixed-column layout with Owner Dashboard polish */}
      <table className="hidden w-full table-fixed border-separate border-spacing-0 text-left lg:table">
        <caption className="sr-only">Patients Directory</caption>
        <thead>
          <tr className="border-b border-[#E6EBF1] bg-[#F8FAFD]/90 text-[12px] font-semibold text-[#5F6B7E]">
            <th scope="col" className="w-[35%] border-b border-[#E6EBF1] py-3.5 pl-5 pr-3 whitespace-nowrap">
              <span className="flex items-center gap-1.5">
                <span className="h-1.5 w-1.5 rounded-full bg-[#1769AA]" aria-hidden="true" />
                Patient
              </span>
            </th>
            <th scope="col" className="w-[17%] border-b border-[#E6EBF1] px-3 py-3.5 whitespace-nowrap">
              <span className="flex items-center gap-1.5">
                <span className="h-1.5 w-1.5 rounded-full bg-[#2B6FD8]" aria-hidden="true" />
                Mobile
              </span>
            </th>
            <th scope="col" className="w-[18%] border-b border-[#E6EBF1] px-3 py-3.5 whitespace-nowrap">
              <span className="flex items-center gap-1.5">
                <span className="h-1.5 w-1.5 rounded-full bg-[#6B48DE]" aria-hidden="true" />
                Active Case
              </span>
            </th>
            <th scope="col" className="w-[16%] border-b border-[#E6EBF1] px-3 py-3.5 whitespace-nowrap">
              <span className="flex items-center gap-1.5">
                <span className="h-1.5 w-1.5 rounded-full bg-[#0E9F87]" aria-hidden="true" />
                {mode.doctor}
              </span>
            </th>
            <th scope="col" className="w-[11%] border-b border-[#E6EBF1] px-3 py-3.5 whitespace-nowrap">
              <span className="flex items-center gap-1.5">
                <span className="h-1.5 w-1.5 rounded-full bg-[#10B981]" aria-hidden="true" />
                Treatment
              </span>
            </th>
            <th scope="col" className="w-[3%] border-b border-[#E6EBF1] py-3.5 pr-5 pl-1 text-right whitespace-nowrap">
              <span className="sr-only">Open</span>
            </th>
          </tr>
        </thead>
        <tbody className="divide-y divide-[#F1F5F9] bg-white">
          {patients.map((patient) => {
            const href = actions.profileHref(patient);

            return (
              <tr
                key={patient.id}
                onClick={(event) => href && !fromControl(event) && router.visit(href)}
                className={cn(
                  'group align-middle transition-all duration-150',
                  href &&
                    'cursor-pointer hover:bg-gradient-to-r hover:from-[#F4F8FE] hover:via-[#FAF9FF] hover:to-white hover:shadow-[0_2px_8px_rgba(23,59,96,0.04)]',
                )}
              >
                <td className="py-3.5 pl-5 pr-3 align-middle">
                  <PatientIdentity patient={patient} href={href} />
                </td>
                <td className="px-3 py-3.5 align-middle text-[13px] font-medium text-[#173B60] whitespace-nowrap">
                  <div className="flex items-center gap-2">
                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-blue-50/80 text-blue-600 ring-1 ring-inset ring-blue-200/60 shadow-2xs">
                      <Phone className="h-3.5 w-3.5" />
                    </span>
                    <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
                  </div>
                </td>
                <td className="px-3 py-3.5 align-middle">
                  <CaseType patient={patient} />
                </td>
                <td className="px-3 py-3.5 align-middle">
                  <Clinician patient={patient} />
                </td>
                <td className="px-3 py-3.5 align-middle">
                  <Progress patient={patient} />
                </td>
                <td className="py-3.5 pr-5 pl-1 align-middle text-right">
                  <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-[#F8FAFD] text-[#8C9BAE] transition-all group-hover:bg-[#1769AA] group-hover:text-white group-hover:translate-x-0.5 shadow-2xs">
                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                  </span>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {/* iPad Portrait (768px: sm to lg): Deliberate tablet-specific patient card */}
      <ul className="hidden divide-y divide-[#F1F5F9] sm:block lg:hidden">
        {patients.map((patient) => {
          const href = actions.profileHref(patient);

          return (
            <li
              key={patient.id}
              onClick={(event) => href && !fromControl(event) && router.visit(href)}
              className={cn(
                'group p-4 transition-all duration-150',
                href &&
                  'cursor-pointer hover:bg-gradient-to-r hover:from-[#F4F8FE] hover:via-[#FAF9FF] hover:to-white hover:shadow-[0_2px_8px_rgba(23,59,96,0.04)]',
              )}
            >
              <div className="flex items-start justify-between gap-3">
                <PatientIdentity patient={patient} href={href} />
                <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-[#F8FAFD] text-[#8C9BAE] transition-all group-hover:bg-[#1769AA] group-hover:text-white group-hover:translate-x-0.5 shadow-2xs">
                  <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </span>
              </div>
              <dl className="mt-3 grid grid-cols-4 gap-2.5 rounded-xl border border-[#E6EBF1] bg-gradient-to-br from-[#F8FAFD] to-[#F5F8FF] p-3 shadow-2xs">
                <Fact label="Mobile">
                  <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
                </Fact>
                <Fact label="Active Case">
                  <CaseType patient={patient} />
                </Fact>
                <Fact label={mode.doctor}>
                  <Clinician patient={patient} />
                </Fact>
                <Fact label="Treatment">
                  <Progress patient={patient} />
                </Fact>
              </dl>
            </li>
          );
        })}
      </ul>

      {/* Mobile (390px, 360px: < sm): Purpose-built mobile patient cards */}
      <ul className="divide-y divide-[#F1F5F9] sm:hidden">
        {patients.map((patient) => {
          const href = actions.profileHref(patient);

          return (
            <li
              key={patient.id}
              onClick={(event) => href && !fromControl(event) && router.visit(href)}
              className={cn(
                'group p-3.5 transition-all duration-150 active:scale-[0.99]',
                href && 'cursor-pointer hover:bg-blue-50/25',
              )}
            >
              <div className="flex items-start justify-between gap-2">
                <PatientIdentity patient={patient} href={href} />
                <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[#F8FAFD] text-[#8C9BAE] transition-all group-hover:bg-[#1769AA] group-hover:text-white shadow-2xs">
                  <ChevronRight className="h-4 w-4" aria-hidden="true" />
                </span>
              </div>
              <dl className="mt-2.5 grid grid-cols-2 gap-x-3 gap-y-2 rounded-xl border border-[#E6EBF1] bg-gradient-to-br from-[#F8FAFD] to-[#F5F8FF] p-2.5 shadow-2xs">
                <Fact label="Mobile">
                  <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
                </Fact>
                <Fact label="Active Case">
                  <CaseType patient={patient} />
                </Fact>
                <Fact label={mode.doctor}>
                  <Clinician patient={patient} />
                </Fact>
                <Fact label="Treatment">
                  <Progress patient={patient} />
                </Fact>
              </dl>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

function PatientIdentity({ patient, href }: { patient: DirectoryPatient; href: string | null }) {
  const marker = recordMarker(patient.status);
  const demographics = [
    patient.age !== null && patient.age !== undefined ? `${patient.age}y` : null,
    patient.gender ? patient.gender.charAt(0).toUpperCase() + patient.gender.slice(1).toLowerCase() : null,
  ]
    .filter(Boolean)
    .join(', ');

  return (
    <div className="flex min-w-0 items-center gap-3 sm:gap-3.5">
      {/* Prominent Avatar: 44px (h-11 w-11) preserved across desktop, tablet, and mobile */}
      <PatientAvatar name={patient.name} quiet={patient.status === 'merged'} size="md" />

      <div className="min-w-0 flex-1">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          {href ? (
            <Link
              href={href}
              className={cn(
                'min-w-0 truncate text-[15px] font-bold leading-snug text-[#173B60] transition-colors group-hover:text-[#1769AA]',
                FOCUS_RING,
              )}
            >
              {patient.name}
            </Link>
          ) : (
            <span className="min-w-0 truncate text-[15px] font-bold leading-snug text-[#173B60]">{patient.name}</span>
          )}
          {marker && (
            <span
              className={cn(
                'shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-wider',
                marker.tone === 'slate'
                  ? 'border border-slate-200 bg-slate-100 text-slate-700'
                  : 'border border-purple-200 bg-purple-50 text-purple-700',
              )}
            >
              {marker.label}
            </span>
          )}
        </div>
        <div className="mt-1 flex flex-wrap items-center gap-1.5 text-[12px] text-[#5F6B7E]">
          <span className="rounded-md border border-blue-200/80 bg-blue-50/90 px-1.5 py-0.5 font-mono text-[11px] font-bold text-blue-700 shadow-2xs">
            {patient.uhid ?? 'No UHID'}
          </span>
          {demographics ? <span className="font-medium text-[#5F6B7E]">• {demographics}</span> : null}
        </div>
      </div>
    </div>
  );
}

function CaseType({ patient }: { patient: DirectoryPatient }) {
  const value = patient.case_type ? CASE[patient.case_type] : null;

  return (
    <div className="min-w-0 space-y-1">
      {patient.active_case ? (
        // The case by what it is for; its internal case number is not a patient-facing identifier.
        <div className="min-w-0">
          <p className="truncate text-[12px] font-semibold text-[#173B60]" title={patient.active_case.title}>
            {patient.active_case.title}
          </p>
        </div>
      ) : null}
      <div className="flex items-center gap-1.5">
        {value ? (
          <span
            className={cn(
              'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold shadow-2xs',
              value.tone === 'blue'
                ? 'border border-blue-200/90 bg-blue-50/90 text-blue-700'
                : 'border border-purple-200/90 bg-purple-50/90 text-purple-700',
            )}
          >
            <span
              className={cn(
                'h-1.5 w-1.5 rounded-full',
                value.tone === 'blue' ? 'bg-blue-600' : 'bg-purple-600',
              )}
              aria-hidden="true"
            />
            {value.label}
          </span>
        ) : !patient.active_case ? (
          <Quiet>—</Quiet>
        ) : null}
      </div>
    </div>
  );
}

function Progress({ patient }: { patient: DirectoryPatient }) {
  const value = patient.treatment_progress ? PROGRESS[patient.treatment_progress] : null;

  if (!value) return <Quiet>—</Quiet>;

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold shadow-2xs',
        value.tone === 'green'
          ? 'border border-emerald-200/90 bg-emerald-50/90 text-emerald-700'
          : 'border border-amber-200/90 bg-amber-50/90 text-amber-800',
      )}
    >
      <span
        className={cn(
          'h-1.5 w-1.5 rounded-full',
          value.tone === 'green' ? 'bg-emerald-600' : 'bg-amber-600',
        )}
        aria-hidden="true"
      />
      {value.label}
    </span>
  );
}

function Clinician({ patient }: { patient: DirectoryPatient }) {
  if (!patient.assigned_doctor) {
    return <Quiet>Not assigned</Quiet>;
  }

  return (
    <div className="flex min-w-0 items-center gap-2">
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-teal-50/80 text-teal-600 ring-1 ring-inset ring-teal-200/60 shadow-2xs">
        <Stethoscope className="h-3.5 w-3.5" />
      </span>
      <div className="min-w-0">
        <p className="truncate text-[13.5px] font-semibold text-[#173B60]">{patient.assigned_doctor.name}</p>
        {!patient.assigned_doctor.at_clinic && (
          <p className="text-[11px] font-medium text-amber-700">No longer at clinic</p>
        )}
      </div>
    </div>
  );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] font-semibold uppercase tracking-wider text-[#8C9BAE]">{label}</dt>
      <dd className="mt-0.5 min-w-0">{children}</dd>
    </div>
  );
}

function Quiet({ children }: { children: ReactNode }) {
  return <span className="whitespace-nowrap text-[12.5px] text-[#8C9BAE]">—</span>;
}

/** The table skeleton while the first page loads. */
export function PatientTableSkeleton() {
  return (
    <ul aria-label="Loading patients" className="divide-y divide-[#F1F5F9]">
      {[0, 1, 2, 3, 4, 5].map((index) => (
        <li key={index} className="flex items-center gap-3.5 px-5 py-4">
          <Shimmer className="h-11 w-11 shrink-0 rounded-[14px]" />
          <span className="flex-1 space-y-1.5">
            <Shimmer className="h-4 w-1/3" />
            <Shimmer className="h-3 w-1/5" />
          </span>
          <Shimmer className="hidden h-3.5 w-24 lg:block" />
          <Shimmer className="hidden h-3.5 w-20 lg:block" />
        </li>
      ))}
    </ul>
  );
}
