import React from 'react';
import { Link } from '@inertiajs/react';
import {
  Building2,
  PlayCircle,
  CalendarCheck,
  TrendingUp,
  AlertTriangle,
  Clock,
  Sparkles,
  ArrowRight,
  ShieldCheck,
  CheckCircle2,
  FileSpreadsheet,
  Activity as ActivityIcon,
} from 'lucide-react';
import {
  CompanyShell,
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmCard,
  CrmMetric,
  CrmStatusPill,
  CrmAvatar,
  CrmButton,
  inr,
} from '@/pages/Company/design';

interface DashboardProps {
  commandCenter?: {
    userName: string;
    headlineStatus: string;
    businessHealth: string;
    todaysMission: Array<{ id: string; text: string }>;
    expectedImpact: Array<{ id: string; label: string; value: string; isPositive: boolean }>;
    aiConfidence: number;
    todaysFocus: string;
    riskLevel: string;
  };
  snapshot?: {
    activeClinics: { label: string; value: string; trend: string; isPositive: boolean };
    activeDemos: { label: string; value: string; trend: string; isPositive: boolean };
    renewals: { label: string; value: string; trend: string; isPositive: boolean };
    mrr: { label: string; value: string; trend: string; isPositive: boolean };
  };
  workspace?: {
    attention: Array<{
      id: string;
      category: string;
      title: string;
      description: string;
      time: string;
      priorityBadge: string;
      priorityDot: string;
    }>;
    approvals: Array<{
      id: string;
      avatarFallback: string;
      title: string;
      requestedBy: string;
      amount: string;
      time: string;
      priority: string;
      status: string;
    }>;
    myWork?: {
      tasks: Array<{ id: string; taskText: string; isCompleted: boolean }>;
      completedCount: number;
      totalCount: number;
    };
  };
  performance?: {
    pipeline: Array<{ id: string; stageName: string; count: number; lostPercent: string }>;
  };
  activityFeed?: Array<{
    id: string;
    avatarFallback: string;
    employeeName: string;
    actionText: string;
    businessValue?: string | null;
    time: string;
  }>;
  newLeads?: number;
  totalLeads?: number;
  followUpsToday?: number;
  quotationsPending?: number;
}

