import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Activity, Users } from 'lucide-react';
import {
  CrmPageHead,
  CrmSection,
  CrmField,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmSelect,
  CrmAvatar,
  CrmDataState,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * One employee, on the company shell.
 *
 * Rebuilt off `ExecutiveShell` and a page of hand-written hex. Its sibling —
 * the workforce directory — has been on `PeopleShell` for some time, so opening
 * a person from that list changed the chrome around you: different header,
 * different rail, different everything. This is the same module and now looks
 * like it.
 *
 * The status list is `EmploymentStatus`, exactly. It used to offer `probation`,
 * `contract` and `terminated`, none of which are in that enum, while omitting
 * four that are — so half the options failed validation and half the real
 * statuses could not be reached.
 */

interface EmployeeDetailProps {
  employee: {
    id: number;
    employee_code: string;
    designation: string | null;
    employment_status: string;
    joining_date: string | null;
    user?: { name?: string; email?: string } | null;
    department?: { name?: string } | null;
    team?: { name?: string } | null;
    manager?: { user?: { name?: string } | null } | null;
    directReports?: Array<{ id: number; user?: { name?: string; email?: string } | null }>;
    activities?: Array<{ id: number; event?: string | null; description?: string | null; created_at?: string | null }>;
  };
}

/** The statuses an employee can hold, named once. */
const EMPLOYMENT_STATUSES: { value: string; label: string }[] = [
  { value: 'pending_invitation', label: 'Pending invitation' },
  { value: 'invited', label: 'Invited' },
  { value: 'verified', label: 'Verified' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'resigned', label: 'Resigned' },
];

const STATUS_TONE: Record<string, CrmStatusTone> = {
  active: 'good',
  verified: 'good',
  invited: 'warn',
  pending_invitation: 'warn',
  inactive: 'neutral',
  suspended: 'bad',
  resigned: 'bad',
};

const label = (value: string | null | undefined, fallback = '—') => {
  const words = String(value ?? '').replace(/_/g, ' ').trim();

  return words ? words.charAt(0).toUpperCase() + words.slice(1) : fallback;
};

const onDate = (value: string | null | undefined) =>
  value ? new Date(value).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

export default function EmployeeDetail({ employee }: EmployeeDetailProps) {
  const [changingStatus, setChangingStatus] = useState(false);
  const [status, setStatus] = useState(employee.employment_status || 'active');

  const person = employee.user ?? {};
  const reports = employee.directReports ?? [];
  const activities = employee.activities ?? [];

  const submitStatus = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      route('company.people.employees.transition', employee.id),
      { employment_status: status },
      { preserveScroll: true, onSuccess: () => setChangingStatus(false) },
    );
  };

  return (
    <PeopleShell title={person.name ?? employee.employee_code}>
      <Link
        href={route('company.people.employees.index')}
        className="mb-3 inline-flex items-center gap-1.5 text-[12.5px] font-medium text-ovs-crm-text-secondary transition hover:text-ovs-crm-text-primary"
      >
        <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
        All employees
      </Link>

      <CrmPageHead
        eyebrow="People"
        title={person.name ?? 'Employee'}
        stats={
          <>
            <span className="tabular-nums text-ovs-crm-text-secondary">{employee.employee_code}</span>
            <CrmStatusPill tone={STATUS_TONE[employee.employment_status] ?? 'neutral'}>
              {label(employee.employment_status)}
            </CrmStatusPill>
          </>
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {/*
              Compensation belongs to the person, so it is reached from here
              rather than from a payroll settings screen.
            */}
            <CrmButton href={`/company/people/employees/${employee.id}/compensation`}>Compensation</CrmButton>
            <CrmButton onClick={() => setChangingStatus(true)}>Change status</CrmButton>
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <CrmSection title="Role and reporting">
          <div className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <CrmField label="Employee code" value={employee.employee_code} />
            <CrmField label="Designation" value={employee.designation ?? '—'} />
            <CrmField label="Department" value={employee.department?.name ?? '—'} />
            <CrmField label="Team" value={employee.team?.name ?? '—'} />
            <CrmField label="Manager" value={employee.manager?.user?.name ?? '—'} />
            <CrmField label="Joined" value={onDate(employee.joining_date)} />
            <CrmField label="Email" value={person.email ?? '—'} />
          </div>
        </CrmSection>

        <CrmSection title={`Direct reports (${reports.length})`}>
          {reports.length === 0 ? (
            <CrmDataState
              kind="empty"
              compact
              icon={Users}
              title="Nobody reports to this person"
              description="Assign a manager on an employee to build the reporting line."
            />
          ) : (
            <ul className="space-y-2">
              {reports.map((report) => (
                <li
                  key={report.id}
                  className="flex items-center gap-3 rounded-[16px] border border-ovs-crm-border px-4 py-3"
                >
                  <CrmAvatar name={report.user?.name ?? '—'} size="sm" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13.5px] font-medium text-ovs-crm-text-primary">
                      {report.user?.name ?? '—'}
                    </span>
                    <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                      {report.user?.email ?? '—'}
                    </span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CrmSection>
      </div>

      <CrmSection title="Audit trail" className="mt-4">
        {activities.length === 0 ? (
          <CrmDataState
            kind="empty"
            compact
            icon={Activity}
            title="Nothing recorded yet"
            description="Status changes and sign-in events appear here."
          />
        ) : (
          <ul className="space-y-2">
            {activities.map((entry) => (
              <li key={entry.id} className="rounded-[16px] border border-ovs-crm-border px-4 py-3">
                <p className="text-[13px] font-medium text-ovs-crm-text-primary">
                  {entry.description ?? entry.event ?? 'Activity'}
                </p>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                  {[entry.event, entry.created_at ? new Date(entry.created_at).toLocaleString('en-IN') : null]
                    .filter(Boolean)
                    .join(' · ')}
                </p>
              </li>
            ))}
          </ul>
        )}
      </CrmSection>

      <CrmModal
        open={changingStatus}
        onClose={() => setChangingStatus(false)}
        title="Change employment status"
        description="Recorded on this employee's audit trail."
        footer={
          <>
            <CrmButton onClick={() => setChangingStatus(false)}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="employee-status">
              Update status
            </CrmButton>
          </>
        }
      >
        <form id="employee-status" onSubmit={submitStatus}>
          <CrmFormField label="New status" required>
            <CrmSelect required value={status} onChange={(e) => setStatus(e.target.value)}>
              {EMPLOYMENT_STATUSES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
