import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmHairlineBar,
  CrmRing,
  CrmStatusPill,
  CrmButton,
  CrmAvatar,
  CrmPagination,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  CheckCircle2,
  Copy,
  Edit3,
  ExternalLink,
  PauseCircle,
  PlayCircle,
  Zap,
} from 'lucide-react';
import { triggerLabel, stepLabel, STEP_ICON, humanize } from './labels';

interface ContactItem {
  id: number;
  name: string;
  phone: string;
  organization: string | null;
}

interface LeadItem {
  id: number;
  company_name: string;
  status: string;
}

interface ExecutionItem {
  id: number;
  status: 'queued' | 'running' | 'waiting' | 'completed' | 'stopped' | 'failed' | 'cancelled';
  stop_reason: string | null;
  last_error: string | null;
  started_at: string | null;
  completed_at: string | null;
  contact?: ContactItem | null;
  lead?: LeadItem | null;
}

/**
 * Laravel’s paginator, in full.
 *
 * The declaration here listed only three of its fields, so anything wanting to
 * render page links had no types for them — which is presumably why this page
 * had no pagination at all despite the controller paginating to 20 a page.
 */
interface PaginatedData<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface AutomationModel {
  id: number;
  name: string;
  description: string | null;
  status: 'draft' | 'active' | 'paused' | 'archived';
  trigger_type: string;
  definition: any;
  created_at: string;
  activated_at: string | null;
}

interface ShowProps {
  automation: AutomationModel;
  executions: PaginatedData<ExecutionItem>;
  stats: {
    total: number;
    running: number;
    completed: number;
    stopped: number;
    failed: number;
  };
}

const AUTOMATION_TONE: Record<string, CrmStatusTone> = {
  draft: 'neutral', active: 'good', paused: 'warn', archived: 'neutral',
};

const EXECUTION_TONE: Record<string, CrmStatusTone> = {
  queued: 'neutral', running: 'warn', waiting: 'warn',
  completed: 'good', stopped: 'neutral', failed: 'bad', cancelled: 'neutral',
};

const shortDate = (iso: string | null) => {
  if (!iso) return '—';
  const d = new Date(iso);

  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
};

