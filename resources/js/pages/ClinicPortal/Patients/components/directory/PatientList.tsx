import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Activity, CalendarCheck, ChevronRight, Phone, ShieldAlert, Stethoscope } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/clinic/ui/Spinner';
import { ageSex, lastSeen } from '../format';
import { LifecycleBadge, MaskedMobile, PatientAvatar } from '../patientVisuals';
import type { DirectoryPatient, DirectoryView } from '../types';

/*
 * The directory's patients, as the approved design shows them (pass 5):
 * from `xl` a row per patient on one grid — identity, mobile, assigned
 * doctor, last activity — and below it a card with the same facts in a
 * tinted band (two columns from `md`). One markup for both: the band's
 * wrapper is `xl:contents`, so its facts become the row's grid cells.
 */

/** Patient, Mobile, Doctor, Last activity, open. */
const ROW_GRID = 'xl:grid xl:grid-cols-[minmax(0,1.75fr)_minmax(150px,1fr)_minmax(150px,1fr)_minmax(156px,1fr)_28px] xl:items-center xl:gap-x-6';

/** One hue per fact, the same in the column headings, the icon tiles and the card labels. */
const HUE = {
  blue: { tile: 'bg-cx-m-blue-bg text-cx-m-blue', dot: 'bg-cx-m-blue' },
  teal: { tile: 'bg-cx-m-teal-bg text-cx-m-teal', dot: 'bg-cx-m-teal' },
  violet: { tile: 'bg-cx-m-purple-bg text-cx-m-purple', dot: 'bg-cx-m-purple' },
} as const;

/** Where today's visit stands, from `patient_visits.visit_status`. */
const VISIT_STATUS: Record<string, string> = {
  checked_in: 'Checked in',
  in_queue: 'Checked in',
  waiting: 'Checked in',
  with_doctor: 'With doctor',
  in_progress: 'With doctor',
  completed: 'Seen today',
};

export function PatientList({
  patients,
  view,
  showBranch,
  profileHref,
}: {
  patients: DirectoryPatient[];
  view: DirectoryView;
  /** Only when the member works across more than one branch. */
  showBranch: boolean;
  profileHref: (id: number) => string | null;
}) {
  const lastColumn = view === 'today' ? 'Today’s visit' : 'Last activity';

  return (
    <div>
      <div className={cn('hidden px-5 pb-2 text-[12px] font-semibold leading-4 text-cx-text-2', ROW_GRID)} aria-hidden="true">
        <span>Patient</span>
        <ColumnHeading hue="blue">Mobile</ColumnHeading>
        <ColumnHeading hue="teal">Doctor</ColumnHeading>
        <ColumnHeading hue="violet">{lastColumn}</ColumnHeading>
        <span />
      </div>
      <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-1 xl:gap-2.5" aria-label="Patients">
        {patients.map((patient) => (
          <PatientRow key={patient.id} patient={patient} view={view} showBranch={showBranch} href={profileHref(patient.id)} />
        ))}
      </ul>
    </div>
  );
}

function ColumnHeading({ hue, children }: { hue: keyof typeof HUE; children: ReactNode }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      <i className={cn('inline-block h-1.5 w-1.5 rounded-[2px]', HUE[hue].dot)} />
      {children}
    </span>
  );
}

