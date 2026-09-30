import React from 'react';
import { router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmCardIcon,
  CrmSection,
  CrmValue,
  CrmHairlineBar,
  CrmRing,
  CrmButton,
  CrmSelect,
  CrmFilterBar,
  CrmTable,
  CrmStatusPill,
  type CrmFilterDef,
  type CrmColumn,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  Download,
  Filter,
  Megaphone,
  MessageSquare,
  Send,
  Target,
} from 'lucide-react';

interface KpiItem {
  value: number;
  prev_value: number;
  pct_change: number;
}

interface FunnelStage {
  key: string;
  label: string;
  count: number;
  conversion_rate: number;
  overall_rate: number;
  drop_off_rate: number;
}

interface CampaignRow {
  id: number;
  name: string;
  status: string;
  audience_name: string;
  recipients: number;
  sent: number;
  delivered: number;
  read: number;
  replies: number;
  failed: number;
  leads: number;
  won: number;
  delivery_rate: number;
  read_rate: number;
  reply_rate: number;
  win_rate: number;
  created_at: string;
}

interface SourceAttribution {
  source_key: string;
  source_label: string;
  leads: number;
  won: number;
  lost: number;
  win_rate: number;
}

interface FilterOption {
  id: number;
  name: string;
}

interface AnalyticsPayload {
  kpis: {
    messages_delivered: KpiItem;
    replies: KpiItem;
    crm_leads: KpiItem;
    won_leads: KpiItem;
  };
  funnel: {
    stages: FunnelStage[];
    summary: Record<string, number>;
  };
  campaign_performance: CampaignRow[];
  lead_attribution: SourceAttribution[];
  safety_metrics: {
    active: number;
    opted_out: number;
    suppressed: number;
    total: number;
  };
  filter: {
    period: string;
    start_date: string | null;
    end_date: string | null;
    campaign_id: number | null;
    audience_id: number | null;
    whatsapp_account_id: number | null;
  };
}

interface IndexProps {
  analytics: AnalyticsPayload;
  filterOptions: {
    campaigns: FilterOption[];
    audiences: FilterOption[];
    whatsappAccounts: FilterOption[];
  };
}

/** Post status → pill tone — same tones Audience.tsx already uses for the same campaign statuses. */
const CAMPAIGN_TONE: Record<string, CrmStatusTone> = {
  draft: 'neutral',
  scheduled: 'warn',
  preparing: 'warn',
  sending: 'warn',
  paused: 'warn',
  completed: 'good',
  cancelled: 'neutral',
  failed: 'bad',
};

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

// Cycling accent per funnel stage tile — same treatment as the CRM Dashboard's
// Sales Pipeline Overview, so a stage-breakdown grid reads the same way in
// both modules instead of Marketing's version looking flatter/plainer.
/**
 * The funnel ramp — the same sequence of hues the CRM pipeline uses, so a
 * stage means the same colour wherever it appears in the product. The block
 * this replaces painted in raw Tailwind slate, blue, purple and amber, none of
 * which are in this palette.
 */
const STAGE_HUES = ['#C9CDD6', '#2F6BE4', '#3E93A3', '#E0B21E', '#F59245', '#2FA96B'] as const;

type DrillDownKey = 'campaign_id' | 'audience_id' | 'whatsapp_account_id';

