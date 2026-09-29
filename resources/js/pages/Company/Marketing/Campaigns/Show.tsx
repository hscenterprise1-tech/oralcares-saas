import React, { useEffect, useState } from 'react';
import { router, usePoll } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmValue,
  CrmHairlineBar,
  CrmRing,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmAvatar,
  CrmPagination,
  CrmTabs,
  CrmTable,
  useCrmConfirm,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
  SelectValue,
} from '@/pages/Company/design/CrmSelectPrimitives';
import {
  Copy,
  MessageSquare,
  PauseCircle,
  PlayCircle,
  RotateCcw,
  Search,
  Send,
  Target,
  XCircle,
} from 'lucide-react';

interface CampaignRecipient {
  id: number;
  phone: string;
  normalized_phone: string;
  status: 'pending' | 'queued' | 'sent' | 'delivered' | 'read' | 'failed' | 'skipped' | 'replied';
  failure_reason: string | null;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  replied_at: string | null;
  contact?: { id: number; name: string; organization: string | null };
}

interface CampaignDetail {
  id: number;
  name: string;
  description: string | null;
  status: 'draft' | 'scheduled' | 'preparing' | 'sending' | 'paused' | 'completed' | 'cancelled' | 'failed';
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
  whatsapp_account?: { verified_name: string; display_phone_number: string };
  audience?: { name: string };
  template?: { name: string };
}

interface Metrics {
  total: number;
  queued: number;
  sent: number;
  delivered: number;
  read: number;
  failed: number;
  skipped: number;
  replied: number;
}

interface AnalyticsDetail {
  summary: {
    total: number;
    delivered: number;
    read: number;
    replies: number;
    failed: number;
    skipped: number;
    leads: number;
    won: number;
  };
  rates: {
    delivery_rate: number;
    read_rate: number;
    reply_rate: number;
    lead_conversion: number;
    win_rate: number;
  };
  time_series: Array<{ date: string; total: number; delivered: number; read_count: number; replies: number }>;
}