export default function AutomationShow({ automation, executions, stats }: ShowProps) {
  const handlePause = () => {
    router.post(`/company/marketing/automations/${automation.id}/pause`, {}, { preserveScroll: true });
  };

  const handleResume = () => {
    router.post(`/company/marketing/automations/${automation.id}/resume`, {}, { preserveScroll: true });
  };

  const handleDuplicate = () => {
    router.post(`/company/marketing/automations/${automation.id}/duplicate`, {}, { preserveScroll: true });
  };

  /*
   * An automation is judged on whether its runs finish. So the page leads with
   * that split — completed against stopped and failed — rather than with the
   * rule's own configuration, which matters far less once it is live.
   */
  const live = automation.status === 'active';
  const finished = stats.completed + stats.stopped + stats.failed;
  const successRate = finished > 0 ? (stats.completed / finished) * 100 : 0;

  const outcomes = [
    { label: 'Completed', count: stats.completed, hue: '#2FA96B', caption: 'Ran all the way through' },
    { label: 'Waiting', count: stats.running, hue: '#2F6BE4', caption: 'Still going' },
    { label: 'Stopped', count: stats.stopped, hue: '#E0B21E', caption: 'A condition said no' },
    { label: 'Failed', count: stats.failed, hue: '#EF5B39', caption: 'Something broke' },
  ];

  const steps: { type?: string; [key: string]: unknown }[] = automation.definition?.steps ?? [];

  return (
    <MarketingShell title={automation.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={automation.name}
        stats={
          <>
            <CrmPageStat
              icon={Zap}
              value={stats.total}
              chip={stats.failed > 0 ? `${stats.failed} failed` : undefined}
              chipHue="#EF5B39"
              caption="Times this rule has run"
            />
            <CrmPageStat
              icon={CheckCircle2}
              value={`${Math.round(successRate)}%`}
              caption="Of finished runs completed"
            />
          </>
        }
        actions={
          <>
            <CrmButton href="/company/marketing/automations">All automations</CrmButton>
            <CrmButton icon={Copy} onClick={handleDuplicate}>
              Duplicate
            </CrmButton>
            <CrmButton
              icon={Edit3}
              href={`/company/marketing/automations/${automation.id}/edit`}
            >
              Edit
            </CrmButton>
            {automation.status !== 'archived' && (
              <CrmButton
                tone={live ? 'secondary' : 'primary'}
                icon={live ? PauseCircle : PlayCircle}
                onClick={live ? handlePause : handleResume}
              >
                {live ? 'Pause' : 'Switch on'}
              </CrmButton>
            )}
          </>
        }
      />

      {/* ═══ What it is, and whether it works ═══════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={0} className="lg:col-span-7">
          <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="mb-1 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Runs when
              </p>
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                {triggerLabel(automation.trigger_type)}
              </h2>
              {automation.description && (
                <p className="mt-1 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                  {automation.description}
                </p>
              )}
            </div>
            <CrmStatusPill tone={AUTOMATION_TONE[automation.status] ?? 'neutral'}>
              {humanize(automation.status)}
            </CrmStatusPill>
          </div>

          {/*
            * The steps, in order, as a sequence rather than a JSON dump. What a
            * rule does is the thing people come here to check.
            */}
          {steps.length === 0 ? (
            <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
              This automation has no steps — it will trigger and do nothing.
            </p>
          ) : (
            <div className="space-y-2">
              {steps.map((step, index) => {
                const stepType = String(step.type ?? 'step');
                const StepIcon = STEP_ICON[stepType];

                return (
                  <div
                    key={index}
                    className="flex items-center gap-3 rounded-[18px] bg-ovs-crm-surface-inset p-3"
                  >
                    <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white text-[11.5px] tabular-nums text-ovs-crm-text-secondary">
                      {index + 1}
                    </span>
                    {StepIcon && <StepIcon className="h-3.5 w-3.5 shrink-0 text-ovs-crm-text-secondary" aria-hidden="true" />}
                    <p className="min-w-0 flex-1 text-[13px] text-ovs-crm-text-primary">
                      {stepLabel(stepType)}
                    </p>
                  </div>
                );
              })}
            </div>
          )}
        </CrmCard>

        <div className="grid grid-cols-1 gap-4 lg:col-span-5">
          <CrmCard step={1} className="flex flex-col items-center">
            <div className="mb-4 w-full">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Runs that finished cleanly
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                A stop is not a failure — a condition simply said no
              </p>
            </div>
            <CrmRing
              value={stats.completed}
              max={Math.max(finished, 1)}
              color="#2FA96B"
              label={`${Math.round(successRate)}%`}
              caption={`${stats.completed} of ${finished}`}
              size={132}
            />
          </CrmCard>

          <CrmSection step={2} title="Every run" description="Grouped by how it ended">
            <div className="space-y-3.5">
              {outcomes.map((band) => (
                <div key={band.label}>
                  <div className="mb-1.5 flex items-baseline justify-between gap-3">
                    <span className="flex items-center gap-2 text-[12px] font-medium text-ovs-crm-text-secondary">
                      {band.label}
                      <span className="text-[11.5px] text-ovs-crm-text-muted">{band.caption}</span>
                    </span>
                    <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                      {band.count}
                    </span>
                  </div>
                  <CrmHairlineBar
                    value={band.count}
                    max={Math.max(stats.total, 1)}
                    color={band.hue}
                  />
                </div>
              ))}
            </div>
          </CrmSection>
        </div>
      </div>

      {/* ═══ Run by run ═════════════════════════════════════════════════ */}
      <CrmSection
        step={3}
        title="Run history"
        description={`${executions.total} run${executions.total === 1 ? '' : 's'}, newest first`}
      >
        {executions.data.length === 0 ? (
          <p className="py-12 text-center text-[13px] text-ovs-crm-text-muted">
            {live
              ? 'Switched on, but nothing has triggered it yet.'
              : 'This automation has never run — it is not switched on.'}
          </p>
        ) : (
          <div className="-mx-2.5">
            {executions.data.map((execution) => (
              <div
                key={execution.id}
                className="flex items-center gap-4 rounded-[14px] px-2.5 py-3 hover:bg-ovs-crm-surface-inset"
              >
                <CrmAvatar name={execution.contact?.name ?? 'Run'} size="sm" />

                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                    {execution.contact?.name ?? `Run #${execution.id}`}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    {execution.last_error
                      ? execution.last_error
                      : execution.stop_reason
                        ? humanize(execution.stop_reason)
                        : execution.lead
                          ? 'Created a lead'
                          : shortDate(execution.started_at)}
                  </p>
                </div>

                {execution.lead && (
                  <CrmButton
                    size="sm"
                    icon={ExternalLink}
                    href={`/company/crm/leads/${execution.lead.id}`}
                  >
                    Lead
                  </CrmButton>
                )}

                <span className="shrink-0">
                  <CrmStatusPill tone={EXECUTION_TONE[execution.status] ?? 'neutral'}>
                    {humanize(execution.status)}
                  </CrmStatusPill>
                </span>
              </div>
            ))}
          </div>
        )}

        <CrmPagination
          links={executions.links}
          from={executions.from}
          to={executions.to}
          total={executions.total}
          className="mt-4"
        />
      </CrmSection>
    </MarketingShell>
  );
}
