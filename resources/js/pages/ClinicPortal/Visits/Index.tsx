import { Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { Pager, type PagedList } from '@/clinic/billing/BillingFrame';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Input } from '@/clinic/ui/controls';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { EmptyState } from '@/clinic/ui/states';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

interface Visit {
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

/** `VisitController::index`: the server is the contract. */
interface Props {
  visits: PagedList<Visit>;
  filters: { date: string | null };
  links: { patient: string };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const VISIT_TONE: Record<string, string> = {
  completed: 'bg-cx-green-bg text-cx-green-ink',
  in_progress: 'bg-cx-blue-bg text-cx-blue-ink',
  checked_in: 'bg-cx-blue-bg text-cx-blue-ink',
  cancelled: 'bg-cx-rose-bg text-cx-rose-ink',
  no_show: 'bg-cx-rose-bg text-cx-rose-ink',
};

export default function VisitsIndex(props: Props) {
  return (
    <ClinicPortalShell title="Visits">
      <VisitsPage {...props} />
    </ClinicPortalShell>
  );
}

/** Every visit at the branch, newest first: who came, with whom, and where they stand. Filter to one day. */
function VisitsPage({ visits, filters, links }: Props) {
  const go = (date: string | null) => router.get(window.location.pathname, date ? { date } : {}, { preserveScroll: true, preserveState: true });

  return (
    <div className="w-full pb-12">
      <PageHeader icon={ClipboardList} title="Visits" description={`${visits.total} ${visits.total === 1 ? 'visit' : 'visits'}${filters.date ? ' on this day' : ''}`} />

      <div className={cn(SURFACE, 'mb-4 flex flex-wrap items-end gap-3 p-4')}>
        <label className="block">
          <span className="mb-1.5 block text-[12px] font-semibold text-[#173B60]">Day</span>
          <Input type="date" value={filters.date ?? ''} onChange={(event) => go(event.target.value || null)} className="w-44" />
        </label>
        {filters.date && <Button onClick={() => go(null)}>Show all days</Button>}
      </div>

      {visits.data.length === 0 ? (
        <EmptyState title="No visits" description={filters.date ? 'Nobody was seen on this day.' : 'No visit has been recorded at this branch yet.'} />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {visits.data.map((visit) => (
            <li key={visit.id}>
              <Link href={links.patient.replace('__ID__', String(visit.patient_id))} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 hover:bg-cx-slate-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#1769AA]/35">
                <PersonAvatar name={visit.patient_name} />
                <div className="min-w-0 flex-1 basis-48">
                  <p className="truncate text-[14px] font-semibold text-cx-nav-ink">{visit.patient_name}</p>
                  <p className="truncate text-[12px] text-cx-text-3">{visit.patient_uhid} · {visit.case_number}</p>
                </div>
                <p className="w-40 truncate text-[12.5px] text-cx-text-2">{visit.practitioner_name}</p>
                <p className="w-32 truncate text-[12.5px] text-cx-text-2">{visit.branch_name}</p>
                <p className="w-28 text-[12.5px] text-cx-text-2">{visit.visit_date}</p>
                <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', VISIT_TONE[visit.visit_status] ?? 'bg-cx-slate-bg text-cx-slate-ink')}>{humanize(visit.visit_status)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      <Pager page={visits} query={{ date: filters.date }} />
    </div>
  );
}
