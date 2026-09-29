import React from 'react';
import { Link } from '@inertiajs/react';
import { MarketingShell } from '../../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  toneClasses,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { Activity, ArrowLeft, Clock, ExternalLink, ListOrdered } from 'lucide-react';
import { humanize, stepLabel } from '../labels';

/**
 * One run, step by step.
 *
 * The page a failure sends you to: which rule fired, for whom, how far it got,
 * and what stopped it. Steps read top to bottom in the order they happened,
 * because "it got to step three and then failed" is the shape of the answer.
 *
 * Like the index, this component was rendered by the controller and had never
 * existed on disk — the route threw for anyone who reached it.
 */

interface StepRow {
  id: number;
  step_type: string;
  status: string;
  attempts: number;
  error_message: string | null;
  scheduled_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  output_payload: Record<string, unknown> | null;
}

interface ExecutionModel {
  id: number;
  status: string;
  stop_reason: string | null;
  last_error: string | null;
  started_at: string | null;
  completed_at: string | null;
  next_run_at: string | null;
  automation?: { id: number; name: string; trigger_type: string } | null;
  contact?: { id: number; name: string; phone: string; organization: string | null } | null;
  lead?: { id: number; company_name: string; status: string } | null;
  conversation?: { id: number } | null;
  steps?: StepRow[];
}

const TONE: Record<string, CrmStatusTone> = {
  queued: 'neutral',
  pending: 'neutral',
  running: 'warn',
  waiting: 'warn',
  completed: 'good',
  succeeded: 'good',
  stopped: 'neutral',
  failed: 'bad',
  cancelled: 'neutral',
  skipped: 'neutral',
};

const stamp = (iso: string | null) => {
  if (! iso) return '—';
  const d = new Date(iso);

  if (Number.isNaN(d.getTime())) return '—';

  return d.toLocaleString('en-IN', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
  });
};

export default function ExecutionShow({ execution }: { execution: ExecutionModel }) {
  const steps = execution.steps ?? [];
  const title = `Run #${execution.id}`;

  return (
    <MarketingShell title={title}>

      <CrmPageHead
        eyebrow={
          execution.automation
            ? `${execution.automation.name} · ${humanize(execution.automation.trigger_type)}`
            : 'No longer linked to an automation'
        }
        title={title}
        stats={
          <>
            <CrmPageStat
              icon={Activity}
              value={humanize(execution.status)}
              caption="How this run ended"
            />
            <CrmPageStat icon={ListOrdered} value={steps.length} caption="Steps recorded" />
            <CrmPageStat icon={Clock} value={stamp(execution.started_at)} caption="Started" />
          </>
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <CrmButton size="sm" icon={ArrowLeft} href="/company/marketing/executions">
              All runs
            </CrmButton>
            {execution.automation && (
              <CrmButton
                size="sm"
                icon={ExternalLink}
                href={`/company/marketing/automations/${execution.automation.id}`}
              >
                Automation
              </CrmButton>
            )}
          </div>
        }
      />

      {/*
        * The failure banner comes before anything else on the page.
        * If a run failed, that message is the reason the page was opened.
        */}
      {execution.last_error && (
        <CrmCard className={`mb-3 border-l-[3px] px-5 py-4 ${toneClasses('coral').accent}`}>
          <p className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-ovs-crm-text-muted">
            Failed
          </p>
          <p className={`text-[13.5px] ${toneClasses('coral').text}`}>{execution.last_error}</p>
        </CrmCard>
      )}

      <div className="grid gap-3 lg:grid-cols-12">
        <div className="lg:col-span-8">
          <CrmSection
            flush
            title="Steps"
            description={
              steps.length === 0
                ? 'This run recorded no steps.'
                : `${steps.length} step${steps.length === 1 ? '' : 's'}, in order`
            }
          >
            {steps.length === 0 ? (
              <CrmCard className="px-6 py-10 text-center">
                <p className="text-[13px] text-ovs-crm-text-muted">
                  Nothing ran — the trigger fired but no step was reached.
                </p>
              </CrmCard>
            ) : (
              <CrmCard className="px-5 py-2">
                {steps.map((step, index) => (
                  <div
                    key={step.id}
                    className="flex items-start gap-4 border-b border-ovs-crm-border-soft py-3.5 last:border-b-0"
                  >
                    <span className="mt-0.5 w-5 shrink-0 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                      {index + 1}
                    </span>

                    <div className="min-w-0 flex-1">
                      <p className="text-[13px] font-medium text-ovs-crm-text-primary">
                        {stepLabel(step.step_type)}
                      </p>
                      <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                        {step.error_message ? (
                          <span className={toneClasses('coral').text}>{step.error_message}</span>
                        ) : (
                          <>
                            {stamp(step.completed_at ?? step.started_at ?? step.scheduled_at)}
                            {step.attempts > 1 && ` · ${step.attempts} attempts`}
                          </>
                        )}
                      </p>
                    </div>

                    <span className="shrink-0">
                      <CrmStatusPill tone={TONE[step.status] ?? 'neutral'}>
                        {humanize(step.status)}
                      </CrmStatusPill>
                    </span>
                  </div>
                ))}
              </CrmCard>
            )}
          </CrmSection>
        </div>

        <div className="lg:col-span-4">
          <CrmSection flush title="Who it ran for">
            <CrmCard className="space-y-3 px-5 py-4">
              <Field label="Contact" value={execution.contact?.name ?? '—'} sub={execution.contact?.phone} />
              <Field label="Organization" value={execution.contact?.organization ?? '—'} />

              {execution.lead ? (
                <div>
                  <p className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-ovs-crm-text-muted">
                    Lead created
                  </p>
                  <Link
                    href={`/company/crm/leads/${execution.lead.id}`}
                    className="text-[13px] font-medium text-ovs-crm-text-primary underline-offset-2 hover:underline"
                  >
                    {execution.lead.company_name}
                  </Link>
                </div>
              ) : (
                <Field label="Lead created" value="—" />
              )}

              {execution.stop_reason && (
                <Field label="Stopped because" value={humanize(execution.stop_reason)} />
              )}

              {execution.next_run_at && (
                <Field label="Next step due" value={stamp(execution.next_run_at)} />
              )}
            </CrmCard>
          </CrmSection>
        </div>
      </div>
    </MarketingShell>
  );
}

function Field({ label, value, sub }: { label: string; value: string; sub?: string | null }) {
  return (
    <div>
      <p className="mb-0.5 text-[11px] font-semibold uppercase tracking-wider text-ovs-crm-text-muted">
        {label}
      </p>
      <p className="text-[13px] text-ovs-crm-text-primary">{value}</p>
      {sub && <p className="text-[11.5px] text-ovs-crm-text-muted">{sub}</p>}
    </div>
  );
}