export default function Dashboard({
  commandCenter,
  snapshot,
  workspace,
  performance,
  activityFeed = [],
  newLeads = 0,
  totalLeads = 0,
  followUpsToday = 0,
  quotationsPending = 0,
}: DashboardProps) {
  const userName = commandCenter?.userName || 'Executive';
  const missions = commandCenter?.todaysMission || [];
  const attentionItems = workspace?.attention || [];
  const approvalItems = workspace?.approvals || [];
  const pipelineStages = performance?.pipeline || [];

  return (
    <CompanyShell title="Executive Mission Control" rail={[]}>
      <CrmPageHead
        eyebrow="Executive"
        title="Mission Control"
        stats={
          <>
            <CrmPageStat
              icon={Building2}
              value={snapshot?.activeClinics?.value ?? '0'}
              caption="Active clinics"
            />
            <CrmPageStat
              icon={TrendingUp}
              value={totalLeads}
              chip={newLeads > 0 ? `${newLeads} new` : undefined}
              chipHue="#2F6BE4"
              caption="Total pipeline leads"
            />
            <CrmPageStat
              icon={Clock}
              value={followUpsToday}
              chip={followUpsToday > 0 ? 'Due today' : undefined}
              chipHue="#E0B21E"
              caption="Scheduled follow-ups"
            />
            <CrmPageStat
              icon={FileSpreadsheet}
              value={quotationsPending}
              chip={quotationsPending > 0 ? 'Pending' : undefined}
              chipHue="#7C6FE0"
              caption="Pending quotations"
            />
          </>
        }
      />

      {/* ── Today's Briefing & Operational Focus ───────────────────────── */}
      <div className="mb-6 rounded-[24px] bg-ovs-crm-ink p-6 text-white shadow-ovs-crmInk sm:p-8">
        <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-0.5 text-[11px] font-semibold text-white/90 backdrop-blur">
                <Sparkles className="h-3 w-3 text-ovs-crm-accent-teal" />
                AI Chief of Staff Briefing
              </span>
              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[10.5px] font-semibold text-emerald-300">
                Health: {commandCenter?.businessHealth || 'Good'}
              </span>
            </div>
            <h2 className="text-2xl font-extrabold tracking-tight sm:text-3xl">
              Welcome back, {userName}
            </h2>
            <p className="text-[13px] text-white/70">
              {commandCenter?.headlineStatus || 'Active operations • All systems nominal'}
            </p>
          </div>

          {missions.length > 0 && (
            <div className="flex flex-wrap gap-2.5 lg:max-w-md">
              {missions.map((m) => (
                <div
                  key={m.id}
                  className="flex items-center gap-2 rounded-[16px] bg-white/10 px-3.5 py-2 text-[12px] font-medium text-white/90 backdrop-blur"
                >
                  <span className="h-1.5 w-1.5 rounded-full bg-ovs-crm-accent-teal" />
                  <span>{m.text}</span>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>

      {/* ── Snapshot Metric Cards ────────────────────────────────────────── */}
      <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <CrmCard step={0}>
          <CrmMetric
            label="Active Clinics"
            value={snapshot?.activeClinics?.value ?? '0'}
            hint={snapshot?.activeClinics?.trend ?? 'Multi-branch deployments'}
            tone={snapshot?.activeClinics?.isPositive ? 'success' : 'default'}
          />
        </CrmCard>

        <CrmCard step={1}>
          <CrmMetric
            label="Active Demos"
            value={snapshot?.activeDemos?.value ?? '0 Demos'}
            hint={snapshot?.activeDemos?.trend ?? 'Scheduled & In progress'}
            tone={snapshot?.activeDemos?.isPositive ? 'success' : 'default'}
          />
        </CrmCard>

        <CrmCard step={2}>
          <CrmMetric
            label="Renewals (Next 30d)"
            value={snapshot?.renewals?.value ?? '₹ 0'}
            hint={snapshot?.renewals?.trend ?? '0 subscriptions'}
            tone={snapshot?.renewals?.isPositive ? 'warning' : 'default'}
          />
        </CrmCard>

        <CrmCard step={3}>
          <CrmMetric
            label="Expected MRR"
            value={snapshot?.mrr?.value ?? '₹ 0.0L'}
            hint={snapshot?.mrr?.trend ?? 'From pipeline leads'}
            tone={snapshot?.mrr?.isPositive ? 'success' : 'default'}
          />
        </CrmCard>
      </div>

      {/* ── Main Operational Grid ────────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Left Column: Needs Attention & Approvals */}
        <div className="space-y-6">
          <CrmSection
            title="Needs attention"
            description="High-priority leads and items requiring action"
          >
            {attentionItems.length === 0 ? (
              <div className="py-8 text-center">
                <p className="text-[13px] text-ovs-crm-text-muted">
                  No urgent attention items right now.
                </p>
              </div>
            ) : (
              <div className="-mx-2.5 space-y-1">
                {attentionItems.map((item) => (
                  <div
                    key={item.id}
                    className="flex items-start gap-3 rounded-[14px] p-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
                  >
                    <span className="mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600">
                      <AlertTriangle className="h-3.5 w-3.5" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                          {item.title}
                        </p>
                        <CrmStatusPill tone="yellow">{item.priorityBadge}</CrmStatusPill>
                      </div>
                      <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-secondary">
                        {item.description}
                      </p>
                      <p className="mt-1 text-[10.5px] text-ovs-crm-text-muted">
                        {item.time}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>

          <CrmSection
            title="Pending quotations & approvals"
            description="Draft proposals awaiting commercial review"
          >
            {approvalItems.length === 0 ? (
              <div className="py-8 text-center">
                <p className="text-[13px] text-ovs-crm-text-muted">
                  No pending quotation approvals.
                </p>
              </div>
            ) : (
              <div className="-mx-2.5 space-y-1">
                {approvalItems.map((item) => (
                  <div
                    key={item.id}
                    className="flex items-center gap-3 rounded-[14px] p-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
                  >
                    <CrmAvatar name={item.title} size="md" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                        {item.title}
                      </p>
                      <p className="text-[11.5px] text-ovs-crm-text-muted">
                        By {item.requestedBy} • {item.time}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="text-[13px] font-bold tabular-nums text-ovs-crm-text-primary">
                        {item.amount}
                      </p>
                      <Link
                        href="/company/crm/quotations"
                        className="text-[11px] font-medium text-ovs-crm-accent-blue hover:underline"
                      >
                        Review
                      </Link>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>
        </div>

        {/* Right Column: Pipeline Stages & Live Activity */}
        <div className="space-y-6">
          <CrmSection
            title="Sales pipeline stages"
            description="Active deal distribution across qualification funnel"
          >
            {pipelineStages.length === 0 ? (
              <div className="py-8 text-center">
                <p className="text-[13px] text-ovs-crm-text-muted">No pipeline data recorded yet.</p>
              </div>
            ) : (
              <div className="space-y-2.5 pt-1">
                {pipelineStages.map((stage) => (
                  <div key={stage.id} className="space-y-1">
                    <div className="flex items-center justify-between text-[12px]">
                      <span className="font-semibold text-ovs-crm-text-primary">
                        {stage.stageName}
                      </span>
                      <span className="font-bold tabular-nums text-ovs-crm-text-primary">
                        {stage.count} lead{stage.count === 1 ? '' : 's'}
                      </span>
                    </div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-ovs-crm-surface-inset">
                      <div
                        className="h-full rounded-full bg-ovs-crm-accent-blue transition-all"
                        style={{
                          width: `${Math.min(100, Math.max(5, (stage.count / Math.max(1, totalLeads)) * 100))}%`,
                        }}
                      />
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CrmSection>

          <CrmSection
            title="Live enterprise activity"
            description="Recent audit and business events across CRM"
          >
            {activityFeed.length === 0 ? (
              <div className="py-8 text-center">
                <p className="text-[13px] text-ovs-crm-text-muted">No activity logged yet.</p>
              </div>
            ) : (
              <div className="-mx-2.5 space-y-1">
                {activityFeed.map((act) => (
                  <div
                    key={act.id}
                    className="flex items-center gap-3 rounded-[14px] p-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
                  >
                    <CrmAvatar name={act.employeeName} size="sm" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-[12px] font-medium text-ovs-crm-text-primary">
                        <span className="font-semibold">{act.employeeName}</span> {act.actionText}
                      </p>
                      <p className="text-[10.5px] text-ovs-crm-text-muted">{act.time}</p>
                    </div>
                    {act.businessValue && (
                      <span className="text-[11.5px] font-semibold tabular-nums text-emerald-600">
                        {act.businessValue}
                      </span>
                    )}
                  </div>
                ))}
              </div>
            )}
          </CrmSection>
        </div>
      </div>
    </CompanyShell>
  );
}
