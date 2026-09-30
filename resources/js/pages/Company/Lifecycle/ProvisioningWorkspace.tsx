import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Building2, CheckCircle2, FlaskConical, Rocket, Workflow } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageHead,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmDataState,
  CrmPagination,
  CrmSection,
  CrmTable,
  lifecycleStageTone,
  statusTone,
  humanize,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { LifecycleShell } from './components/LifecycleShell';

/**
 * Lifecycle → Provisioning
 *
 * Where every account is in its life, derived from records that already exist:
 * the provisioning audit, the onboarding machine, the trial and the
 * subscription window. Nothing here maintains a stage column of its own.
 *
 * Retrying a failed provision is not offered. That path lives behind the trials
 * surface, and a second button calling the same engine is how two modules end
 * up disagreeing about whether something was retried.
 */

const HUE = { violet: '#7C6FE0', green: '#2FA96B', orange: '#F59245', coral: '#EF5B39', blue: '#2F6BE4' } as const;

interface Row {
  organization_id: number;
  account: string;
  stage: string;
  window_state: string | null;
  onboarding_step: string | null;
  onboarding_status: string | null;
  trial_status: string | null;
  provisioning_error: string | null;
  last_provisioning: { status: string; profile: string; at: string | null; error: string | null } | null;
  subscription_id: number | null;
}

interface Props {
  rows: Row[];
  paginator: {
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number | null;
  };
  filters: Record<string, string | undefined>;
  summary: {
    accounts: number;
    subscriptions: number;
    onboarding_open: number;
    trials_active: number;
    provisioning_failed: number;
    audits: number;
    audits_failed: number;
  };
}

/*
 * The stage *colours* live in `crmTheme`, not here.
 *
 * This file carried its own copy which had drifted: it painted `onboarding`
 * amber while the canonical map paints it blue, so the same account read as a
 * warning on this page and as in-progress on the Accounts list. Only the
 * wording is local now, because "In grace" is nicer than what `humanize()`
 * would produce and no other page needs it.
 */
const STAGE_LABEL: Record<string, string> = {
  live: 'Live',
  trialling: 'Trialling',
  grace: 'In grace',
  onboarding: 'Onboarding',
  lapsed: 'Lapsed',
  not_provisioned: 'Not provisioned',
};

const STAGES = ['not_provisioned', 'onboarding', 'trialling', 'grace', 'live', 'lapsed'] as const;

