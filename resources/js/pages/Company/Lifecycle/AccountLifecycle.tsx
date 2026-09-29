import React from 'react';
import { Head } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, FlaskConical, History, Rocket, Workflow } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { LifecycleShell } from './components/LifecycleShell';

/**
 * One account's life, in order.
 *
 * Four existing records assembled into a timeline: what the provisioning engine
 * did, where the onboarding machine is, what the trial recorded, and what the
 * subscription's own events say. Read-only, and none of it is stored a second
 * time.
 */

const HUE = { violet: '#7C6FE0', green: '#2FA96B', orange: '#F59245', coral: '#EF5B39', blue: '#2F6BE4' } as const;

interface Props {
  lifecycle: {
    organization: { id: number; name: string; role: string | null };
    stage: string;
    window: { state: string; days_remaining: number | null } | null;
    onboarding: { current_step: string | null; status: string | null; completed_steps: string[] } | null;
    trial: {
      id: number;
      status: string | null;
      start_date: string | null;
      end_date: string | null;
      provisioned_at: string | null;
      provisioning_error: string | null;
    } | null;
    provisioning: Array<{
      id: number;
      profile: string | null;
      status: string | null;
      duration_ms: number | null;
      version: string | null;
      error: string | null;
      at: string | null;
    }>;
    subscription: {
      id: number;
      plan: string | null;
      status: string;
      mrr: number | null;
      starts_at: string | null;
      ends_at: string | null;
      days_to_expiry: number | null;
    } | null;
    events: Array<{ id: number; label: string; reason: string | null; actor: string | null; at: string | null }>;
  };
  can: { viewAccounts: boolean; viewSubscriptions: boolean };
}

const STAGE_TONE: Record<string, CrmStatusTone> = {
  live: 'good',
  trialling: 'info',
  grace: 'warn',
  onboarding: 'warn',
  lapsed: 'bad',
  not_provisioned: 'neutral',
};

const STAGE_LABEL: Record<string, string> = {
  live: 'Live',
  trialling: 'Trialling',
  grace: 'In grace',
  onboarding: 'Onboarding',
  lapsed: 'Lapsed',
  not_provisioned: 'Not provisioned',
};

const inr = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value);

