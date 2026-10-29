import { router } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { Activity, ChevronRight, ShieldAlert, Smile } from 'lucide-react';
import { Pill, isDental, isPhysio, specialtyTone, type DsTone } from '@/clinic/ds';
import { Table, type Column } from '@/clinic/ui/Table';
import { cn } from '@/lib/utils';
import type { DeskMode } from '@/pages/ClinicPortal/FrontDesk/components/desk';
import { ageSex, lastSeen } from '../format';
import { LifecycleBadge, MaskedMobile, PatientAvatar } from '../patientVisuals';
import type { ActiveCare, DirectoryPatient, DirectoryView } from '../types';

/** Where today's visit stands, from `patient_visits.visit_status`, worded for the clinic (`DeskMode`). */
function visitStatus(status: string | undefined, mode: DeskMode): { label: string; tone: DsTone } {
  switch (status) {
    case 'with_doctor':
    case 'in_progress':
      return { label: mode.active, tone: 'violet' };
    case 'completed':
      return { label: mode.physio && !mode.dental ? 'Session done' : 'Seen today', tone: 'green' };
    default:
      return { label: 'Checked in', tone: 'amber' };
  }
}

function careLabel(care: ActiveCare): string {
  const { done, total, unit } = care.progress;

  return total ? `${care.title} · ${done}/${total} ${unit}` : `${care.title} · ${done} ${unit}`;
}

/** What a row needs to know about the clinic: its wording, and the specialties' names when there is more than one to tell apart. */
export interface DirectoryContext {
  mode: DeskMode;
  /** Specialty key → label; empty in a single-specialty clinic, where a chip would say nothing new. */
  specialtyLabels: Record<string, string>;
}

function SpecialtyPills({ keys, labels }: { keys: string[]; labels: Record<string, string> }) {
  const known = keys.filter((key) => labels[key]);
  if (known.length === 0) {
    return null;
  }

  return (
    <>
      {known.map((key) => (
        <Pill key={key} tone={specialtyTone(key)} icon={isPhysio(key) ? Activity : isDental(key) ? Smile : undefined}>
          {labels[key]}
        </Pill>
      ))}
    </>
  );
}

function NameCell({ patient, href, context }: { patient: DirectoryPatient; href: string | null; context: DirectoryContext }) {
  const facts = [patient.uhid, ageSex(patient.age, patient.gender)].filter(Boolean).join(' · ');

  return (
    <div className="flex min-w-0 items-center gap-3">
      <PatientAvatar name={patient.name} quiet={patient.status === 'merged'} />
      <div className="min-w-0">
        <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          {href ? (
            <Link
              href={href}
              className="truncate text-[14px] font-semibold text-[#173B60] hover:text-[#1769AA] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]"
            >
              {patient.name}
            </Link>
          ) : (
            <span className="truncate text-[14px] font-semibold text-[#173B60]">{patient.name}</span>
          )}
          <LifecycleBadge status={patient.status} />
          {patient.critical_alerts > 0 && (
            <Pill tone="rose" icon={ShieldAlert}>
              {patient.critical_alerts} {patient.critical_alerts === 1 ? 'alert' : 'alerts'}
            </Pill>
          )}
        </div>
        <div className="mt-1 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
          {facts && <span className="truncate text-[12.5px] text-[#475569]">{facts}</span>}
          <SpecialtyPills keys={patient.specialties} labels={context.specialtyLabels} />
        </div>
      </div>
    </div>
  );
}

function DoctorCell({ patient }: { patient: DirectoryPatient }) {
  if (!patient.assigned_doctor) {
    return <span className="whitespace-nowrap text-[#64748B]">Not assigned</span>;
  }

  return (
    <div className="min-w-0 lg:max-w-44">
      <p className="truncate font-medium text-[#173B60]">{patient.assigned_doctor.name}</p>
      {!patient.assigned_doctor.at_clinic && <p className="text-[12px] text-[#9A5B00]">No longer at the clinic</p>}
    </div>
  );
}

function ActivityCell({ patient, view, showBranch, mode }: { patient: DirectoryPatient; view: DirectoryView; showBranch: boolean; mode: DeskMode }) {
  if (view === 'today') {
    const status = visitStatus(patient.today_visit?.status, mode);

    return (
      <div className="min-w-0">
        <Pill tone={status.tone}>{status.label}</Pill>
        {showBranch && patient.today_visit?.branch && <p className="mt-1 truncate text-[12px] text-[#64748B]">{patient.today_visit.branch}</p>}
      </div>
    );
  }

  return (
    <div className="min-w-0">
      <p className={cn('whitespace-nowrap', patient.last_activity_at ? 'text-[#173B60]' : 'text-[#64748B]')}>{lastSeen(patient.last_activity_at) ?? 'No activity yet'}</p>
      {showBranch && patient.last_branch && (
        <p className="truncate text-[12px] text-[#64748B]">
          {patient.last_branch}
          {patient.other_branch_count > 0 && ` +${patient.other_branch_count} more`}
        </p>
      )}
    </div>
  );
}