interface PaginatedData<T> {
  data: T[];
  current_page: number;
  total: number;
  last_page: number;
  from: number;
  to: number;
  links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface ShowProps {
  campaign: CampaignDetail;
  recipients: PaginatedData<CampaignRecipient>;
  metrics: Metrics;
  analytics: AnalyticsDetail;
  filters: {
    search: string;
    status: string;
  };
}

type TabKey = 'overview' | 'recipients' | 'results';

/** Campaign status → the pill tone that means the same thing. */
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

/** …and the same for one recipient's delivery state. */
const RECIPIENT_TONE: Record<string, CrmStatusTone> = {
  pending: 'neutral',
  queued: 'neutral',
  sent: 'warn',
  delivered: 'good',
  read: 'good',
  replied: 'good',
  failed: 'bad',
  skipped: 'warn',
};

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function CampaignDetailWorkspace({ campaign, recipients, metrics, analytics, filters }: ShowProps) {
  const [activeTab, setActiveTab] = useState<TabKey>('overview');
  const [search, setSearch] = useState(filters.search || '');
  const [status, setStatus] = useState(filters.status || 'all');

  const applyFilters = (newFilters: { search?: string; status?: string }) => {
    router.get(
      `/company/marketing/campaigns/${campaign.id}`,
      {
        search: newFilters.search !== undefined ? newFilters.search : search,
        status: newFilters.status !== undefined ? newFilters.status : status,
      },
      {
        preserveState: true,
        preserveScroll: true,
        only: ['recipients', 'filters'],
      }
    );
  };

  /*
   * A campaign only changes on its own while it is actually sending — a
   * draft sits still until someone launches it, a completed one is done for
   * good. Polling anything else would just be a timer nobody asked for.
   * `metrics` is the only other prop asked for: the recipient list and
   * Results stay on what they last loaded rather than reflowing under
   * someone mid-scroll or mid-filter every few seconds.
   */
  const campaignIsActive = campaign.status === 'preparing' || campaign.status === 'sending';
  const { start: startPolling, stop: stopPolling } = usePoll(4000, { only: ['campaign', 'metrics'] }, { autoStart: false });

  useEffect(() => {
    if (campaignIsActive) {
      startPolling();
    } else {
      stopPolling();
    }
    return () => stopPolling();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [campaignIsActive]);

  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  /*
   * Launching sends real messages to real numbers. Once they leave they cannot
   * be recalled, which is exactly what the dialog needs to say.
   */
  const handleLaunch = () => {
    confirm({
      title: `Send this campaign to ${metrics.total.toLocaleString('en-IN')} contact${metrics.total === 1 ? '' : 's'}?`,
      description:
        'Messages start going out immediately and cannot be recalled once WhatsApp has them. Opted-out and suppressed contacts are skipped automatically.',
      confirmLabel: 'Send it',
      onConfirm: () => router.post(`/company/marketing/campaigns/${campaign.id}/launch`),
    });
  };

  const handlePause = () => {
    confirm({
      title: 'Pause this campaign?',
      description:
        'Messages already handed to WhatsApp still go. The rest wait until you resume — nothing is lost.',
      confirmLabel: 'Pause sending',
      onConfirm: () => router.post(`/company/marketing/campaigns/${campaign.id}/pause`),
    });
  };

  const handleCancel = () => {
    confirm({
      title: 'Cancel this campaign?',
      description:
        'Everything still queued is abandoned and cannot be resumed. Messages already sent are unaffected.',
      confirmLabel: 'Cancel the campaign',
      destructive: true,
      onConfirm: () => router.post(`/company/marketing/campaigns/${campaign.id}/cancel`),
    });
  };

  const handleDuplicate = () => {
    confirm({
      title: 'Duplicate this campaign?',
      description: 'A new draft is created with the same audience and template. Nothing is sent until you launch it.',
      confirmLabel: 'Create the draft',
      onConfirm: () => router.post(`/company/marketing/campaigns/${campaign.id}/duplicate`),
    });
  };

  /*
   * Resume reuses the same launch endpoint a fresh campaign uses — a paused
   * campaign's recipients aren't recreated, only the ones still waiting are
   * picked back up. The label and copy are what change, not the pipeline.
   */
  const handleResume = () => {
    confirm({
      title: 'Resume sending?',
      description: 'Recipients already reached are untouched. The rest pick up where they left off.',
      confirmLabel: 'Resume sending',
      onConfirm: () => router.post(`/company/marketing/campaigns/${campaign.id}/launch`),
    });
  };

  const handleRetryFailed = () => {
    confirm({
      title: `Retry ${metrics.failed.toLocaleString('en-IN')} failed recipient${metrics.failed === 1 ? '' : 's'}?`,
      description:
        'Only recipients WhatsApp rejected are retried. Sent, delivered, read, and replied recipients are untouched.',
      confirmLabel: 'Retry failed',
      onConfirm: () => router.post(`/company/marketing/campaigns/${campaign.id}/retry-failed`),
    });
  };

  /*
   * A sent campaign is a funnel, and the numbers only mean something in
   * sequence: queued → sent → delivered → read → replied, with failures and
   * skips beside them. Eight counters listed as equals hides the two that
   * actually matter — how many got through, and how many wrote back.
   */
  const progress = [
    { label: 'Queued', count: metrics.queued, hue: '#C9CDD6', caption: 'Waiting to go out' },
    { label: 'Sent', count: metrics.sent, hue: '#2F6BE4', caption: 'Handed to WhatsApp' },
    { label: 'Delivered', count: metrics.delivered, hue: '#3E93A3', caption: 'Reached a phone' },
    { label: 'Read', count: metrics.read, hue: '#7C6FE0', caption: 'Opened' },
    { label: 'Replied', count: metrics.replied, hue: '#2FA96B', caption: 'Wrote back' },
  ];

  const problems = [
    { label: 'Failed', count: metrics.failed, hue: '#EF5B39', caption: 'WhatsApp rejected these' },
    { label: 'Skipped', count: metrics.skipped, hue: '#E0B21E', caption: 'Opted out or suppressed' },
  ];

  const deliveryRate = metrics.sent > 0 ? (metrics.delivered / metrics.sent) * 100 : 0;
  const live = campaign.status === 'sending' || campaign.status === 'preparing';
  const launchable = campaign.status === 'draft' || campaign.status === 'scheduled';
  const resumable = campaign.status === 'paused';

  /*
   * Each stage carries the rate it was actually measured against, spelled
   * out — "Reply rate 40%" means nothing until you know it is 40% of the
   * people who read it, not 40% of everyone addressed.
   */
  const s = analytics.summary;
  const r = analytics.rates;
  const chain = [
    { label: 'Delivered', count: s.delivered, hue: '#3E93A3', rate: r.delivery_rate, of: 'of everyone addressed' },
    { label: 'Read', count: s.read, hue: '#7C6FE0', rate: r.read_rate, of: 'of those delivered' },
    { label: 'Replied', count: s.replies, hue: '#E0B21E', rate: r.reply_rate, of: 'of those who read it' },
    { label: 'Became a lead', count: s.leads, hue: '#2F6BE4', rate: r.lead_conversion, of: 'of those who replied' },
    { label: 'Won', count: s.won, hue: '#2FA96B', rate: r.win_rate, of: 'of the leads it produced' },
  ];
  const chainMax = Math.max(s.total, 1);

  return (
    <MarketingShell title={campaign.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={campaign.name}
        stats={
          <>
            <CrmPageStat
              icon={Send}
              value={metrics.delivered.toLocaleString('en-IN')}
              chip={metrics.failed > 0 ? `${metrics.failed} failed` : undefined}
              chipHue="#EF5B39"
              caption="Messages that reached a phone"
            />
            <CrmPageStat
              icon={MessageSquare}
              value={metrics.replied}
              caption="People who wrote back"
            />
          </>
        }
        actions={
          <>
            <CrmButton href="/company/marketing/campaigns">All campaigns</CrmButton>
            <CrmButton icon={Copy} onClick={handleDuplicate}>
              Duplicate
            </CrmButton>
            {metrics.failed > 0 && (
              <CrmButton icon={RotateCcw} onClick={handleRetryFailed}>
                Retry failed
              </CrmButton>
            )}
            {live && (
              <>
                <CrmButton icon={PauseCircle} onClick={handlePause}>
                  Pause
                </CrmButton>
                <CrmButton tone="danger" icon={XCircle} onClick={handleCancel}>
                  Cancel
                </CrmButton>
              </>
            )}
            {launchable && (
              <CrmButton tone="primary" icon={PlayCircle} onClick={handleLaunch}>
                Launch
              </CrmButton>
            )}
            {resumable && (
              <CrmButton tone="primary" icon={PlayCircle} onClick={handleResume}>
                Resume
              </CrmButton>
            )}
          </>
        }
      />

      <div className="mb-4">
        <CrmTabs
          value={activeTab}
          onChange={setActiveTab}
          tabs={[
            { key: 'overview', label: 'Overview' },
            { key: 'recipients', label: 'Recipients', count: recipients.total },
            { key: 'results', label: 'Results' },
          ]}
        />
      </div>

      {/* ═══ Overview ════════════════════════════════════════════════════ */}
      {activeTab === 'overview' && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <CrmCard step={0} className="lg:col-span-7">
            <div className="mb-6 flex items-start justify-between gap-4">
              <div className="min-w-0">
                <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  How far it got
                </p>
                <CrmValue value={metrics.total.toLocaleString('en-IN')} size="hero" />
                <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                  {[campaign.audience?.name, campaign.template?.name].filter(Boolean).join(' · ') ||
                    'No audience or template'}
                </p>
              </div>
              <CrmStatusPill tone={CAMPAIGN_TONE[campaign.status] ?? 'neutral'}>
                {humanize(campaign.status)}
              </CrmStatusPill>
            </div>

            <div className="space-y-4">
              {progress.map((step) => (
                <div key={step.label}>
                  <div className="mb-1.5 flex items-baseline justify-between gap-4">
                    <span className="flex items-center gap-2 text-[12px] font-medium text-ovs-crm-text-secondary">
                      <span
                        className="h-1.5 w-1.5 rounded-full"
                        style={{ backgroundColor: step.hue }}
                        aria-hidden="true"
                      />
                      {step.label}
                      <span className="text-[11.5px] text-ovs-crm-text-muted">{step.caption}</span>
                    </span>
                    <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                      {step.count.toLocaleString('en-IN')}
                    </span>
                  </div>
                  <CrmHairlineBar value={step.count} max={Math.max(metrics.total, 1)} color={step.hue} />
                </div>
              ))}
            </div>
          </CrmCard>

          <div className="grid grid-cols-1 gap-4 lg:col-span-5">
            <CrmCard step={1} className="flex flex-col items-center">
              <div className="mb-4 w-full">
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  Delivery
                </h2>
                <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                  Of the messages WhatsApp accepted
                </p>
              </div>
              <CrmRing
                value={metrics.delivered}
                max={Math.max(metrics.sent, 1)}
                color="#3E93A3"
                label={`${Math.round(deliveryRate)}%`}
                caption={`${metrics.delivered.toLocaleString('en-IN')} of ${metrics.sent.toLocaleString('en-IN')}`}
                size={132}
              />
            </CrmCard>

            {/*
              * Failures and skips are separated from the funnel deliberately:
              * they are not a stage people pass through, they are messages
              * that never went. Mixing them into the ladder makes it lie.
              */}
            <CrmSection step={2} title="Did not go out" description="And why">
              {metrics.failed + metrics.skipped === 0 ? (
                <p className="py-6 text-center text-[13px] text-ovs-crm-text-muted">
                  Nothing was skipped or rejected.
                </p>
              ) : (
                <div className="space-y-3.5">
                  {problems.map((band) => (
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
                        max={Math.max(metrics.total, 1)}
                        color={band.hue}
                      />
                    </div>
                  ))}
                </div>
              )}
            </CrmSection>
          </div>
        </div>
      )}

      {/* ═══ Recipients ══════════════════════════════════════════════════ */}
      {activeTab === 'recipients' && (
        <CrmSection
          step={0}
          title="Recipients"
          description={`${recipients.total.toLocaleString('en-IN')} matching`}
          actions={
            <div className="flex flex-wrap items-center gap-2">
              <Select
                value={status}
                onValueChange={(value) => {
                  setStatus(value);
                  applyFilters({ status: value });
                }}
              >
                <SelectTrigger aria-label="Filter by delivery status" className="h-10 w-auto bg-ovs-crm-surface-inset">
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                  <SelectItem value="queued">Queued</SelectItem>
                  <SelectItem value="sent">Sent</SelectItem>
                  <SelectItem value="delivered">Delivered</SelectItem>
                  <SelectItem value="read">Read</SelectItem>
                  <SelectItem value="replied">Replied</SelectItem>
                  <SelectItem value="failed">Failed</SelectItem>
                  <SelectItem value="skipped">Skipped</SelectItem>
                </SelectContent>
              </Select>

              <div className="relative">
                <Search
                  className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
                  aria-hidden="true"
                />
                <CrmInput
                  type="search"
                  value={search}
                  onChange={(e) => {
                    setSearch(e.target.value);
                    applyFilters({ search: e.target.value });
                  }}
                  placeholder="Search name or number"
                  aria-label="Search recipients"
                  className="w-full pl-9 sm:w-64"
                />
              </div>
            </div>
          }
        >
          {recipients.data.length === 0 ? (
            <p className="py-12 text-center text-[13px] text-ovs-crm-text-muted">
              {search || status !== 'all'
                ? 'Nothing matches these filters.'
                : 'This campaign has no recipients yet.'}
            </p>
          ) : (
            <div className="-mx-2.5">
              {recipients.data.map((recipient) => (
                <div
                  key={recipient.id}
                  className="flex items-center gap-4 rounded-[14px] px-2.5 py-3 hover:bg-ovs-crm-surface-inset"
                >
                  <CrmAvatar name={recipient.contact?.name ?? recipient.phone} size="sm" />

                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {recipient.contact?.name ?? recipient.phone}
                    </p>
                    <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                      {recipient.failure_reason
                        ? recipient.failure_reason
                        : [recipient.phone, recipient.contact?.organization].filter(Boolean).join(' · ')}
                    </p>
                  </div>

                  <span className="shrink-0">
                    <CrmStatusPill tone={RECIPIENT_TONE[recipient.status] ?? 'neutral'}>
                      {humanize(recipient.status)}
                    </CrmStatusPill>
                  </span>
                </div>
              ))}
            </div>
          )}

          <CrmPagination
            links={recipients.links}
            from={recipients.from}
            to={recipients.to}
            total={recipients.total}
            className="mt-4"
          />
        </CrmSection>
      )}

      {/* ═══ Results ═════════════════════════════════════════════════════ */}
      {activeTab === 'results' && (
        <div className="space-y-4">
          <CrmCard step={0}>
            <div className="mb-6 flex items-start justify-between gap-4">
              <div className="min-w-0">
                <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  Addressed
                </p>
                <CrmValue value={s.total.toLocaleString('en-IN')} size="hero" />
                <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                  Followed all the way from delivery to revenue
                </p>
              </div>
              {s.leads > 0 && (
                <div className="text-right">
                  <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Leads</p>
                  <p className="text-[15px] font-semibold text-ovs-crm-text-primary">
                    {s.leads}
                    {s.won > 0 && <span className="ml-1.5 text-[11.5px] font-normal text-ovs-crm-accent-teal">{s.won} won</span>}
                  </p>
                </div>
              )}
            </div>

            <div className="space-y-4">
              {chain.map((step) => (
                <div key={step.label}>
                  <div className="mb-1.5 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <span className="flex items-center gap-2 text-[12px] font-medium text-ovs-crm-text-secondary">
                      <span
                        className="h-1.5 w-1.5 rounded-full"
                        style={{ backgroundColor: step.hue }}
                        aria-hidden="true"
                      />
                      {step.label}
                      <span className="text-[11.5px] text-ovs-crm-text-muted">
                        {step.rate}% {step.of}
                      </span>
                    </span>
                    <span className="text-[13px] tabular-nums text-ovs-crm-text-primary">
                      {step.count.toLocaleString('en-IN')}
                    </span>
                  </div>
                  <CrmHairlineBar value={step.count} max={chainMax} color={step.hue} />
                </div>
              ))}
            </div>
          </CrmCard>

          {analytics.time_series.length > 1 && (
            <CrmSection step={1} title="Day by day" description="How sending played out over time" icon={Target}>
              <CrmTable
                columns={[
                  { key: 'date', header: 'Date', primary: true, cell: (row) => row.date },
                  { key: 'total', header: 'Sent', align: 'right', cell: (row) => row.total.toLocaleString('en-IN') },
                  { key: 'delivered', header: 'Delivered', align: 'right', cell: (row) => row.delivered.toLocaleString('en-IN') },
                  { key: 'read', header: 'Read', align: 'right', cell: (row) => row.read_count.toLocaleString('en-IN'), hideOnMobile: true },
                  { key: 'replies', header: 'Replied', align: 'right', cell: (row) => row.replies.toLocaleString('en-IN'), hideOnMobile: true },
                ]}
                rows={analytics.time_series}
                rowKey={(row) => row.date}
              />
            </CrmSection>
          )}
        </div>
      )}

      {confirmDialog}
    </MarketingShell>
  );
}