export default function AccountLifecycle({ lifecycle, can }: Props) {
  const { organization, stage, window: accessWindow, onboarding, trial, provisioning, subscription, events } = lifecycle;

  return (
    <LifecycleShell title={organization.name}>
      <Head title={`${organization.name} | Customer Success`} />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Account lifecycle
          </p>
          <h1 className="text-[32px] font-bold leading-[1.06] tracking-[-0.03em] text-ovs-crm-text-primary sm:text-[38px]">
            {organization.name}
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <CrmStatusPill tone={STAGE_TONE[stage] ?? 'neutral'}>{STAGE_LABEL[stage] ?? stage}</CrmStatusPill>
            {accessWindow && <CrmStatusPill tone="neutral">window: {accessWindow.state}</CrmStatusPill>}
            {trial?.provisioning_error && <CrmStatusPill tone="bad">Provisioning failed</CrmStatusPill>}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat
            icon={Rocket}
            value={onboarding?.status === 'completed' || ! onboarding ? 'Complete' : (onboarding.current_step ?? '—')}
            caption="Onboarding"
          />
          <CrmPageStat icon={FlaskConical} value={trial?.status ?? 'None'} caption="Trial" />
          <CrmPageStat
            icon={Workflow}
            value={accessWindow?.days_remaining === null || accessWindow === null ? '—' : `${accessWindow.days_remaining}d`}
            caption="Window remaining"
          />
        </div>
      </div>

      <div className="mb-4 flex flex-wrap gap-2">
        <CrmButton size="sm" icon={ArrowLeft} href="/company/customer-success/lifecycle">
          All accounts
        </CrmButton>
        {can.viewAccounts && (
          <CrmButton size="sm" href={`/company/customer-success/accounts/${organization.id}`}>
            Account 360
          </CrmButton>
        )}
        {can.viewSubscriptions && subscription && (
          <CrmButton size="sm" href={`/company/customer-success/subscriptions/${subscription.id}`}>
            Subscription
          </CrmButton>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Onboarding
            </h2>
            <CrmCardIcon icon={Rocket} hue={HUE.orange} />
          </div>

          {onboarding ? (
            <>
              <p className="text-[12.5px] text-ovs-crm-text-secondary">
                Currently at <span className="text-ovs-crm-text-primary">{onboarding.current_step ?? '—'}</span> ·{' '}
                {onboarding.status}
              </p>
              {onboarding.completed_steps.length > 0 && (
                <ul className="mt-3 space-y-1.5 border-t border-ovs-crm-border pt-3">
                  {onboarding.completed_steps.map((step) => (
                    <li key={step} className="flex items-center gap-2 text-[12px] text-ovs-crm-text-secondary">
                      <CheckCircle2 className="h-3.5 w-3.5 text-ovs-crm-accent-green" aria-hidden />
                      {step}
                    </li>
                  ))}
                </ul>
              )}
            </>
          ) : (
            <p className="text-[12.5px] text-ovs-crm-text-muted">No onboarding record for this account.</p>
          )}
        </CrmCard>

        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Trial
            </h2>
            <CrmCardIcon icon={FlaskConical} hue={HUE.blue} />
          </div>

          {trial ? (
            <dl className="space-y-2.5">
              {[
                ['Status', trial.status],
                ['Started', trial.start_date],
                ['Ends', trial.end_date],
                ['Provisioned', trial.provisioned_at],
              ].map(([label, value]) => (
                <div key={label as string} className="flex items-baseline justify-between gap-3">
                  <dt className="text-[12.5px] text-ovs-crm-text-secondary">{label as string}</dt>
                  <dd className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {(value as string | null) ?? <span className="text-ovs-crm-text-muted">—</span>}
                  </dd>
                </div>
              ))}
              {trial.provisioning_error && (
                <p className="mt-2 border-t border-ovs-crm-border pt-2.5 text-[12px] text-ovs-crm-accent-coral-text">
                  {trial.provisioning_error}
                </p>
              )}
            </dl>
          ) : (
            <p className="text-[12.5px] text-ovs-crm-text-muted">This account never ran a trial.</p>
          )}
        </CrmCard>

        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Subscription
            </h2>
            <CrmCardIcon icon={Workflow} hue={HUE.violet} />
          </div>

          {subscription ? (
            <dl className="space-y-2.5">
              {[
                ['Plan', subscription.plan],
                ['Status', subscription.status],
                ['Started', subscription.starts_at],
                ['Ends', subscription.ends_at],
                /* Unknown reads as such — never a zero standing in for it. */
                ['MRR', subscription.mrr === null ? 'Not recorded' : inr(subscription.mrr)],
              ].map(([label, value]) => (
                <div key={label as string} className="flex items-baseline justify-between gap-3">
                  <dt className="text-[12.5px] text-ovs-crm-text-secondary">{label as string}</dt>
                  <dd className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {(value as string | null) ?? <span className="text-ovs-crm-text-muted">—</span>}
                  </dd>
                </div>
              ))}
            </dl>
          ) : (
            <p className="text-[12.5px] text-ovs-crm-text-muted">
              No subscription exists, so this account has not been provisioned.
            </p>
          )}
        </CrmCard>
      </div>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Provisioning runs
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                Written by the engine as it worked — read here, never edited
              </p>
            </div>
            <CrmCardIcon icon={History} hue={HUE.green} />
          </div>

          {provisioning.length === 0 ? (
            <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">
              No provisioning run was recorded for this account.
            </p>
          ) : (
            <ol className="space-y-3">
              {provisioning.map((run) => (
                <li key={run.id} className="flex gap-3">
                  <span
                    className="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                    style={{ background: run.status === 'failed' ? HUE.coral : HUE.green }}
                    aria-hidden
                  />
                  <div className="min-w-0 flex-1">
                    <p className="flex flex-wrap items-center gap-2 text-[12.5px] font-medium text-ovs-crm-text-primary">
                      {run.profile ?? 'provision'}
                      <CrmStatusPill tone={run.status === 'failed' ? 'bad' : 'good'}>{run.status}</CrmStatusPill>
                      {run.version && <span className="text-[11px] font-normal text-ovs-crm-text-muted">v{run.version}</span>}
                    </p>
                    {run.error && <p className="mt-0.5 text-[12px] text-ovs-crm-accent-coral-text">{run.error}</p>}
                    <p className="mt-0.5 text-[11px] tabular-nums text-ovs-crm-text-muted">
                      {run.at}
                      {run.duration_ms !== null && <> · {run.duration_ms}ms</>}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}
        </CrmCard>

        <CrmCard className="p-6">
          <div className="mb-4 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Entitlement history
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                Trial started, converted to paid, extended or cancelled
              </p>
            </div>
            <CrmCardIcon icon={History} hue={HUE.violet} />
          </div>

          {events.length === 0 ? (
            <p className="py-6 text-center text-[12.5px] text-ovs-crm-text-muted">
              Nothing has changed this account's entitlement yet.
            </p>
          ) : (
            <ol className="space-y-3">
              {events.map((event) => (
                <li key={event.id} className="flex gap-3">
                  <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-ovs-crm-ink" aria-hidden />
                  <div className="min-w-0 flex-1">
                    <p className="text-[12.5px] font-medium text-ovs-crm-text-primary">{event.label}</p>
                    {event.reason && <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">{event.reason}</p>}
                    <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">
                      {event.at}
                      {event.actor && <> · {event.actor}</>}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}
        </CrmCard>
      </div>
    </LifecycleShell>
  );
}