function CareCell({ patient }: { patient: DirectoryPatient }) {
  if (patient.active_care.length === 0) {
    return <span className="text-[#64748B]">—</span>;
  }
  return (
    <div className="min-w-0">
      <p className="truncate font-medium text-[#173B60]">{careLabel(patient.active_care[0])}</p>
      {patient.active_care.length > 1 && <p className="text-[12px] text-[#64748B]">+{patient.active_care.length - 1} more</p>}
    </div>
  );
}

/**
 * The directory's patients: a table from `lg` (identity, mobile, doctor,
 * active care, last activity or today's visit) and a card per patient below
 * it. Opening a row goes to Patient 360 when the server gave its link; the
 * mobile number is masked unless the server sent it in full. In a clinic
 * with more than one specialty each patient carries their Dental /
 * Physiotherapy chips; the doctor and visit wording follow the clinic.
 */
export function DirectoryTable({
  patients,
  view,
  showBranch,
  profileHref,
  loading,
  context,
}: {
  patients: DirectoryPatient[];
  view: DirectoryView;
  showBranch: boolean;
  profileHref: (id: number) => string | null;
  loading: boolean;
  context: DirectoryContext;
}) {
  const activityHeader = view === 'today' ? 'Today’s visit' : 'Last activity';
  const columns: Column<DirectoryPatient>[] = [
    { key: 'patient', header: 'Patient', cell: (patient) => <NameCell patient={patient} href={profileHref(patient.id)} context={context} />, className: 'min-w-[240px]' },
    { key: 'mobile', header: 'Mobile', cell: (patient) => <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} /> },
    { key: 'doctor', header: context.mode.doctor, cell: (patient) => <DoctorCell patient={patient} /> },
    { key: 'care', header: 'Active care', cell: (patient) => <CareCell patient={patient} />, hideBelow: 'xl' },
    { key: 'activity', header: activityHeader, cell: (patient) => <ActivityCell patient={patient} view={view} showBranch={showBranch} mode={context.mode} /> },
    {
      key: 'open',
      header: <span className="sr-only">Open</span>,
      cell: (patient) => (profileHref(patient.id) ? <ChevronRight className="ml-auto h-4 w-4 text-[#94A3B8] group-hover:text-[#1769AA]" aria-hidden="true" /> : null),
      className: 'w-10',
    },
  ];

  return (
    <div className={cn(loading && patients.length > 0 && 'pointer-events-none opacity-60 motion-safe:transition-opacity')}>
      <Table
        caption="Patients"
        columns={columns}
        rows={patients}
        rowKey={(patient) => patient.id}
        loading={loading && patients.length === 0}
        onRowClick={(patient) => {
          const href = profileHref(patient.id);
          if (href) {
            router.visit(href);
          }
        }}
        mobileCard={(patient) => (
          <div className="space-y-2.5">
            <NameCell patient={patient} href={profileHref(patient.id)} context={context} />
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl bg-[#F4F7FB] px-3 py-2.5 text-[13px]">
              <div className="min-w-0">
                <dt className="text-[11.5px] font-semibold text-[#64748B]">Mobile</dt>
                <dd>
                  <MaskedMobile name={patient.name} phone={patient.phone} lastDigits={patient.phone_last_digits} />
                </dd>
              </div>
              <div className="min-w-0">
                <dt className="text-[11.5px] font-semibold text-[#64748B]">{context.mode.doctor}</dt>
                <dd>
                  <DoctorCell patient={patient} />
                </dd>
              </div>
              <div className="col-span-2 min-w-0">
                <dt className="text-[11.5px] font-semibold text-[#64748B]">{activityHeader}</dt>
                <dd className="mt-0.5">
                  <ActivityCell patient={patient} view={view} showBranch={showBranch} mode={context.mode} />
                </dd>
              </div>
              {patient.active_care.length > 0 && (
                <div className="col-span-2 min-w-0">
                  <dt className="text-[11.5px] font-semibold text-[#64748B]">Active care</dt>
                  <dd>
                    <CareCell patient={patient} />
                  </dd>
                </div>
              )}
            </dl>
          </div>
        )}
      />
    </div>
  );
}