export default function ProvisioningWorkspace({ rows, paginator, filters, summary }: Props) {
  const apply = (changes: Record<string, string | undefined>) =>
    router.get(
      '/company/customer-success/lifecycle',
      { ...filters, ...changes },
      { preserveState: true, preserveScroll: true, replace: true },
    );

  const failing = rows.filter((row) => row.provisioning_error || row.last_provisioning?.status === 'failed');

  return (
    <LifecycleShell title="Lifecycle">
      <Head title="Lifecycle | Customer Success" />

      <CrmPageHead
        eyebrow="Customer success"
        title="Lifecycle"
        description="Provisioning, onboarding, trial and go-live, across every account."
        stats={
          <>
            <CrmPageStat icon={Building2} value={summary.accounts} caption="Accounts" />
            <CrmPageStat icon={Rocket} value={summary.onboarding_open} caption="Still onboarding" />
            <CrmPageStat icon={FlaskConical} value={summary.trials_active} caption="Trials running" />
            <CrmPageStat icon={AlertTriangle} value={summary.provisioning_failed} caption="Provisioning failed" />
          </>
        }
      />

      {failing.length > 0 && (
        <CrmCard className="mb-4 p-6" frostHue={HUE.coral}>
          <div className="mb-3 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Provisioning that did not finish
              </h2>
              {/* Retry lives with trials, deliberately, and is linked rather than duplicated. */}
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                Retrying is done from the trial itself, which is the one place that owns the engine.
              </p>
            </div>
            <CrmCardIcon icon={AlertTriangle} hue={HUE.coral} />
          </div>

          <ul className="space-y-2">
            {failing.map((row) => (
              <li
                key={row.organization_id}
                className="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface px-4 py-2.5"
              >
                <span className="min-w-[150px] flex-1 text-[12.5px] font-medium text-ovs-crm-text-primary">
                  {row.account}
                </span>
                <span className="min-w-[200px] flex-1 text-[11.5px] text-ovs-crm-text-secondary">
                  {row.provisioning_error ?? row.last_provisioning?.error}
                </span>
                <CrmButton size="sm" href={`/company/customer-success/accounts/${row.organization_id}?tab=lifecycle`}>
                  Open
                </CrmButton>
              </li>
            ))}
          </ul>
        </CrmCard>
      )}

      <CrmCard className="mb-3 flex flex-wrap items-end gap-3 px-5 py-3.5">
        <div className="min-w-[220px] flex-1">
          <CrmFormField label="Search">
            <CrmInput
              type="search"
              placeholder="Account name"
              defaultValue={filters.search ?? ''}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  apply({ search: (e.target as HTMLInputElement).value || undefined });
                }
              }}
            />
          </CrmFormField>
        </div>

        <div className="min-w-[190px]">
          <CrmFormField label="Stage">
            <CrmSelect value={filters.stage ?? ''} onChange={(e) => apply({ stage: e.target.value || undefined })}>
              <option value="">Every stage</option>
              {STAGES.map((stage) => (
                <option key={stage} value={stage}>
                  {STAGE_LABEL[stage]}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>

        <CrmButton href="/company/customer-success/subscriptions">Subscriptions</CrmButton>
      </CrmCard>

      <CrmSection
        flush
        title="Accounts"
        description={
          (paginator?.total ?? 0) > 0
            ? `${paginator?.from ?? 0}–${paginator?.to ?? 0} of ${paginator?.total ?? 0}`
            : undefined
        }
      >
        <CrmTable<Row>
          columns={[
            {
              key: 'account',
              header: 'Account',
              mobileLabel: 'Account',
              primary: true,
              width: '30%',
              cell: (row) => (
                <span className="block min-w-0">
                  <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                    {row.account}
                  </span>
                  <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                    {row.onboarding_step ? `Onboarding: ${humanize(row.onboarding_step)}` : 'Onboarding complete'}
                    {row.trial_status ? ` · trial ${humanize(row.trial_status)}` : ''}
                  </span>
                </span>
              ),
            },
            {
              key: 'stage',
              header: 'Stage',
              mobileLabel: 'Stage',
              width: '160px',
              cell: (row) => (
                <span className="flex flex-wrap items-center gap-1.5">
                  <CrmStatusPill tone={lifecycleStageTone(row.stage)}>{STAGE_LABEL[row.stage] ?? humanize(row.stage)}</CrmStatusPill>
                  {row.provisioning_error && <CrmStatusPill tone="bad">Failed</CrmStatusPill>}
                </span>
              ),
            },
            {
              key: 'window',
              header: 'Access window',
              mobileLabel: 'Access window',
              width: '150px',
              cell: (row) =>
                row.window_state ? (
                  <CrmStatusPill tone={statusTone(row.window_state)}>{humanize(row.window_state)}</CrmStatusPill>
                ) : (
                  <span className="text-[12.5px] text-ovs-crm-text-muted">No subscription</span>
                ),
            },
            {
              key: 'provisioned',
              header: 'Last provisioned',
              mobileLabel: 'Last provisioned',
              width: '170px',
              hideOnMobile: true,
              cell: (row) => (
                <span className="text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                  {row.last_provisioning?.at ?? 'Never'}
                </span>
              ),
            },
          ]}
          rows={rows}
          rowKey={(row) => row.organization_id}
          onRowClick={(row) =>
            router.visit(`/company/customer-success/accounts/${row.organization_id}?tab=lifecycle`)
          }
          isFiltered={Object.values(filters).some(Boolean)}
          empty={{
            icon: Workflow,
            title: 'No accounts to show',
            description: 'An account appears here once it has a provisioned clinic behind it.',
          }}
          emptyFiltered={{
            title: 'Nothing matches this cut',
            description: 'No account satisfies every filter currently applied.',
          }}
          footer={
            paginator ? (
              <CrmPagination
                links={paginator.links}
                from={paginator.from}
                to={paginator.to}
                total={paginator.total}
              />
            ) : undefined
          }
        />
      </CrmSection>
    </LifecycleShell>
  );
}
