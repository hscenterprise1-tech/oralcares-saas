import React from 'react';
import { Head, router } from '@inertiajs/react';
import {
  AlertTriangle,
  Ban,
  CircleSlash,
  GitBranch,
  History,
  Layers,
  Play,
  ShieldAlert,
  Timer,
  Workflow,
  type LucideIcon,
} from 'lucide-react';
import {
  AdministrationShell,
  CrmCard,
  CrmCardIcon,
  CrmFormField,
  CrmInput,
  CrmPageStat,
  CrmStatusPill,
  CrmValue,
  type CrmStatusTone,
} from '@/pages/Company/design';

/**
 * Company → Workflow administration
 *
 * Read-only. The engine is the authoritative one and is reused unchanged;
 * nothing here starts, moves, completes or cancels a run.
 *
 * The page's job is the middle tab: a workflow can be saved, look complete, and
 * be unable to do what it appears to do — no stages to start from, no
 * transitions to move along, a stage nothing reaches. None of that was visible
 * anywhere before.
 */

const HUE = {
  green: '#2FA96B',
  blue: '#2F6BE4',
  violet: '#7C6FE0',
  orange: '#F59245',
  coral: '#EF5B39',
  slate: '#64748B',
} as const;

/* The rail is Administration's, from COMPANY_MODULES — not a private three-item menu. */

interface DefinitionRef {
  definition_id: number;
  name: string;
  clinic_id: number;
  is_active: boolean;
}

interface StageRef {
  stage: string;
  definition: string | null;
  definition_id: number;
}

interface Props {
  definitions: Array<{
    id: number;
    name: string;
    description: string | null;
    version: string;
    is_active: boolean;
    clinic_id: number;
    clinic: string | null;
    branch_id: number | null;
    branch: string | null;
    stages: number;
    transitions: number;
    runs: Record<string, number>;
    runs_total: number;
    created_at: string | null;
  }>;
  integrity: {
    no_stages: DefinitionRef[];
    no_transitions: DefinitionRef[];
    unreachable_stages: StageRef[];
    dead_end_stages: StageRef[];
    unenforced_validation_rules: Array<{
      transition_id: number;
      definition: string | null;
      definition_id: number;
      rules: unknown;
    }>;
    duplicate_active: Array<{ clinic_id: number; branch_id: number | null; names: string[] }>;
    foreign_branch: Array<{
      definition_id: number;
      name: string;
      clinic_id: number;
      branch_id: number;
      branch_clinic_id: number;
    }>;
    total: number;
  };
  runs: {
    by_status: Record<string, number>;
    total: number;
    longest_still: Array<{
      id: number;
      encounter_id: number;
      definition: string | null;
      definition_id: number;
      clinic: string | null;
      stage: string | null;
      still_for_hours: number | null;
      last_moved_at: string | null;
      started_at: string | null;
    }>;
  };
  history: Array<{
    id: number;
    run_id: number;
    action: string | null;
    from: string | null;
    to: string | null;
    performer: string | null;
    reason: string | null;
    at: string;
  }>;
  unsupported: {
    statuses_in_use: string[];
    has_failure_state: boolean;
    event_listeners: Record<string, number>;
    performer_scope: string;
  };
  filters: { search: string | null };
}

/** A definition that cannot start or cannot move is not "inactive" — it is broken. */
function definitionTone(stages: number, transitions: number, isActive: boolean): CrmStatusTone {
  if (stages === 0) return 'bad';
  if (stages > 1 && transitions === 0) return 'bad';

  return isActive ? 'good' : 'neutral';
}

function definitionLabel(stages: number, transitions: number, isActive: boolean): string {
  if (stages === 0) return 'No stages';
  if (stages > 1 && transitions === 0) return 'No transitions';

  return isActive ? 'Active' : 'Switched off';
}

