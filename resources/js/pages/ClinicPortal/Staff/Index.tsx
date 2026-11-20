import { useMemo, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ClipboardCheck, Plus, Search, UserCog } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { ButtonLink } from '@/clinic/ui/Button';
import { Input, Select } from '@/clinic/ui/controls';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { EmptyState } from '@/clinic/ui/states';
import { PillTabs } from '@/clinic/ui/Tabs';
import { humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

type TabKey = 'active' | 'invited' | 'inactive' | 'suspended' | 'archived';

interface StaffRow {
  id: number;
  name: string;
  photo_url: string | null;
  roles: string[];
  staff_type: string | null;
  staff_type_label: string | null;
  primary_branch: string | null;
  branch_count: number;
  status: string | null;
  tab: TabKey;
  is_practitioner: boolean;
  specialty: string | null;
  headline: string | null;
  has_login: boolean;
  invitation_pending: boolean;
  last_activity: string | null;
}

/** `EmployeeController::index`: the server is the contract. */
interface Props {
  employees: StaffRow[];
  counts: Record<TabKey, number>;
  filters: { q: string; type: string | null };
  staffTypes: { value: string; label: string; clinical: boolean }[];
  links: { index: string; create: string; show: string; documentSettings: string };
}

const TABS: { key: TabKey; label: string }[] = [
  { key: 'active', label: 'Active' },
  { key: 'invited', label: 'Invited' },
  { key: 'inactive', label: 'Inactive' },
  { key: 'suspended', label: 'Suspended' },
  { key: 'archived', label: 'Archived' },
];

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';

export default function StaffIndex(props: Props) {
  return (
    <ClinicPortalShell title="Staff">
      <StaffPage {...props} />
    </ClinicPortalShell>
  );
}

/** The clinic's team: who works here, in what role and where, and whether they can sign in yet. */
function StaffPage({ employees, counts, staffTypes, links }: Props) {
  const [tab, setTab] = useState<TabKey>('active');
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const href = (id: number) => links.show.replace('__ID__', String(id));

  const shown = useMemo(
    () =>
      employees.filter(
        (row) =>
          row.tab === tab &&
          (type === '' || row.staff_type === type) &&
          (search.trim() === '' || `${row.name} ${row.staff_type_label ?? ''} ${row.specialty ?? ''}`.toLowerCase().includes(search.trim().toLowerCase())),
      ),
    [employees, tab, type, search],
  );

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={UserCog}
        title="Staff"
        description="Everyone who works at the clinic: roles, branches and sign-in."
        actions={
          <>
            <ButtonLink href={links.documentSettings}>
              <ClipboardCheck className="h-4 w-4" aria-hidden="true" /> Document checklist
            </ButtonLink>
            <ButtonLink href={links.create} tone="primary">
              <Plus className="h-4 w-4" aria-hidden="true" /> Add staff
            </ButtonLink>
          </>
        }
      />

      <div className={cn(SURFACE, 'mb-4 flex flex-wrap items-center gap-3 p-4')}>
        <PillTabs label="Staff status" value={tab} onChange={setTab} options={TABS.map((t) => ({ key: t.key, label: t.label, count: counts[t.key] ?? 0 }))} />
        <div className="relative ml-auto min-w-[220px] flex-1 sm:max-w-xs">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-cx-text-3" aria-hidden="true" />
          <Input aria-label="Search staff" placeholder="Search by name or role" value={search} onChange={(event) => setSearch(event.target.value)} className="pl-9" />
        </div>
        <div className="w-full sm:w-48">
          <Select
            aria-label="Staff type"
            value={type}
            onChange={(event) => setType(event.target.value)}
            options={[{ value: '', label: 'All types' }, ...staffTypes.map((t) => ({ value: t.value, label: t.label }))]}
          />
        </div>
      </div>

      {shown.length === 0 ? (
        <EmptyState
          title={employees.length === 0 ? 'No staff yet' : 'Nobody here'}
          description={employees.length === 0 ? 'Add your doctors and desk staff, then invite them to sign in.' : 'Nobody matches this tab and these filters.'}
          action={employees.length === 0 ? <ButtonLink href={links.create} tone="primary">Add staff</ButtonLink> : undefined}
        />
      ) : (
        <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
          {shown.map((row) => (
            <li key={row.id}>
              <Link href={href(row.id)} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 hover:bg-cx-slate-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#1769AA]/35">
                {row.photo_url ? (
                  <img src={row.photo_url} alt="" className="h-10 w-10 shrink-0 rounded-full object-cover" />
                ) : (
                  <PersonAvatar name={row.name} />
                )}
                <div className="min-w-0 flex-1 basis-52">
                  <p className="truncate text-[14px] font-semibold text-cx-nav-ink">{row.name}</p>
                  <p className="truncate text-[12px] text-cx-text-3">
                    {[row.staff_type_label, row.headline ?? row.specialty].filter(Boolean).join(' · ') || 'No role set'}
                  </p>
                </div>
                <div className="flex min-w-0 flex-wrap items-center gap-1.5 sm:w-56">
                  {row.roles.length === 0 ? (
                    <span className="text-[12px] text-cx-text-3">No role</span>
                  ) : (
                    row.roles.map((role) => (
                      <span key={role} className="rounded-full bg-cx-slate-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-slate-ink">
                        {humanize(role)}
                      </span>
                    ))
                  )}
                </div>
                <p className="w-36 truncate text-[12.5px] text-cx-text-2">
                  {row.primary_branch ?? 'No branch'}
                  {row.branch_count > 1 ? ` +${row.branch_count - 1}` : ''}
                </p>
                <SignInChip row={row} />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function SignInChip({ row }: { row: StaffRow }) {
  const [label, tone] = row.has_login
    ? ['Can sign in', 'bg-cx-green-bg text-cx-green-ink']
    : row.invitation_pending
      ? ['Invitation sent', 'bg-cx-amber-bg text-cx-amber-ink']
      : ['No login', 'bg-cx-slate-bg text-cx-slate-ink'];

  return <span className={cn('w-28 rounded-full px-2.5 py-0.5 text-center text-[11.5px] font-semibold', tone)}>{label}</span>;
}
