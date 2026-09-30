import React, { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, Droplet, Mail, Phone, Plus, UserCheck, UserPlus, Users } from 'lucide-react';
import {
  CrmAvatar,
  CrmButton,
  CrmFilterBar,
  CrmPageHead,
  CrmPageStat,
  CrmPagination,
  CrmStatusPill,
  CrmTable,
  CrmTabs,
  type CrmColumn,
  type CrmFilterDef,
} from '@/pages/Company/design';
import { ClinicShell } from '../components/ClinicShell';
import { QuickAddPatientModal } from './components/QuickAddPatientModal';
import { STATUS_LABEL, STATUS_TONE } from './patientTheme';
import { PatientDirectoryTab, PatientListRow, PatientsIndexProps } from './types';

/*
 * Patient directory — the first Clinic-side list built to the "premium,
 * colourful, alive" bar the pre-auth pages set, not the flatter table
 * language Employees/Branches used. Search and both filters are server
 * round-trips (`PatientController::index()` already does the filtering,
 * pagination and summary counts) — this page never re-implements that
 * client-side, it just debounces the request.
 */

const STATUS_OPTIONS = [
  { value: 'all', label: 'All statuses' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'deceased', label: 'Deceased' },
];

const GENDER_OPTIONS = [
  { value: 'all', label: 'All genders' },
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
];

