import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronRight, Mail, Phone, ShieldAlert } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SpecialtyBadge, StatusBadge } from '@/clinic/ui/badges';
import { Skeleton } from '@/clinic/ui/Spinner';
import { CAPTION, ROW_INTERACTIVE } from '../design';
import { lastSeen } from '../format';
import { PatientIdentity } from '../PatientIdentity';
import type { DirectoryPatient } from '../types';

/**
 * The directory's rows: a table from `lg`, stacked rows below it, with the
 * same facts in the same order. A row opens the patient's profile when that
 * page exists (`profileHref` is null until then).
 */
export function PatientList({ patients, profileHref }: { patients: DirectoryPatient[]; profileHref: (id: number) => string | null }) {
  return (
    <>
      <table className="hidden w-full table-fixed lg:table">
        <caption className="sr-only">Patients</caption>
        <colgroup>
          <col className="w-[34%]" />
          <col className="w-[22%]" />
          <col className="w-[18%]" />
          <col className="w-[16%]" />
          <col className="w-[10%]" />
        </colgroup>
        <thead>
          <tr className="border-b border-cx-line">
            {['Patient', 'Contact', 'Care', 'Last seen', ''].map((heading, index) => (
              <th key={index} scope="col" className={cn(CAPTION, 'px-5 py-2.5 text-left')}>
                {heading || <span className="sr-only">Open</span>}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-cx-line">
          {patients.map((patient) => {
            const href = profileHref(patient.id);

            return (
              <tr key={patient.id} className={cn('relative', href && ROW_INTERACTIVE)}>
                <td className="px-5 py-3 align-middle">
                  <Identity patient={patient} href={href} />
                </td>
                <td className="px-5 py-3 align-middle">
                  <Contact patient={patient} />
                </td>
                <td className="px-5 py-3 align-middle">
                  <Care patient={patient} />
                </td>
                <td className="px-5 py-3 align-middle">
                  <Seen patient={patient} />
                </td>
                <td className="px-5 py-3 text-right align-middle">{href && <ChevronRight className="ml-auto h-4 w-4 text-cx-text-3" aria-hidden="true" />}</td>
              </tr>
            );
          })}
        </tbody>
      </table>

      <ul className="divide-y divide-cx-line lg:hidden">
        {patients.map((patient) => {
          const href = profileHref(patient.id);

          return (
            <li key={patient.id} className={cn('relative px-4 py-3.5 sm:px-5', href && ROW_INTERACTIVE)}>
              <div className="flex items-start justify-between gap-3">
                <Identity patient={patient} href={href} />
                {href && <ChevronRight className="mt-2.5 h-4 w-4 shrink-0 text-cx-text-3" aria-hidden="true" />}
              </div>
              <div className="mt-2.5 grid gap-2 pl-[52px] sm:grid-cols-3">
                <Contact patient={patient} />
                <Care patient={patient} />
                <Seen patient={patient} />
              </div>
            </li>
          );
        })}
      </ul>
    </>
  );
}

function Identity({ patient, href }: { patient: DirectoryPatient; href: string | null }) {
  const trailing: ReactNode = (
    <>
      {patient.status !== 'active' && <StatusBadge status={patient.status} />}
      {patient.critical_alerts > 0 && (
        <span className="inline-flex items-center gap-1 rounded-full bg-cx-rose-bg px-2 py-0.5 text-[11.5px] font-semibold text-cx-rose-ink">
          <ShieldAlert className="h-3 w-3" aria-hidden="true" />
          {patient.critical_alerts} {patient.critical_alerts === 1 ? 'alert' : 'alerts'}
        </span>
      )}
    </>
  );
  const identity = <PatientIdentity name={patient.name} uhid={patient.uhid} age={patient.age} gender={patient.gender} trailing={trailing} />;

  if (!href) {
    return identity;
  }

  // The link covers the whole row (the row is `relative`); its text is the patient's name.
  return (
    <Link href={href} className="block min-w-0 rounded-lg focus-visible:outline-none after:absolute after:inset-0 after:content-['']" aria-label={`Open ${patient.name}`}>
      {identity}
    </Link>
  );
}

function Contact({ patient }: { patient: DirectoryPatient }) {
  if (!patient.phone && !patient.email) {
    return <span className="text-[12.5px] text-cx-text-3">No contact on file</span>;
  }

  return (
    <div className="min-w-0 space-y-0.5 text-[13px] text-cx-nav-ink">
      {patient.phone && (
        <p className="flex min-w-0 items-center gap-1.5">
          <Phone className="h-3.5 w-3.5 shrink-0 text-cx-text-3" aria-hidden="true" />
          <span className="sr-only">Mobile </span>
          <span className="truncate tabular-nums">{patient.phone}</span>
        </p>
      )}
      {patient.email && (
        <p className="flex min-w-0 items-center gap-1.5 text-cx-text-2">
          <Mail className="h-3.5 w-3.5 shrink-0 text-cx-text-3" aria-hidden="true" />
          <span className="sr-only">Email </span>
          <span className="truncate">{patient.email}</span>
        </p>
      )}
    </div>
  );
}

function Care({ patient }: { patient: DirectoryPatient }) {
  const care = patient.active_care[0];

  if (!care && patient.specialties.length === 0) {
    return <span className="text-[12.5px] text-cx-text-3">—</span>;
  }

  return (
    <div className="min-w-0 space-y-1">
      {patient.specialties.length > 0 && (
        <div className="flex flex-wrap gap-1">
          {patient.specialties.map((specialty) => (
            <SpecialtyBadge key={specialty} specialty={specialty} />
          ))}
        </div>
      )}
      {care && (
        <p className="truncate text-[12px] text-cx-text-2" title={care.title}>
          <span className="font-medium text-cx-nav-ink">{care.title}</span>
          {care.progress.total ? ` · ${care.progress.done}/${care.progress.total} ${care.progress.unit}` : null}
          {patient.active_care.length > 1 && ` +${patient.active_care.length - 1} more`}
        </p>
      )}
    </div>
  );
}

function Seen({ patient }: { patient: DirectoryPatient }) {
  const seen = lastSeen(patient.last_activity_at);

  return (
    <div className="min-w-0 text-[12.5px]">
      <p className="text-cx-nav-ink">{seen ?? 'Not seen yet'}</p>
      {patient.last_branch && <p className="truncate text-cx-text-3">{patient.last_branch}</p>}
    </div>
  );
}

/** Rows in the list's own shape while a page loads. */
export function PatientListSkeleton({ rows = 8 }: { rows?: number }) {
  return (
    <div role="status" aria-label="Loading patients" className="divide-y divide-cx-line">
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="flex items-center gap-3 px-5 py-3.5">
          <Skeleton className="h-10 w-10 rounded-full" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-3.5 w-1/3" />
            <Skeleton className="h-3 w-1/4" />
          </div>
          <Skeleton className="hidden h-3.5 w-1/5 lg:block" />
          <Skeleton className="hidden h-3.5 w-1/6 lg:block" />
        </div>
      ))}
    </div>
  );
}
