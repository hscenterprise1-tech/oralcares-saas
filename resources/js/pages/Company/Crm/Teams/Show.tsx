import {
  CrmShell,
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmCardIcon,
  CrmValue,
  CrmRing,
  CrmHairlineBar,
  CrmIconButton,
} from '../components';
import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Card, CardTitle } from '../components/CrmCardPrimitives';
import { Badge } from '../components/CrmBadgePrimitives';
import { Button } from '../components/CrmFormPrimitives';
import {
  Table,
  TableHeader,
  TableBody,
  TableHead,
  TableRow,
  TableCell,
} from '../components/CrmDataTable';
import { ArrowLeft, Briefcase, MapPin, Power, Shuffle, UserCheck, UserMinus, UserPlus, Users } from 'lucide-react';
import { TeamShowProps } from './types';
import { useCrmConfirm } from '../components';

const currency = (value: number): string =>
  `₹${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(value)}`;

/** null means "not measurable yet", which must not read as a zero. */
const orNoData = (value: number | null, suffix = ''): React.ReactNode =>
  value === null ? <span className="text-ovs-crm-text-secondary">No data</span> : <>{value}{suffix}</>;

export default function TeamShow({ team, assignableUsers, memberPerformance, can }: TeamShowProps) {
  const [selectedUserId, setSelectedUserId] = useState('');
  const { confirm, dialog } = useCrmConfirm();

  const memberIds = new Set(team.members.map((m) => m.id));
  const addableUsers = assignableUsers.filter((u) => !memberIds.has(u.id));

  const addMember = () => {
    if (!selectedUserId) return;
    router.post(
      route('company.crm.teams.members.store', team.id),
      { user_id: Number(selectedUserId) },
      { preserveScroll: true, onSuccess: () => setSelectedUserId('') },
    );
  };

  const removeMember = (userId: number, name: string) =>
    confirm({
      title: `Remove ${name} from ${team.name}?`,
      description:
        'Their existing leads stay assigned to them — only auto-assignment from this team stops. If they are the team manager, the team is left without one.',
      confirmLabel: 'Remove member',
      destructive: true,
      onConfirm: () =>
        router.delete(route('company.crm.teams.members.destroy', [team.id, userId]), {
          preserveScroll: true,
        }),
    });

  const toggleActive = () => {
    router.post(route('company.crm.teams.toggle-active', team.id), {}, { preserveScroll: true });
  };

  return (
    <CrmShell title={team.name}>
      <CrmIconButton
        icon={ArrowLeft}
        label="Back to teams"
        tone="ghost"
        href={route('company.crm.teams.index')}
        className="mb-2"
      />

      <CrmPageHead
        eyebrow="Sales"
        title={team.name}
        stats={
          team.workload ? (
            <>
              <CrmPageStat
                icon={Users}
                value={team.workload.member_count}
                caption="Reps on this team"
              />
              <CrmPageStat
                icon={Briefcase}
                value={team.workload.open_leads}
                chip={
                  team.workload.overdue_followups > 0
                    ? `${team.workload.overdue_followups} overdue`
                    : undefined
                }
                chipHue="#EF5B39"
                caption="Open leads they are carrying"
              />
            </>
          ) : undefined
        }
        actions={
          can.manage && (
            <Button size="sm" variant="secondary" onClick={toggleActive} className="gap-1.5">
              <Power className="w-4 h-4" /> {team.is_active ? 'Deactivate' : 'Activate'}
            </Button>
          )
        }
      />


      <div className="space-y-6 pb-12">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={team.is_active ? 'success' : 'neutral'} size="compact">
            {team.is_active ? 'Active' : 'Inactive'}
          </Badge>
          <Badge variant="indigo" size="compact" className="gap-1">
            {team.assignment_strategy === 'round_robin' ? (
              <><Shuffle className="w-3 h-3" /> Round-robin</>
            ) : (
              <><MapPin className="w-3 h-3" /> State-wise</>
            )}
          </Badge>
          <Badge variant={team.manager ? 'indigo' : 'warning'} size="compact" className="gap-1">
            <UserCheck className="w-3 h-3" />
            {team.manager ? team.manager.name : 'No manager assigned'}
          </Badge>
        </div>

        {team.workload && (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
            <CrmCard step={0} className="lg:col-span-7">
              <div className="mb-6 flex items-start justify-between gap-4">
                <div>
                  <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                    Open pipeline
                  </p>
                  <CrmValue value={currency(team.workload.open_pipeline_value)} size="hero" />
                  <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                    Annualised, across {team.workload.open_leads} open lead
                    {team.workload.open_leads === 1 ? '' : 's'}
                  </p>
                </div>
                <CrmCardIcon icon={Briefcase} hue="#2F6BE4" />
              </div>

              {/*
                * Load per person. A team's pipeline only means something next to
                * how many people are carrying it, and the old strip buried that
                * in a caption under an unrelated figure.
                */}
              <div className="border-t border-ovs-crm-border pt-4">
                <div className="mb-1.5 flex items-baseline justify-between gap-3">
                  <span className="text-[12px] font-medium text-ovs-crm-text-secondary">
                    Open leads per rep
                  </span>
                  <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                    {team.workload.average_open_leads_per_member ?? '—'}
                  </span>
                </div>
                <CrmHairlineBar
                  value={team.workload.overdue_followups}
                  max={Math.max(team.workload.open_leads, 1)}
                  color="#EF5B39"
                  tooltip={`${team.workload.overdue_followups} of ${team.workload.open_leads} open leads have an overdue follow-up`}
                />
                <p className="mt-1.5 text-[11.5px] text-ovs-crm-text-muted">
                  {team.workload.overdue_followups === 0
                    ? 'Nothing overdue'
                    : `${team.workload.overdue_followups} with an overdue follow-up`}
                </p>
              </div>
            </CrmCard>

            <CrmCard step={1} className="flex flex-col items-center lg:col-span-5">
              <div className="mb-4 w-full">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Win rate
                </h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">All the deals this team has closed</p>
              </div>
              <CrmRing
                value={team.workload.won}
                max={Math.max(team.workload.won + team.workload.lost, 1)}
                color="#2FA96B"
                label={`${
                  team.workload.won + team.workload.lost > 0
                    ? Math.round((team.workload.won / (team.workload.won + team.workload.lost)) * 100)
                    : 0
                }%`}
                caption={`${team.workload.won} won · ${team.workload.lost} lost`}
                size={150}
              />
            </CrmCard>
          </div>
        )}

        {/* Roster */}
        <Card className="p-5">
          <div className="mb-4 flex items-center justify-between gap-3">
            <CardTitle className="text-[15px]">Members ({team.members.length})</CardTitle>
            {can.manage && addableUsers.length > 0 && (
              <div className="flex items-center gap-2">
                <select
                  value={selectedUserId}
                  onChange={(e) => setSelectedUserId(e.target.value)}
                  className="rounded-[16px] border border-ovs-crm-border bg-white px-2.5 py-1.5 text-[13px] text-ovs-crm-text-primary"
                  aria-label="Select a user to add"
                >
                  <option value="">Add member…</option>
                  {addableUsers.map((u) => (
                    <option key={u.id} value={u.id}>{u.name}</option>
                  ))}
                </select>
                <Button size="sm" onClick={addMember} disabled={!selectedUserId} className="gap-1.5">
                  <UserPlus className="w-4 h-4" /> Add
                </Button>
              </div>
            )}
          </div>

          {team.members.length === 0 ? (
            <p className="text-[13px] text-ovs-crm-text-secondary">
              No members yet. Until a rep is added, this team receives no auto-assigned leads.
            </p>
          ) : (
            <div className="space-y-2">
              {team.members.map((member) => (
                <div
                  key={member.id}
                  className="flex items-center justify-between gap-3 rounded-[16px] border border-ovs-crm-border px-3 py-2"
                >
                  <div className="min-w-0">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {member.name}
                      {team.manager?.id === member.id && (
                        <Badge variant="indigo" size="compact" className="ml-2">Manager</Badge>
                      )}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-secondary">{member.email}</p>
                  </div>
                  {can.manage && (
                    <button
                      type="button"
                      onClick={() => removeMember(member.id, member.name)}
                      className="flex h-7 w-7 items-center justify-center rounded-full border border-ovs-crm-border text-ovs-crm-text-secondary transition-colors hover:bg-ovs-crm-danger-soft hover:text-ovs-crm-danger"
                      aria-label={`Remove ${member.name}`}
                    >
                      <UserMinus className="h-3.5 w-3.5" />
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>

        {/* Performance */}
        {memberPerformance && (
          <Card className="p-5">
            <CardTitle className="mb-4 text-[15px]">Member Performance</CardTitle>
            {memberPerformance.length === 0 ? (
              <p className="text-[13px] text-ovs-crm-text-secondary">No member of this team holds an assigned lead yet.</p>
            ) : (
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Member</TableHead>
                      <TableHead className="text-right">Assigned</TableHead>
                      <TableHead className="text-right">Demos</TableHead>
                      <TableHead className="text-right">Trials</TableHead>
                      <TableHead className="text-right">Quotes</TableHead>
                      <TableHead className="text-right">Won</TableHead>
                      <TableHead className="text-right">Lost</TableHead>
                      <TableHead className="text-right">Conv.</TableHead>
                      <TableHead className="text-right">Cycle</TableHead>
                      <TableHead className="text-right">Overdue</TableHead>
                      <TableHead className="text-right">Weighted</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {memberPerformance.map((row) => (
                      <TableRow key={row.user_id}>
                        <TableCell className="font-medium text-ovs-crm-text-primary">{row.name}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.leads_assigned}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.demos_completed}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.trials_active}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.quotations_sent}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.wins}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.losses}</TableCell>
                        <TableCell className="text-right tabular-nums">{orNoData(row.conversion_rate, '%')}</TableCell>
                        <TableCell className="text-right tabular-nums">{orNoData(row.average_cycle_time_days, 'd')}</TableCell>
                        <TableCell className="text-right tabular-nums">{row.overdue_followups}</TableCell>
                        <TableCell className="text-right tabular-nums">{currency(row.active_weighted_pipeline)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}
          </Card>
        )}
      </div>
      {dialog}
    </CrmShell>
  );
}
