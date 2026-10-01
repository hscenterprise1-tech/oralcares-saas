import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ClipboardCheck, Mail, Search, UserPlus, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PageHeader } from '@/clinic/page/PageHeader';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { buttonClasses } from '@/clinic/ui/Button';
import type { StaffRow, StaffTab, StaffTypeOption } from './lib/types';
import { Avatar, Chip, relativeTime, statusOf } from './lib/ui';

const TABS: { value: StaffTab | 'all'; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'invited', label: 'Invited' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'archived', label: 'Archived' },
];

/**
 * The clinic's staff: who works here, as what, where, and in what state.
 * Contact details and documents stay on each profile.
 */
export default function StaffIndex({
  employees,
  counts,
  filters,
  staffTypes,
  links,
}: {
  employees: StaffRow[];
  counts: Record<StaffTab, number>;
  filters: { q: string; type: string | null };
  staffTypes: StaffTypeOption[];
  links: { index: string; create: string; show: string; documentSettings: string };
}) {
  const showUrl = (id: number) => links.show.replace('__ID__', String(id));
  const [tab, setTab] = useState<StaffTab | 'all'>(() => {
    const wanted = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('tab');

    return (TABS.some((option) => option.value === wanted) ? wanted : 'active') as StaffTab | 'all';
  });
  const [search, setSearch] = useState(filters.q);
  const first = useRef(true);

  useEffect(() => {
    if (first.current) {
      first.current = false;

      return;
    }
    const timer = window.setTimeout(() => {
      router.get(links.index, { q: search.trim() || undefined, type: filters.type || undefined, tab }, { preserveState: true, replace: true, preserveScroll: true });
    }, 350);

    return () => window.clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const shown = useMemo(() => (tab === 'all' ? employees : employees.filter((employee) => employee.tab === tab)), [employees, tab]);
  const total = Object.values(counts).reduce((sum, value) => sum + value, 0);

  return (
    <ClinicPortalShell title="Staff">
      <PageHeader
        eyebrow="Administration"
        title="Staff"
        description="Doctors, therapists and the team: roles, branches, schedules, leave and documents."
        actions={
          <>
            <Link href={links.documentSettings} className={buttonClasses('secondary', 'md')}>
              <ClipboardCheck className="h-4 w-4" aria-hidden="true" /> Document checklist
            </Link>
            <Link href={links.create} className={buttonClasses('primary', 'md')}>
              <UserPlus className="h-4 w-4" aria-hidden="true" /> Add staff
            </Link>
          </>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div role="tablist" aria-label="Staff status" className="flex max-w-full items-center gap-0.5 overflow-x-auto rounded-full border border-white/70 bg-white/[0.93] p-1 backdrop-blur-sm [scrollbar-width:none]">
          {TABS.map((option) => {
            const count = option.value === 'all' ? total : counts[option.value];

            return (
              <button
                key={option.value}
                type="button"
                role="tab"
                aria-selected={tab === option.value}
                onClick={() => setTab(option.value)}
                className={cn(
                  'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-[12.5px] font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:h-9',
                  tab === option.value ? 'bg-clinic-ink text-white' : 'text-clinic-secondary hover:bg-clinic-inset hover:text-clinic-ink',
                )}
              >
                {option.label}
                <span className={cn('rounded-full px-1.5 text-[11px] tabular-nums', tab === option.value ? 'bg-white/20' : 'bg-clinic-inset')}>{count}</span>
              </button>
            );
          })}
        </div>

        <select
          aria-label="Staff type"
          value={filters.type ?? ''}
          onChange={(event) => router.get(links.index, { q: search.trim() || undefined, type: event.target.value || undefined, tab }, { preserveState: true, preserveScroll: true })}
          className="h-11 min-w-0 rounded-full border border-white/70 bg-white/[0.93] px-4 text-[12.5px] font-semibold text-clinic-ink backdrop-blur-sm focus:outline-none focus:ring-2 focus:ring-clinic-brand/25"
        >
          <option value="">All staff types</option>
          {staffTypes.map((type) => (
            <option key={type.value} value={type.value}>
              {type.label}
            </option>
          ))}
        </select>

        <div className="relative min-w-0 basis-full sm:ml-auto sm:w-[280px] sm:basis-auto">
          <Search className="pointer-events-none absolute left-3.5 top-1/2 z-10 h-4 w-4 -translate-y-1/2 text-clinic-secondary" aria-hidden="true" />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search name, email or mobile"
            aria-label="Search staff"
            className="h-11 w-full rounded-full border border-white/70 bg-white/[0.93] pl-10 pr-10 text-[13px] text-clinic-ink backdrop-blur-sm placeholder:text-clinic-muted focus:border-clinic-brand-strong focus:outline-none focus:ring-2 focus:ring-clinic-brand/25"
          />
          {search && (
            <button type="button" aria-label="Clear search" onClick={() => setSearch('')} className="absolute right-1 top-1/2 z-10 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full text-clinic-secondary hover:bg-clinic-inset">
              <X className="h-4 w-4" aria-hidden="true" />
            </button>
          )}
        </div>
      </div>

      <section aria-label="Staff list" className="overflow-hidden rounded-3xl border border-white/70 bg-white/[0.93] backdrop-blur-sm">
        {shown.length === 0 ? (
          <div className="px-6 py-14 text-center">
            <p className="text-[14px] font-bold text-clinic-ink">No staff here</p>
            <p className="mt-1 text-[12.5px] text-clinic-secondary">{search || filters.type ? 'Nobody matches the search or filter.' : 'Add your first team member to get started.'}</p>
          </div>
        ) : (
          <>
            {/* Wide screens: a compact table. */}
            <table className="hidden w-full text-left md:table">
              <thead>
                <tr className="border-b border-clinic-border text-[11px] font-bold uppercase tracking-wide text-clinic-secondary">
                  <th className="px-5 py-3">Name</th>
                  <th className="px-3 py-3">Role</th>
                  <th className="px-3 py-3">Primary branch</th>
                  <th className="px-3 py-3">Status</th>
                  <th className="hidden px-3 py-3 lg:table-cell">Last activity</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-clinic-border">
                {shown.map((employee) => {
                  const status = statusOf(employee.status);

                  return (
                    <tr key={employee.id} className="group hover:bg-clinic-canvas/70">
                      <td className="px-5 py-3">
                        <Link href={showUrl(employee.id)} className="flex min-w-0 items-center gap-3 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong">
                          <Avatar name={employee.name} url={employee.photo_url} id={employee.id} />
                          <span className="min-w-0">
                            <span className="block truncate text-[13.5px] font-bold text-clinic-ink group-hover:underline">{employee.name}</span>
                            <span className="block truncate text-[11.5px] text-clinic-secondary">
                              {[employee.headline, employee.specialty].filter(Boolean).join(' · ') || employee.staff_type_label || 'Staff'}
                            </span>
                          </span>
                        </Link>
                      </td>
                      <td className="px-3 py-3">
                        <span className="block text-[12.5px] font-semibold text-clinic-ink">{employee.staff_type_label ?? '—'}</span>
                        <span className="block truncate text-[11.5px] text-clinic-secondary">{employee.roles.join(', ') || (employee.has_login ? 'No role' : 'No login')}</span>
                      </td>
                      <td className="px-3 py-3 text-[12.5px] text-clinic-ink">
                        {employee.primary_branch ?? '—'}
                        {employee.branch_count > 1 && <span className="ml-1 text-[11.5px] text-clinic-secondary">+{employee.branch_count - 1}</span>}
                      </td>
                      <td className="px-3 py-3">
                        <span className="flex flex-wrap items-center gap-1">
                          <Chip className={status.chip}>{status.label}</Chip>
                          {employee.invitation_pending && (
                            <Chip className="bg-sky-50 text-sky-700 ring-sky-200">
                              <Mail className="h-3 w-3" aria-hidden="true" /> Invited
                            </Chip>
                          )}
                        </span>
                      </td>
                      <td className="hidden px-3 py-3 text-[12px] text-clinic-secondary lg:table-cell">{employee.last_activity ? relativeTime(employee.last_activity) : '—'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>

            {/* Phones: one card per person. */}
            <ul className="divide-y divide-clinic-border md:hidden">
              {shown.map((employee) => {
                const status = statusOf(employee.status);

                return (
                  <li key={employee.id}>
                    <Link href={showUrl(employee.id)} className="flex min-h-[64px] items-center gap-3 px-4 py-3 focus-visible:bg-clinic-canvas focus-visible:outline-none">
                      <Avatar name={employee.name} url={employee.photo_url} id={employee.id} size={44} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13.5px] font-bold text-clinic-ink">{employee.name}</span>
                        <span className="block truncate text-[11.5px] text-clinic-secondary">
                          {[employee.staff_type_label, employee.specialty, employee.primary_branch].filter(Boolean).join(' · ')}
                        </span>
                      </span>
                      <Chip className={status.chip}>{status.label}</Chip>
                    </Link>
                  </li>
                );
              })}
            </ul>
          </>
        )}
      </section>
    </ClinicPortalShell>
  );
}