export default function WorkflowWorkspace({
  definitions,
  integrity,
  runs,
  history,
  unsupported,
  filters,
}: Props) {
  const [tab, setTab] = React.useState<'definitions' | 'integrity' | 'runs'>('definitions');

  const active = definitions.filter((d) => d.is_active).length;
  const listenerTotal = Object.values(unsupported.event_listeners).reduce((sum, n) => sum + n, 0);

  return (
    <AdministrationShell title="Workflows">
      <Head title="Workflow administration | Company" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Company</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Workflows
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            What the workflow engine has been configured to do, and whether it can do it
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Workflow} value={`${active}/${definitions.length}`} caption="Definitions active" />
          <CrmPageStat icon={Play} value={runs.by_status.active ?? 0} caption="Runs in progress" />
          <CrmPageStat icon={AlertTriangle} value={integrity.total} caption="Configuration faults" />
          <CrmPageStat icon={History} value={history.length} caption="Recent movements" />
        </div>
      </div>

      {/*
        Said once, at the top: HQ can look and cannot act. The schema is the
        reason, not a permission — a history entry names a clinic employee as
        its performer, so an HQ user cannot be recorded as having moved anything.
      */}
      <CrmCard className="mb-4 flex flex-wrap items-start gap-3 px-5 py-4" frostHue={HUE.slate}>
        <CrmCardIcon icon={CircleSlash} hue={HUE.slate} />
        <div className="min-w-0 flex-1">
          <p className="text-[13px] font-semibold text-ovs-crm-text-primary">
            HQ oversees these workflows and does not author them
          </p>
          <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
            A definition belongs to a clinic, a run to a patient encounter, and every history entry records the person
            who acted as a row in{' '}
            <code className="rounded bg-ovs-crm-surface-inset px-1 py-0.5 text-[11px]">
              {unsupported.performer_scope}
            </code>
            . There is no shape in which an HQ user can move a case, so this page reads and reports rather than
            offering buttons that could not record who pressed them.
          </p>
        </div>
      </CrmCard>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div
          role="tablist"
          aria-label="Workflow view"
          className="flex flex-wrap items-center gap-1 rounded-full bg-ovs-crm-surface-inset p-1"
        >
          {(
            [
              ['definitions', 'Definitions'],
              ['integrity', `Configuration faults${integrity.total > 0 ? ` (${integrity.total})` : ''}`],
              ['runs', 'Runs & history'],
            ] as const
          ).map(([key, label]) => (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={tab === key}
              onClick={() => setTab(key)}
              className={
                tab === key
                  ? 'rounded-full bg-ovs-crm-ink px-3.5 py-1.5 text-[12px] font-semibold text-white transition-colors'
                  : 'rounded-full px-3.5 py-1.5 text-[12px] font-semibold text-ovs-crm-text-secondary transition-colors hover:text-ovs-crm-text-primary'
              }
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {tab === 'definitions' && (
        <>
          <CrmCard className="mb-4 flex flex-wrap items-end gap-3 px-5 py-3.5">
            <div className="min-w-[240px] flex-1">
              <CrmFormField label="Find a workflow">
                <CrmInput
                  type="search"
                  placeholder="Workflow name"
                  defaultValue={filters.search ?? ''}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      router.get(
                        '/company/workflows',
                        { search: (e.target as HTMLInputElement).value || undefined },
                        { preserveState: true, replace: true },
                      );
                    }
                  }}
                />
              </CrmFormField>
            </div>
          </CrmCard>

          {definitions.length === 0 ? (
            <CrmCard className="p-6">
              <div className="py-10 text-center">
                <p className="text-[13px] text-ovs-crm-text-secondary">No workflow has been defined yet.</p>
                <p className="mt-1 text-[12px] text-ovs-crm-text-muted">
                  Definitions are authored per clinic, in the clinic that will run them.
                </p>
              </div>
            </CrmCard>
          ) : (
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
              {definitions.map((definition) => (
                <CrmCard key={definition.id} className="p-5">
                  <div className="flex items-start gap-3">
                    <CrmCardIcon icon={Workflow} hue={definition.is_active ? HUE.violet : HUE.slate} />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[13.5px] font-semibold text-ovs-crm-text-primary">
                          {definition.name}
                        </span>
                        <CrmStatusPill
                          tone={definitionTone(definition.stages, definition.transitions, definition.is_active)}
                        >
                          {definitionLabel(definition.stages, definition.transitions, definition.is_active)}
                        </CrmStatusPill>
                      </div>
                      <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                        v{definition.version} · {definition.clinic ?? `clinic ${definition.clinic_id}`}
                        {definition.branch ? <> · {definition.branch}</> : <> · all branches</>}
                      </p>
                    </div>
                  </div>

                  {definition.description && (
                    <p className="mt-3 text-[12px] text-ovs-crm-text-secondary">{definition.description}</p>
                  )}

                  <dl className="mt-4 grid grid-cols-3 gap-x-4">
                    {[
                      { label: 'Stages', value: definition.stages, icon: Layers },
                      { label: 'Transitions', value: definition.transitions, icon: GitBranch },
                      { label: 'Runs', value: definition.runs_total, icon: Play },
                    ].map((cell) => (
                      <div key={cell.label}>
                        <dt className="text-[10.5px] font-semibold uppercase tracking-[0.1em] text-ovs-crm-text-muted">
                          {cell.label}
                        </dt>
                        <dd className="mt-0.5 text-[16px] font-semibold tabular-nums text-ovs-crm-text-primary">
                          {cell.value}
                        </dd>
                      </div>
                    ))}
                  </dl>

                  {definition.runs_total > 0 && (
                    <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3">
                      {Object.entries(definition.runs).map(([status, count]) => (
                        <CrmStatusPill
                          key={status}
                          tone={status === 'active' ? 'good' : status === 'cancelled' ? 'warn' : 'neutral'}
                        >
                          {count} {status}
                        </CrmStatusPill>
                      ))}
                    </div>
                  )}
                </CrmCard>
              ))}
            </div>
          )}
        </>
      )}

      {tab === 'integrity' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <FaultCard
            title="Cannot start"
            note="No stages, so there is nothing to start from. The engine refuses these."
            hue={HUE.coral}
            icon={Ban}
            items={integrity.no_stages.map((row) => ({
              key: `nostage-${row.definition_id}`,
              primary: row.name,
              secondary: `Definition ${row.definition_id} · clinic ${row.clinic_id}${row.is_active ? ' · switched on' : ''}`,
            }))}
          />

          <FaultCard
            title="Cannot move"
            note="Stages exist and no transition connects any of them, so a case starts and stays where it started."
            hue={HUE.coral}
            icon={CircleSlash}
            items={integrity.no_transitions.map((row) => ({
              key: `notrans-${row.definition_id}`,
              primary: row.name,
              secondary: `Definition ${row.definition_id} · clinic ${row.clinic_id}`,
            }))}
          />

          <FaultCard
            title="Stages nothing reaches"
            note="No transition leads here and it is not the first stage, so no case can ever arrive."
            hue={HUE.orange}
            icon={AlertTriangle}
            items={integrity.unreachable_stages.map((row, index) => ({
              key: `unreach-${row.definition_id}-${index}`,
              primary: row.stage,
              secondary: row.definition ?? `Definition ${row.definition_id}`,
            }))}
          />

          <FaultCard
            title="Stages nothing leaves"
            note="No transition leads out and it is not the last stage, so a case arriving here cannot go on."
            hue={HUE.orange}
            icon={AlertTriangle}
            items={integrity.dead_end_stages.map((row, index) => ({
              key: `dead-${row.definition_id}-${index}`,
              primary: row.stage,
              secondary: row.definition ?? `Definition ${row.definition_id}`,
            }))}
          />

          {/*
            The quiet one. Someone set these expecting them to gate a movement;
            the engine evaluates `rules` and never looks at `validation_rules`.
          */}
          <FaultCard
            title="Validation rules that are never checked"
            note="Recorded on the transition and read by nothing — the engine evaluates its criteria rules only. Anything relying on these is not being enforced."
            hue={HUE.coral}
            icon={ShieldAlert}
            items={integrity.unenforced_validation_rules.map((row) => ({
              key: `val-${row.transition_id}`,
              primary: row.definition ?? `Definition ${row.definition_id}`,
              secondary: `Transition ${row.transition_id} · ${JSON.stringify(row.rules)}`,
            }))}
          />

          <FaultCard
            title="Two workflows switched on at once"
            note="One branch, more than one active definition. Creating one through the service switches the others off, so this came from a write that did not."
            hue={HUE.orange}
            icon={Layers}
            items={integrity.duplicate_active.map((row, index) => ({
              key: `dup-${row.clinic_id}-${index}`,
              primary: row.names.join(', '),
              secondary: `Clinic ${row.clinic_id} · ${row.branch_id ? `branch ${row.branch_id}` : 'all branches'}`,
            }))}
          />

          {integrity.foreign_branch.length > 0 && (
            <FaultCard
              title="Filed under one clinic, pointing at another"
              note="The definition service now refuses to create these. Rows written before it did are still here."
              hue={HUE.coral}
              icon={ShieldAlert}
              items={integrity.foreign_branch.map((row) => ({
                key: `foreign-${row.definition_id}`,
                primary: row.name,
                secondary: `Clinic ${row.clinic_id} · branch ${row.branch_id} belongs to clinic ${row.branch_clinic_id}`,
              }))}
            />
          )}
        </div>
      )}

      {tab === 'runs' && (
        <>
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: 'Runs, all time', value: runs.total, hue: HUE.blue },
              { label: 'In progress', value: runs.by_status.active ?? 0, hue: HUE.green },
              { label: 'Completed', value: runs.by_status.completed ?? 0, hue: HUE.violet },
              { label: 'Cancelled', value: runs.by_status.cancelled ?? 0, hue: HUE.orange },
            ].map((cell) => (
              <CrmCard key={cell.label} className="px-5 py-4">
                <span className="block h-1.5 w-9 rounded-full" style={{ background: cell.hue }} aria-hidden />
                <CrmValue value={cell.value} size="minor" className="mt-2" />
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{cell.label}</p>
              </CrmCard>
            ))}
          </div>

          {/*
            No "failed" column, because there is no failed status: the engine
            writes active, completed and cancelled and nothing else. A run that
            cannot proceed looks exactly like one waiting on a person.
          */}
          {! unsupported.has_failure_state && (
            <CrmCard className="mb-4 flex flex-wrap items-start gap-3 px-5 py-4" frostHue={HUE.orange}>
              <CrmCardIcon icon={Timer} hue={HUE.orange} />
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold text-ovs-crm-text-primary">
                  A run cannot be marked failed, so nothing can be retried
                </p>
                <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
                  The engine records {unsupported.statuses_in_use.length > 0
                    ? unsupported.statuses_in_use.join(', ')
                    : 'active, completed and cancelled'}{' '}
                  and carries no error or attempt count. A case that cannot proceed is indistinguishable from one
                  waiting on a person, which is why the list below is ordered by how long each has been still rather
                  than filtered to the stuck ones.
                  {listenerTotal === 0 && (
                    <>
                      {' '}
                      Nothing subscribes to the engine's four events either, so finishing a case notifies no one.
                    </>
                  )}
                </p>
              </div>
            </CrmCard>
          )}

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <CrmCard className="p-6">
              <div className="mb-3 flex items-start gap-3">
                <div className="min-w-0 flex-1">
                  <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                    Still the longest
                  </h2>
                  <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                    Time since each case last moved — no threshold is applied
                  </p>
                </div>
                <CrmCardIcon icon={Timer} hue={HUE.orange} />
              </div>

              {runs.longest_still.length === 0 ? (
                <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">No case is in progress.</p>
              ) : (
                <ul className="space-y-2">
                  {runs.longest_still.map((run) => (
                    <li key={run.id} className="rounded-[14px] border border-ovs-crm-border px-3.5 py-2.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <CrmStatusPill tone="neutral">{run.stage ?? 'no stage'}</CrmStatusPill>
                        <span className="text-[12.5px] font-medium text-ovs-crm-text-primary">
                          {run.definition ?? `Definition ${run.definition_id}`}
                        </span>
                        {run.still_for_hours !== null && (
                          <span className="text-[11.5px] tabular-nums text-ovs-crm-text-secondary">
                            still {run.still_for_hours}h
                          </span>
                        )}
                      </div>
                      <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                        {run.clinic ?? '—'} · encounter {run.encounter_id} · last moved {run.last_moved_at ?? '—'}
                      </p>
                    </li>
                  ))}
                </ul>
              )}
            </CrmCard>

            <CrmCard className="p-6">
              <div className="mb-3 flex items-start gap-3">
                <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Recent movements
                </h2>
                <CrmCardIcon icon={History} hue={HUE.violet} />
              </div>

              {history.length === 0 ? (
                <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">Nothing has moved yet.</p>
              ) : (
                <div className="overflow-x-auto">
                  <ul className="min-w-[360px] space-y-1.5">
                    {history.map((entry) => (
                      <li
                        key={entry.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-[14px] border border-ovs-crm-border px-3.5 py-2"
                      >
                        <CrmStatusPill
                          tone={entry.action === 'cancel' ? 'warn' : entry.action === 'complete' ? 'good' : 'neutral'}
                        >
                          {entry.action ?? 'move'}
                        </CrmStatusPill>
                        <span className="min-w-[140px] flex-1 text-[12px] text-ovs-crm-text-primary">
                          {entry.from ?? 'start'} → {entry.to ?? 'end'}
                        </span>
                        <span className="text-[11px] tabular-nums text-ovs-crm-text-muted">{entry.at}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </CrmCard>
          </div>
        </>
      )}
    </AdministrationShell>
  );
}

function FaultCard({
  title,
  note,
  hue,
  icon,
  items,
}: {
  title: string;
  note: string;
  hue: string;
  icon: LucideIcon;
  items: Array<{ key: string; primary: string; secondary: string }>;
}) {
  return (
    <CrmCard className="p-6">
      <div className="mb-3 flex items-start gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">{title}</h2>
            <CrmStatusPill tone={items.length === 0 ? 'good' : 'bad'}>{items.length}</CrmStatusPill>
          </div>
          <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-secondary">{note}</p>
        </div>
        <CrmCardIcon icon={icon} hue={items.length === 0 ? HUE.green : hue} />
      </div>

      {items.length === 0 ? (
        <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">None.</p>
      ) : (
        <ul className="space-y-2">
          {items.map((item) => (
            <li key={item.key} className="rounded-[14px] border border-ovs-crm-border px-3.5 py-2.5">
              <p className="text-[12.5px] font-medium text-ovs-crm-text-primary">{item.primary}</p>
              <p className="mt-0.5 break-words text-[11.5px] text-ovs-crm-text-muted">{item.secondary}</p>
            </li>
          ))}
        </ul>
      )}
    </CrmCard>
  );
}