export default function AnalyticsIndex({ analytics, filterOptions }: IndexProps) {
  const { kpis, funnel, campaign_performance = [], lead_attribution = [], safety_metrics, filter } = analytics;

  /*
   * The funnel is this page's spine. The server already computes each stage's
   * conversion and drop-off, so the page's job is to make the drop-off legible
   * — a stage list of raw counts hides where people actually leave, which is
   * the only thing anyone reads a funnel for.
   */
  const funnelMax = Math.max(...funnel.stages.map((s) => s.count), 1);
  const worstDrop = funnel.stages.reduce(
    (worst, stage) => (stage.drop_off_rate > (worst?.drop_off_rate ?? -1) ? stage : worst),
    null as FunnelStage | null
  );

  const reachRate =
    safety_metrics.total > 0 ? (safety_metrics.active / safety_metrics.total) * 100 : 0;

  /*
   * One filter contract for the whole page: every section below reads from
   * the same `MarketingAnalyticsFilter` the server builds from these exact
   * query params, so there is no way for a filter to move the KPI band
   * without also moving the funnel, the campaign table and lead attribution.
   * Period is the primary, always-present dimension; the other three are
   * optional drill-downs — omitted entirely from the request (not sent as
   * "all") when unset, which is what keeps the page in its organization-wide
   * view.
   */
  const currentDrillDowns: Record<DrillDownKey, string> = {
    campaign_id: filter.campaign_id ? String(filter.campaign_id) : 'all',
    audience_id: filter.audience_id ? String(filter.audience_id) : 'all',
    whatsapp_account_id: filter.whatsapp_account_id ? String(filter.whatsapp_account_id) : 'all',
  };

  const buildParams = (overrides: Partial<Record<'period' | DrillDownKey, string>>) => {
    const next = { period: filter.period, ...currentDrillDowns, ...overrides };
    const params: Record<string, string> = { period: next.period };

    (['campaign_id', 'audience_id', 'whatsapp_account_id'] as DrillDownKey[]).forEach((key) => {
      if (next[key] !== 'all') {
        params[key] = next[key];
      }
    });

    return params;
  };

  const applyFilters = (overrides: Partial<Record<'period' | DrillDownKey, string>>) =>
    router.get('/company/marketing/analytics', buildParams(overrides), {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });

  const setPeriod = (period: string) => applyFilters({ period });
  const handleDrillDownChange = (key: string, value: string) => applyFilters({ [key]: value } as Partial<Record<DrillDownKey, string>>);
  const clearDrillDowns = () => applyFilters({ campaign_id: 'all', audience_id: 'all', whatsapp_account_id: 'all' });

  const hasActiveDrillDown = filter.campaign_id !== null || filter.audience_id !== null || filter.whatsapp_account_id !== null;

  const exportHref = `/company/marketing/analytics/export?${new URLSearchParams(buildParams({})).toString()}`;

  const drillDownFilters: CrmFilterDef[] = [
    {
      key: 'campaign_id',
      label: 'Campaign',
      value: currentDrillDowns.campaign_id,
      options: [
        { value: 'all', label: 'All campaigns' },
        ...filterOptions.campaigns.map((c) => ({ value: String(c.id), label: c.name })),
      ],
    },
    {
      key: 'audience_id',
      label: 'Audience',
      value: currentDrillDowns.audience_id,
      options: [
        { value: 'all', label: 'All audiences' },
        ...filterOptions.audiences.map((a) => ({ value: String(a.id), label: a.name })),
      ],
    },
    {
      key: 'whatsapp_account_id',
      label: 'WhatsApp account',
      value: currentDrillDowns.whatsapp_account_id,
      options: [
        { value: 'all', label: 'All accounts' },
        ...filterOptions.whatsappAccounts.map((w) => ({ value: String(w.id), label: w.name })),
      ],
    },
  ];

  const campaignColumns: CrmColumn<CampaignRow>[] = [
    {
      key: 'name',
      header: 'Campaign',
      primary: true,
      cell: (row) => (
        <div className="min-w-0">
          <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">{row.name}</p>
          <p className="truncate text-[11.5px] text-ovs-crm-text-muted">{row.audience_name}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => <CrmStatusPill tone={CAMPAIGN_TONE[row.status] ?? 'neutral'}>{humanize(row.status)}</CrmStatusPill>,
    },
    {
      key: 'sent',
      header: 'Sent',
      align: 'right',
      hideOnMobile: true,
      cell: (row) => <span className="tabular-nums">{row.sent.toLocaleString('en-IN')}</span>,
    },
    {
      key: 'delivered',
      header: 'Delivered',
      align: 'right',
      cell: (row) => <span className="tabular-nums">{row.delivered.toLocaleString('en-IN')}</span>,
    },
    {
      key: 'read',
      header: 'Read',
      align: 'right',
      hideOnMobile: true,
      cell: (row) => <span className="tabular-nums">{row.read.toLocaleString('en-IN')}</span>,
    },
    {
      key: 'replies',
      header: 'Replied',
      align: 'right',
      cell: (row) => <span className="tabular-nums">{row.replies.toLocaleString('en-IN')}</span>,
    },
    {
      key: 'delivery_rate',
      header: 'Delivery',
      align: 'right',
      hideOnMobile: true,
      cell: (row) => <span className="tabular-nums text-ovs-crm-text-muted">{row.delivery_rate.toFixed(1)}%</span>,
    },
    {
      key: 'read_rate',
      header: 'Read rate',
      align: 'right',
      hideOnMobile: true,
      cell: (row) => <span className="tabular-nums text-ovs-crm-text-muted">{row.read_rate.toFixed(1)}%</span>,
    },
    {
      key: 'reply_rate',
      header: 'Reply rate',
      align: 'right',
      hideOnMobile: true,
      cell: (row) => <span className="tabular-nums text-ovs-crm-text-muted">{row.reply_rate.toFixed(1)}%</span>,
    },
    {
      key: 'leads',
      header: 'Leads',
      align: 'right',
      cell: (row) => <span className="tabular-nums font-medium text-ovs-crm-text-primary">{row.leads}</span>,
    },
    {
      key: 'won',
      header: 'Won',
      align: 'right',
      cell: (row) => <span className="tabular-nums font-medium text-ovs-crm-success">{row.won}</span>,
    },
  ];

  return (
    <MarketingShell title="Analytics">
      <CrmPageHead
        eyebrow="Marketing"
        title="Analytics"
        stats={
          <>
            <CrmPageStat
              icon={Target}
              value={kpis.crm_leads.value}
              chip={kpis.won_leads.value > 0 ? `${kpis.won_leads.value} won` : undefined}
              chipHue="#2FA96B"
              caption="Leads marketing produced"
            />
            <CrmPageStat
              icon={Send}
              value={kpis.messages_delivered.value.toLocaleString('en-IN')}
              caption="Messages that reached a phone"
            />
            <CrmPageStat
              icon={MessageSquare}
              value={kpis.replies.value}
              caption="People who wrote back"
            />
          </>
        }
        actions={
          <>
            <CrmSelect
              aria-label="Reporting period"
              value={filter.period}
              onChange={(e) => setPeriod(e.target.value)}
              className="w-auto"
            >
              {/*
                * These values are the canonical period keys the backend
                * resolves to (MarketingAnalyticsFilter::PERIOD_ALIASES /
                * calculateDateBoundaries()), not shorthand — the select is
                * controlled by `filter.period`, which the server always
                * echoes back as the canonical value, so the option values
                * have to match it exactly or the dropdown shows nothing
                * selected.
                */}
              <option value="last_7_days">Last 7 days</option>
              <option value="last_30_days">Last 30 days</option>
              <option value="last_90_days">Last 90 days</option>
              <option value="all_time">All time</option>
            </CrmSelect>
            <CrmButton
              icon={Download}
              onClick={() => window.open(exportHref, '_blank', 'noreferrer')}
            >
              Export
            </CrmButton>
          </>
        }
      />

      {/*
        * Period is the primary, always-visible dimension (it lives in the
        * header above, next to Export). Campaign / Audience / WhatsApp
        * account are optional drill-downs — a flat three-select bar rather
        * than the "More" panel CrmFilterBar can also do, since three filters
        * is short enough to just show, and hiding them behind a toggle would
        * work against people actually finding and using them. No search:
        * these are day-to-day-sized option lists a native select already
        * handles (type-ahead included), not something to build a picker for.
        */}
      <CrmFilterBar
        className="mb-4"
        filters={drillDownFilters}
        onFilterChange={handleDrillDownChange}
        onClearAll={hasActiveDrillDown ? clearDrillDowns : undefined}
        resultCount={campaign_performance.length}
        resultNoun="campaign"
      />

      {/* ═══ Where people fall out ══════════════════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={0} className="lg:col-span-8">
          <div className="mb-6 flex items-start justify-between gap-4">
            <div>
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                The funnel
              </p>
              <CrmValue value={kpis.crm_leads.value} size="hero" />
              <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                {worstDrop && worstDrop.drop_off_rate > 0
                  ? `Most people leave at ${worstDrop.label.toLowerCase()} — ${Math.round(worstDrop.drop_off_rate)}% drop off there`
                  : 'Not enough traffic yet to say where people leave'}
              </p>
            </div>
            <CrmCardIcon icon={Filter} hue="#2F6BE4" />
          </div>

          <div className="space-y-4">
            {funnel.stages.map((stage, index) => (
              <div key={stage.key}>
                <div className="mb-1.5 flex items-baseline justify-between gap-4">
                  <span className="flex items-center gap-2 text-[12px] font-medium text-ovs-crm-text-secondary">
                    <span
                      className="h-1.5 w-1.5 rounded-full"
                      style={{ backgroundColor: STAGE_HUES[index % STAGE_HUES.length] }}
                      aria-hidden="true"
                    />
                    {stage.label}
                    {stage.drop_off_rate > 0 && (
                      <span className="text-[11.5px] text-ovs-crm-text-muted">
                        {Math.round(stage.drop_off_rate)}% leave here
                      </span>
                    )}
                  </span>
                  <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                    {stage.count.toLocaleString('en-IN')}
                  </span>
                </div>
                <CrmHairlineBar
                  value={stage.count}
                  max={funnelMax}
                  color={STAGE_HUES[index % STAGE_HUES.length]}
                  tooltip={`${stage.count} · ${Math.round(stage.overall_rate)}% of everyone who entered`}
                />
              </div>
            ))}
          </div>
        </CrmCard>

        <CrmCard step={1} className="flex flex-col items-center lg:col-span-4">
          <div className="mb-4 w-full">
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Still reachable
            </h2>
            <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
              A list shrinks every time someone opts out
            </p>
          </div>
          <CrmRing
            value={safety_metrics.active}
            max={Math.max(safety_metrics.total, 1)}
            color="#2FA96B"
            label={`${Math.round(reachRate)}%`}
            caption={`${safety_metrics.opted_out + safety_metrics.suppressed} off limits`}
            size={150}
          />
        </CrmCard>
      </div>

      {/* ═══ Which campaigns actually produced something ════════════════ */}
      <CrmSection
        step={2}
        title="Campaign performance"
        description="Delivery is not the point — leads are"
        className="mb-4"
      >
        <CrmTable
          columns={campaignColumns}
          rows={campaign_performance}
          rowKey={(row) => row.id}
          onRowClick={(row) => router.visit(`/company/marketing/campaigns/${row.id}`)}
          isFiltered={hasActiveDrillDown}
          empty={{
            icon: Megaphone,
            title: 'No campaign has run yet',
            description: 'Once a campaign sends, its performance shows up here.',
          }}
          emptyFiltered={{
            title: 'No campaign matches these filters',
            description: 'Try a different period, campaign, audience, or account — or clear the filters above.',
          }}
        />
      </CrmSection>

      <CrmSection
        step={3}
        title="Where leads came from"
        description="And which sources actually close"
      >
        {lead_attribution.length === 0 ? (
          <p className="py-12 text-center text-[13px] text-ovs-crm-text-muted">
            No leads attributed in this period.
          </p>
        ) : (
          <div className="grid grid-cols-1 gap-x-8 gap-y-3.5 sm:grid-cols-2">
            {lead_attribution.map((source) => (
              <div key={source.source_key}>
                <div className="mb-1.5 flex items-baseline justify-between gap-3">
                  <span className="truncate text-[12px] font-medium text-ovs-crm-text-secondary">
                    {source.source_label}
                  </span>
                  <span className="shrink-0 text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {source.leads} lead{source.leads === 1 ? '' : 's'} · {Math.round(source.win_rate)}% won
                  </span>
                </div>
                <CrmHairlineBar
                  value={source.won}
                  max={Math.max(source.leads, 1)}
                  color="#2FA96B"
                  tooltip={`${source.won} won, ${source.lost} lost`}
                />
              </div>
            ))}
          </div>
        )}
      </CrmSection>
    </MarketingShell>
  );
}