function PatientRow({ patient, view, showBranch, href }: { patient: DirectoryPatient; view: DirectoryView; showBranch: boolean; href: string | null }) {
  const facts = [patient.uhid, ageSex(patient.age, patient.gender)].filter(Boolean) as string[];
  const quiet = patient.status === 'merged';

  return (
    <li
      className={cn(
        'relative rounded-[14px] border bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.05)] motion-safe:transition-[border-color,box-shadow,background-color,transform] sm:p-[18px] xl:min-h-[72px] xl:px-5 xl:py-3.5',
        quiet ? 'border-[#E9E4FA] bg-[#FBFAFF]' : 'border-cx-line',
        href &&
          'hover:border-[#A6DCE3] hover:bg-gradient-to-r hover:from-[#F2FBFA] hover:via-[#F5F8FF] hover:to-white hover:shadow-[0_2px_4px_rgba(16,24,40,0.04),0_10px_22px_-14px_rgba(14,116,144,0.38)] focus-within:border-[#A6DCE3] motion-safe:hover:-translate-y-px [&:hover_.row-chevron]:bg-gradient-to-br [&:hover_.row-chevron]:from-[#0E9384] [&:hover_.row-chevron]:to-[#1673C9] [&:hover_.row-chevron]:text-white',
        ROW_GRID,
      )}
    >
      {/* Identity */}
      <div className="flex min-w-0 items-start gap-3.5 xl:items-center">
        <PatientAvatar name={patient.name} quiet={quiet} />
        <div className="min-w-0 flex-1">
          <div className="flex min-w-0 items-start justify-between gap-x-2.5 gap-y-1 xl:flex-wrap xl:items-center xl:justify-start">
            {href ? (
              <Link
                href={href}
                className="min-w-0 text-[15px] font-semibold leading-[22px] tracking-[-0.008em] text-cx-text [overflow-wrap:anywhere] after:absolute after:inset-0 after:rounded-[14px] after:content-[''] focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-cx-cyan focus-visible:after:ring-offset-2"
              >
                {patient.name}
              </Link>
            ) : (
              <span className="min-w-0 text-[15px] font-semibold leading-[22px] tracking-[-0.008em] text-cx-text [overflow-wrap:anywhere]">{patient.name}</span>
            )}
            <span className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
              <LifecycleBadge status={patient.status} />
              {patient.critical_alerts > 0 && (
                <span className="inline-flex h-6 items-center gap-1 rounded-sm bg-cx-rose-bg px-2 text-[12px] font-semibold text-cx-rose-ink">
                  <ShieldAlert className="h-3 w-3" aria-hidden="true" />
                  {patient.critical_alerts} {patient.critical_alerts === 1 ? 'alert' : 'alerts'}
                </span>
              )}
            </span>
          </div>
          {facts.length > 0 && (
            <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-[13px] leading-5 text-cx-text-2">
              {facts.map((fact, index) => (
                <span key={fact} className="whitespace-nowrap">
                  {index > 0 && (
                    <span aria-hidden="true" className="mr-1.5 text-cx-text-3">
                      ·
                    </span>
                  )}
                  <span className={index === 0 && patient.uhid ? 'font-medium tracking-[0.01em] text-cx-text' : undefined}>{fact}</span>
                </span>
              ))}
            </p>
          )}
        </div>
      </div>

      {/* Facts: a tinted band on cards, the row's own cells from xl. */}
      <div className="mt-3.5 grid grid-cols-2 gap-x-4 gap-y-3 rounded-md border border-[#E8EEF8] bg-gradient-to-br from-[#F3FAFD] to-[#F5F3FE] p-3 sm:p-3.5 xl:contents">
        <Fact hue="blue" icon={Phone} label="Mobile">
          <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
        </Fact>
        <Fact hue="teal" icon={Stethoscope} label="Doctor">
          {patient.assigned_doctor ? (
            <>
              <Value>{patient.assigned_doctor.name}</Value>
              {!patient.assigned_doctor.at_clinic && <Sub>No longer at the clinic</Sub>}
            </>
          ) : (
            <Value empty>Not assigned</Value>
          )}
        </Fact>
        {view === 'today' ? (
          <Fact hue="violet" icon={CalendarCheck} label="Today’s visit" wide>
            <Value>{patient.today_visit ? (VISIT_STATUS[patient.today_visit.status] ?? 'Checked in') : 'Checked in'}</Value>
            {showBranch && patient.today_visit?.branch && <Sub>{patient.today_visit.branch}</Sub>}
          </Fact>
        ) : (
          <Fact hue="violet" icon={Activity} label="Last activity" wide>
            <Value empty={!patient.last_activity_at}>{lastSeen(patient.last_activity_at) ?? 'No activity yet'}</Value>
            {showBranch && patient.last_branch && (
              <Sub>
                {patient.last_branch}
                {patient.other_branch_count > 0 && ` +${patient.other_branch_count} more`}
              </Sub>
            )}
          </Fact>
        )}
      </div>

      <span className="hidden xl:block" aria-hidden="true">
        {href && (
          <span className="row-chevron ml-auto grid h-7 w-7 place-items-center rounded-[9px] text-cx-text-3 motion-safe:transition-colors">
            <ChevronRight className="h-4 w-4" />
          </span>
        )}
      </span>
    </li>
  );
}

function Fact({
  hue,
  icon: Icon,
  label,
  wide,
  children,
}: {
  hue: keyof typeof HUE;
  icon: typeof Phone;
  label: string;
  /** Spans both columns of the card's band (the last fact, which carries a branch). */
  wide?: boolean;
  children: ReactNode;
}) {
  return (
    <div className={cn('flex min-w-0 items-center gap-2.5', wide && 'col-span-2 xl:col-span-1')}>
      <span className={cn('hidden h-[30px] w-[30px] shrink-0 place-items-center rounded-[9px] xl:grid', HUE[hue].tile)} aria-hidden="true">
        <Icon className="h-4 w-4" strokeWidth={1.9} />
      </span>
      <div className="min-w-0">
        <p className="mb-0.5 flex items-center gap-1.5 text-[12px] font-medium leading-4 text-cx-text-2 xl:sr-only">
          <i className={cn('inline-block h-1.5 w-1.5 rounded-[2px]', HUE[hue].dot)} aria-hidden="true" />
          {label}
        </p>
        <div className="min-w-0">{children}</div>
      </div>
    </div>
  );
}

function Value({ empty, children }: { empty?: boolean; children: ReactNode }) {
  return <span className={cn('block truncate text-[14px] leading-5', empty ? 'text-cx-text-2' : 'font-medium text-cx-text')}>{children}</span>;
}

function Sub({ children }: { children: ReactNode }) {
  return <span className="mt-px block truncate text-[12px] font-medium leading-4 text-cx-violet-ink">{children}</span>;
}

/** Rows in the list's own shape while a page loads. */
export function PatientListSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div role="status" aria-label="Loading patients" className="grid gap-3 md:grid-cols-2 xl:grid-cols-1 xl:gap-2.5">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="flex items-center gap-3.5 rounded-[14px] border border-cx-line bg-white px-5 py-4">
          <Skeleton className="h-11 w-11 rounded-[14px]" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-3.5 w-1/3" />
            <Skeleton className="h-3 w-1/4" />
          </div>
          <Skeleton className="hidden h-3.5 w-1/6 xl:block" />
          <Skeleton className="hidden h-3.5 w-1/6 xl:block" />
        </div>
      ))}
    </div>
  );
}
