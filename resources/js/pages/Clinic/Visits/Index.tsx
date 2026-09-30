import React from 'react';
import { router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { CrmPageHead, CrmPageStat, CrmPagination, CrmTable } from '@/pages/Company/design';
import type { CrmColumn } from '@/pages/Company/design';
import { Badge } from '@/pages/Company/design/CrmBadgePrimitives';
import { ClinicShell } from '../components/ClinicShell';

/*
 * Every visit registered at the active branch, newest first. A visit is the
 * attendance record reception creates at check-in; clinical notes hang off
 * the encounter, not here.
 */

interface VisitRow {
  id: number;
  patient_id: number;
  patient_name: string;
  patient_uhid: string;
  case_number: string;
  practitioner_name: string;
  branch_name: string;
  visit_date: string;
  visit_status: string;
  queue_status: string;
  notes: string | null;
}

interface Paginated<T> {
  data: T[];
  links: { url: string | null; label: string; active: boolean }[];
  from: number | null;
  to: number | null;
  total: number;
}

interface VisitsPageProps {
  visits: Paginated<VisitRow>;
  filters: { date: string | null };
}

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'indigo' | 'neutral' | 'danger'> = {
  completed: 'success',
  in_progress: 'indigo',
  in_consultation: 'indigo',
  checked_in: 'warning',
  waiting: 'warning',
  called: 'warning',
  cancelled: 'danger',
};

function label(value: string): string {
  return value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');
}

export default function VisitsIndex({ visits, filters }: VisitsPageProps) {
  const applyDate = (date: string) =>
    router.get(route('clinic.visits.index'), date ? { date } : {}, { preserveState: true, preserveScroll: true, replace: true });

  const columns: CrmColumn<VisitRow>[] = [
    {
      key: 'patient',
      header: 'Patient',
      primary: true,
      cell: (row) => (
        <div className="min-w-0">
          <p className="truncate font-semibold text-ovs-crm-text-primary">{row.patient_name}</p>
          <p className="text-2xs text-ovs-crm-text-secondary">{row.patient_uhid}</p>
        </div>
      ),
    },
    { key: 'case', header: 'Case no.', cell: (row) => <span className="tabular-nums text-ovs-crm-text-secondary">{row.case_number}</span> },
    { key: 'date', header: 'Date', cell: (row) => <span className="whitespace-nowrap text-ovs-crm-text-secondary">{row.visit_date}</span> },
    { key: 'doctor', header: 'Doctor', hideOnMobile: true, cell: (row) => <span className="text-ovs-crm-text-secondary">{row.practitioner_name}</span> },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => (
        <Badge variant={STATUS_VARIANT[row.visit_status] ?? 'neutral'} size="compact">
          {label(row.visit_status)}
        </Badge>
      ),
    },
  ];

  return (
    <ClinicShell title="Visits">
      <CrmPageHead
        eyebrow="Front desk"
        title="Visits"
        description="Visits registered at this branch, newest first."
        stats={<CrmPageStat icon={ClipboardList} value={visits.total} caption={filters.date ? 'Visits that day' : 'Visits'} />}
      />

      <div className="space-y-4 pb-12">
        <div className="flex flex-wrap items-center gap-2">
          <label htmlFor="visit-date" className="text-[12px] font-semibold text-ovs-crm-text-secondary">Date</label>
          <input
            id="visit-date"
            type="date"
            value={filters.date ?? ''}
            onChange={(e) => applyDate(e.target.value)}
            className="rounded-full border border-ovs-crm-border bg-white px-3 py-1.5 text-[12.5px] text-ovs-crm-text-primary"
          />
          {filters.date && (
            <button
              type="button"
              onClick={() => applyDate('')}
              className="rounded-full px-3 py-1.5 text-[12.5px] font-semibold text-ovs-crm-text-secondary hover:text-ovs-crm-text-primary"
            >
              Show all dates
            </button>
          )}
        </div>

        <CrmTable
          columns={columns}
          rows={visits.data}
          rowKey={(row) => row.id}
          onRowClick={(row) => router.visit(route('clinic.patients.show', row.patient_id))}
          isFiltered={Boolean(filters.date)}
          empty={{ icon: ClipboardList, title: 'No visits yet', description: 'Visits appear here once reception checks patients in.' }}
          emptyFiltered={{ title: 'No visits on this date' }}
          footer={
            visits.total > 0 ? <CrmPagination links={visits.links} from={visits.from} to={visits.to} total={visits.total} /> : undefined
          }
        />
      </div>
    </ClinicShell>
  );
}
