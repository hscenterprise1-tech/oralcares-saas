import {
  CrmShell,
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmValue,
  CrmHairlineBar,
  CrmButton,
} from '../components';
import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Badge } from '../components/CrmBadgePrimitives';
import { Button } from '../components/CrmFormPrimitives';
import { Users, Plus, Shuffle, MapPin, Pencil, Trash2, UserCheck } from 'lucide-react';
import { TeamFormModal } from './components/TeamFormModal';
import { Team, TeamsIndexProps } from './types';
import { cn } from '@/lib/utils';
import {
  CrmFilterBar,
  CrmSection,
  CrmTable,
  useCrmConfirm,
  type CrmColumn,
  type CrmFilterDef,
} from '../components';

/**
 * Sales Teams.
 *
 * Rebuilt from gradient cards into the standard CRM table: teams are records
 * to be compared — members, load, pipeline — and a table compares far better
 * than a grid of cards. Deleting now goes through the shared confirm dialog
 * rather than the browser's native prompt.
 */

const currency = (value: number): string =>
  `₹${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(value)}`;

const STATUS_OPTIONS = [
  { value: 'all', label: 'All teams' },
  { value: 'active', label: 'Active' },
  { value: 'inactive', label: 'Inactive' },
];

const STRATEGY_OPTIONS = [
  { value: 'all', label: 'All strategies' },
  { value: 'round_robin', label: 'Round-robin' },
  { value: 'territory', label: 'State-wise' },
];

/**
 * How a team decides which rep gets the next lead. These are the values the
 * team form writes, so the split below is a real breakdown rather than a
 * guess at one.
 */
const STRATEGY_SPLIT = [
  { key: 'round_robin', label: 'Round robin', hue: '#2F6BE4', caption: 'Leads cycle through the members' },
  { key: 'territory', label: 'State-wise', hue: '#3E93A3', caption: 'Routed by the lead’s state' },
] as const;