export default function PatientsIndex({ patients, filters, isPractitioner, metrics, practitioners, allergyCatalogue, fieldConfig }: PatientsIndexProps) {
  const [quickAddOpen, setQuickAddOpen] = useState(false);
  const [search, setSearch] = useState(filters.q);
  const [status, setStatus] = useState(filters.status ?? 'all');
  const [gender, setGender] = useState(filters.gender ?? 'all');
  const [tab, setTab] = useState<PatientDirectoryTab>(filters.tab);
  const [isFetching, setIsFetching] = useState(false);
  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => () => clearTimeout(searchTimer.current), []);

  const applyFilters = (next: { q?: string; status?: string; gender?: string; tab?: PatientDirectoryTab }) => {
    const q = next.q ?? search;
    const nextStatus = next.status ?? status;
    const nextGender = next.gender ?? gender;
    const nextTab = next.tab ?? tab;

    router.get(
      route('clinic.patients.index'),
      {
        q: q.trim() === '' ? undefined : q,
        status: nextStatus === 'all' ? undefined : nextStatus,
        gender: nextGender === 'all' ? undefined : nextGender,
        tab: isPractitioner ? nextTab : undefined,
      },
      {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => setIsFetching(true),
        onFinish: () => setIsFetching(false),
      }
    );
  };

  const handleTabChange = (nextTab: PatientDirectoryTab) => {
    setTab(nextTab);
    applyFilters({ tab: nextTab });
  };

  const handleSearchChange = (value: string) => {
    setSearch(value);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => applyFilters({ q: value }), 300);
  };

  const handleFilterChange = (key: string, value: string) => {
    if (key === 'status') {
      setStatus(value);
      applyFilters({ status: value });
    }
    if (key === 'gender') {
      setGender(value);
      applyFilters({ gender: value });
    }
  };

  const handleClearAll = () => {
    setSearch('');
    setStatus('all');
    setGender('all');
    router.get(
      route('clinic.patients.index'),
      { tab: isPractitioner ? tab : undefined },
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const isFiltered = search.trim() !== '' || status !== 'all' || gender !== 'all';

  const filterDefs: CrmFilterDef[] = [
    { key: 'status', label: 'Status', value: status, options: STATUS_OPTIONS },
    { key: 'gender', label: 'Gender', value: gender, options: GENDER_OPTIONS },
  ];

  const columns: CrmColumn<PatientListRow>[] = [
    {
      key: 'patient',
      header: 'Patient',
      primary: true,
      cell: (patient) => (
        <div className="flex min-w-0 items-center gap-3">
          <CrmAvatar name={patient.name} size="md" />
          <div className="min-w-0">
            <div className="flex items-center gap-1.5">
              <p className="truncate text-[13.5px] font-semibold text-ovs-crm-text-primary">{patient.name}</p>
              {patient.blood_group && (
                <span className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-ovs-crm-stage-coral-soft px-1.5 py-0.5 text-[10px] font-medium text-ovs-crm-stage-coral-text">
                  <Droplet className="h-2.5 w-2.5" aria-hidden="true" />
                  {patient.blood_group}
                </span>
              )}
              {patient.has_critical_alert && (
                <span
                  title="Has a critical or high-severity alert"
                  className="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-ovs-crm-danger-soft px-1.5 py-0.5 text-[10px] font-medium text-ovs-crm-danger-text"
                >
                  <AlertTriangle className="h-2.5 w-2.5" aria-hidden="true" />
                  Alert
                </span>
              )}
            </div>
            <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
              <span className="tabular-nums">{patient.uhid}</span>
              {patient.age !== null && ` · ${patient.age}yrs`}
              {patient.gender && ` · ${patient.gender.charAt(0).toUpperCase()}${patient.gender.slice(1)}`}
            </p>
          </div>
        </div>
      ),
    },
    {
      key: 'contact',
      header: 'Contact',
      cell: (patient) => (
        <div className="min-w-0 space-y-1">
          {patient.phone ? (
            <span className="flex items-center gap-1 text-ovs-crm-text-secondary">
              <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-ovs-crm-accent-blue-soft text-ovs-crm-accent-blue-text">
                <Phone className="h-2.5 w-2.5" aria-hidden="true" />
              </span>
              <span className="truncate">{patient.phone}</span>
            </span>
          ) : (
            <span className="text-ovs-crm-text-muted">No phone on file</span>
          )}
          {patient.email && (
            <span className="flex items-center gap-1 text-[11.5px] text-ovs-crm-text-muted">
              <span className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-ovs-crm-surface-inset text-ovs-crm-text-muted">
                <Mail className="h-2.5 w-2.5" aria-hidden="true" />
              </span>
              <span className="truncate">{patient.email}</span>
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'last_visit',
      header: 'Last Visit',
      hideOnMobile: true,
      cell: (patient) =>
        patient.last_visit_date === 'Never' ? (
          <span className="text-ovs-crm-text-muted">Never</span>
        ) : (
          <span
            className={`inline-flex items-center gap-1.5 ${patient.last_visit_is_recent ? 'text-ovs-crm-accent-green-text' : 'text-ovs-crm-text-secondary'}`}
          >
            {patient.last_visit_is_recent && (
              <span className="mt-[3px] h-1.5 w-1.5 shrink-0 self-start rounded-full bg-ovs-crm-accent-green-text" aria-hidden="true" />
            )}
            {patient.last_visit_date}
          </span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (patient) => <CrmStatusPill tone={STATUS_TONE[patient.status]}>{STATUS_LABEL[patient.status]}</CrmStatusPill>,
    },
  ];

  return (
    <ClinicShell title="Patients">
      <CrmPageHead
        eyebrow="Clinic"
        title="Patients"
        stats={
          <>
            <CrmPageStat icon={Users} value={metrics.total} caption={`Patient${metrics.total === 1 ? '' : 's'}`} />
            {metrics.registeredToday > 0 && (
              <CrmPageStat icon={UserPlus} value={metrics.registeredToday} chip="today" chipHue="#3DDC84" caption="Registered today" />
            )}
          </>
        }
        actions={
          <CrmButton tone="primary" icon={Plus} onClick={() => setQuickAddOpen(true)}>
            Add Patient
          </CrmButton>
        }
      />

      <div className="space-y-4 pb-12">
        {isPractitioner && (
          <CrmTabs
            tabs={[
              { key: 'mine', label: 'My Patients', count: metrics.mine },
              { key: 'all', label: 'All Patients', count: metrics.total },
            ]}
            value={tab}
            onChange={handleTabChange}
          />
        )}

        <CrmFilterBar
          searchValue={search}
          onSearchChange={handleSearchChange}
          searchPlaceholder="Name, phone or UHID…"
          loading={isFetching}
          filters={filterDefs}
          onFilterChange={handleFilterChange}
          onClearAll={handleClearAll}
          resultCount={patients.total}
          resultNoun="patient"
        />

        <CrmTable
          variant="ruled"
          columns={columns}
          rows={patients.data}
          rowKey={(patient) => patient.id}
          onRowClick={(patient) => router.visit(route('clinic.patients.show', patient.id))}
          isFiltered={isFiltered}
          footer={
            patients.links.length > 3 ? (
              <CrmPagination links={patients.links} from={patients.from} to={patients.to} total={patients.total} />
            ) : undefined
          }
          empty={
            tab === 'mine' && metrics.total > 0
              ? {
                  icon: UserCheck,
                  title: 'No patients assigned to you yet',
                  description: 'Patients assigned to you by Reception or Admin will appear here.',
                  action: (
                    <CrmButton size="sm" onClick={() => handleTabChange('all')}>
                      View All Patients
                    </CrmButton>
                  ),
                }
              : {
                  icon: Users,
                  title: 'No patients yet',
                  description: 'Register your first patient to start building the directory.',
                  action: (
                    <CrmButton size="sm" icon={Plus} onClick={() => setQuickAddOpen(true)}>
                      Register your first patient
                    </CrmButton>
                  ),
                }
          }
          emptyFiltered={{
            title: 'No patients match these filters',
            description: 'Try a different search, status or gender.',
            action: (
              <CrmButton size="sm" onClick={handleClearAll}>
                Clear filters
              </CrmButton>
            ),
          }}
        />
      </div>

      <QuickAddPatientModal
        open={quickAddOpen}
        onClose={() => setQuickAddOpen(false)}
        practitioners={practitioners}
        allergyCatalogue={allergyCatalogue}
        fieldConfig={fieldConfig}
      />
    </ClinicShell>
  );
}