export default function TeamsIndex({
  teams = [],
  assignableUsers = [],
  can = { manage: false, view_performance: false },
}: TeamsIndexProps) {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingTeam, setEditingTeam] = useState<Team | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('all');
  const [strategy, setStrategy] = useState('all');
  const { confirm, dialog } = useCrmConfirm();

  const openCreate = () => {
    setEditingTeam(null);
    setIsModalOpen(true);
  };

  const openEdit = (team: Team) => {
    setEditingTeam(team);
    setIsModalOpen(true);
  };

  const confirmDelete = (team: Team) =>
    confirm({
      title: `Remove ${team.name}?`,
      description:
        'Leads already assigned to its members stay with them, but this team will no longer receive auto-assigned leads. This cannot be undone.',
      confirmLabel: 'Remove team',
      destructive: true,
      onConfirm: () =>
        router.delete(route('company.crm.teams.destroy', team.id), { preserveScroll: true }),
    });

  const filters: CrmFilterDef[] = [
    { key: 'status', label: 'Status', value: status, options: STATUS_OPTIONS },
    { key: 'strategy', label: 'Strategy', value: strategy, options: STRATEGY_OPTIONS },
  ];

  const setFilter = (key: string, value: string) => {
    if (key === 'status') setStatus(value);
    if (key === 'strategy') setStrategy(value);
  };

  const clearAll = () => {
    setSearch('');
    setStatus('all');
    setStrategy('all');
  };

  const visibleTeams = useMemo(() => {
    const term = search.trim().toLowerCase();

    return teams.filter((team) => {
      if (term && !team.name.toLowerCase().includes(term)) return false;
      if (status === 'active' && !team.is_active) return false;
      if (status === 'inactive' && team.is_active) return false;
      if (strategy !== 'all' && team.assignment_strategy !== strategy) return false;

      return true;
    });
  }, [teams, search, status, strategy]);

  const isFiltered = search.trim() !== '' || status !== 'all' || strategy !== 'all';
  const totalReps = new Set(teams.flatMap((team) => team.members.map((m) => m.id))).size;
  const unmanagedTeams = teams.filter((team) => !team.manager).length;

  const columns: CrmColumn<Team>[] = [
    {
      key: 'team',
      header: 'Team',
      primary: true,
      cell: (team) => (
        <div className="min-w-0">
          <p className="truncate font-medium text-ovs-crm-text-primary">{team.name}</p>
          {team.description && (
            <p className="truncate text-[11.5px] text-ovs-crm-text-secondary">{team.description}</p>
          )}
        </div>
      ),
    },
    {
      key: 'manager',
      header: 'Manager',
      cell: (team) =>
        team.manager ? (
          <span className="truncate text-ovs-crm-text-primary">{team.manager.name}</span>
        ) : (
          <span className="text-ovs-crm-text-secondary">Unassigned</span>
        ),
    },
    {
      key: 'members',
      header: 'Members',
      align: 'right',
      cell: (team) => <span className="tabular-nums text-ovs-crm-text-primary">{team.member_count}</span>,
    },
    {
      key: 'strategy',
      header: 'Assignment',
      hideOnMobile: true,
      cell: (team) => (
        <span className="inline-flex items-center gap-1.5 text-ovs-crm-text-secondary">
          {team.assignment_strategy === 'round_robin' ? (
            <><Shuffle className="h-3.5 w-3.5" aria-hidden="true" /> Round-robin</>
          ) : (
            <><MapPin className="h-3.5 w-3.5" aria-hidden="true" /> State-wise</>
          )}
        </span>
      ),
    },
    {
      key: 'open',
      header: 'Active Leads',
      align: 'right',
      cell: (team) =>
        team.workload ? (
          <span className="tabular-nums text-ovs-crm-text-primary">{team.workload.open_leads}</span>
        ) : (
          <span className="text-ovs-crm-text-muted">—</span>
        ),
    },
    {
      key: 'pipeline',
      header: 'Pipeline',
      align: 'right',
      hideOnMobile: true,
      cell: (team) =>
        team.workload ? (
          <span className="tabular-nums text-ovs-crm-text-primary">
            {currency(team.workload.open_pipeline_value)}
          </span>
        ) : (
          <span className="text-ovs-crm-text-muted">—</span>
        ),
    },
    {
      key: 'overdue',
      header: 'Overdue',
      align: 'right',
      hideOnMobile: true,
      cell: (team) =>
        team.workload ? (
          <span
            className={
              team.workload.overdue_followups > 0
                ? 'font-medium tabular-nums text-ovs-crm-danger-text'
                : 'tabular-nums text-ovs-crm-text-primary'
            }
          >
            {team.workload.overdue_followups}
          </span>
        ) : (
          <span className="text-ovs-crm-text-muted">—</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (team) => (
        <Badge variant={team.is_active ? 'success' : 'neutral'} size="compact">
          {team.is_active ? 'Active' : 'Inactive'}
        </Badge>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">Actions</span>,
      align: 'right',
      hideOnMobile: true,
      cell: (team) =>
        can.manage ? (
          <div className="flex items-center justify-end gap-1">
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                openEdit(team);
              }}
              aria-label={`Edit ${team.name}`}
              className="flex h-7 w-7 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition-colors hover:border-ovs-crm-border-strong hover:text-ovs-crm-text-primary"
            >
              <Pencil className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                confirmDelete(team);
              }}
              aria-label={`Remove ${team.name}`}
              className="flex h-7 w-7 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition-colors hover:border-ovs-crm-danger-border hover:text-ovs-crm-danger-text"
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        ) : null,
    },
  ];

  return (
    <CrmShell title="Sales Teams">
      <CrmPageHead
        eyebrow="Admin"
        title="Sales Teams"
        stats={
          teams.length > 0 ? (
            <>
              <CrmPageStat icon={Users} value={teams.length} caption={`Team${teams.length === 1 ? '' : 's'}`} />
              {totalReps > 0 && (
                <CrmPageStat icon={UserCheck} value={totalReps} caption="Reps covered by a team" />
              )}
              {unmanagedTeams > 0 && (
                <CrmPageStat
                  icon={MapPin}
                  value={unmanagedTeams}
                  chip="no manager"
                  chipHue="#E0B21E"
                  caption="Nobody accountable"
                />
              )}
            </>
          ) : undefined
        }
        actions={
          can.manage ? (
            <CrmButton tone="primary" icon={Plus} onClick={openCreate}>
              Create team
            </CrmButton>
          ) : undefined
        }
      />


      <div className="space-y-4 pb-10">
        {/*
          * How leads reach a rep. The strip used to report four unrelated
          * counts; the only one that told you anything actionable was
          * "without manager", which is now in the masthead. What is left is
          * the thing a sales lead actually adjusts: the split of assignment
          * strategies across teams.
          */}
        <div
          className={cn(
            'grid grid-cols-1 gap-4 sm:grid-cols-2',
            teams.length === 0 && 'hidden'
          )}
        >
          {STRATEGY_SPLIT.map((strategy, index) => {
            const count = teams.filter((team) => team.assignment_strategy === strategy.key).length;

            return (
              <CrmCard key={strategy.key} step={index}>
                <div className="mb-3 flex items-start justify-between gap-2">
                  <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                    {strategy.label}
                  </p>
                  <span
                    className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                    style={{ backgroundColor: strategy.hue }}
                    aria-hidden="true"
                  />
                </div>
                <CrmValue value={count} size="minor" />
                <p className="mt-1.5 text-[11.5px] text-ovs-crm-text-muted">{strategy.caption}</p>
                <CrmHairlineBar
                  className="mt-3"
                  value={count}
                  max={Math.max(teams.length, 1)}
                  color={strategy.hue}
                />
              </CrmCard>
            );
          })}
        </div>

        <CrmFilterBar
          searchValue={search}
          onSearchChange={setSearch}
          searchPlaceholder="Search teams…"
          filters={filters}
          onFilterChange={setFilter}
          onClearAll={clearAll}
          resultCount={visibleTeams.length}
          resultNoun="team"
        />

        <CrmSection flush>
          <CrmTable
            columns={columns}
            rows={visibleTeams}
            rowKey={(team) => team.id}
            onRowClick={(team) => router.visit(route('company.crm.teams.show', team.id))}
            isFiltered={isFiltered}
            empty={{
              icon: Users,
              title: 'No sales teams yet',
              description:
                'Create a team, add reps, and pick round-robin or state-wise distribution — new leads start auto-assigning immediately. Until then they stay unassigned for manual pickup.',
              action: can.manage ? (
                <Button size="sm" onClick={openCreate} className="gap-1.5">
                  <Plus className="h-4 w-4" aria-hidden="true" /> Create Your First Team
                </Button>
              ) : undefined,
            }}
            emptyFiltered={{
              title: 'No teams match these filters',
              description: 'Try a different status or strategy.',
              action: (
                <Button size="sm" variant="secondary" onClick={clearAll}>
                  Clear filters
                </Button>
              ),
            }}
          />
        </CrmSection>
      </div>

      <TeamFormModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        team={editingTeam}
        assignableUsers={assignableUsers}
      />
      {dialog}
    </CrmShell>
  );
}
