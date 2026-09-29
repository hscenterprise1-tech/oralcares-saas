import React from 'react';
import { Head, Link } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmCard,
  CrmCardIcon,
  CrmOpenButton,
  CrmValue,
  CrmHairlineBar,
  CrmStatusPill,
  CrmSparkline,
  CrmGradientCard,
  CrmPageStat,
  CrmSection,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  Users,
  UserPlus,
  Megaphone,
  MessageSquare,
  Send,
  Ban,
  AlertTriangle,
  AlertCircle,
  CheckCircle2,
  Clock,
  Upload,
  FileSpreadsheet,
  Camera,
  Snowflake,
  Activity,
} from 'lucide-react';

/* ─────────────────────────────────────────────────── palette */

const HUE = {
  blue: '#2F6BE4',
  teal: '#3E93A3',
  violet: '#7C6FE0',
  green: '#2FA96B',
  yellow: '#E0B21E',
  orange: '#F59245',
  coral: '#EF5B39',
} as const;

/* ─────────────────────────────────────────────────── interfaces */

interface OperationalMetrics {
  contacts: { total: number; today_new: number; untouched: number };
  campaigns: { total_active: number; sending: number; scheduled: number };
  messages_today: { sent: number; delivered: number; failed: number };
  replies: { awaiting_action: number; action_url: string };
  compliance: { opted_out: number; suppressed: number };
}

interface ActivityItem {
  id: string;
  type: 'import' | 'campaign' | 'reply' | 'failed' | 'opt_out';
  title: string;
  detail: string;
  timestamp: string | null;
  status: string;
  action_url?: string;
}

interface ActiveCampaignItem {
  id: number;
  name: string;
  audience_name: string;
  sender_number: string;
  status: string;
  recipients_count: number;
  sent_count: number;
  delivered_count: number;
  failed_count: number;
  replied_count: number;
  progress_percent: number;
  scheduled_at: string | null;
  updated_at: string | null;
}

interface AttentionItem {
  id: string;
  level: 'critical' | 'high' | 'medium';
  title: string;
  description: string;
  action_label: string;
  action_url: string;
  badge: string;
  tone: CrmStatusTone;
}

interface ImportBatchItem {
  id: number;
  original_filename: string;
  created_at: string | null;
  status: string;
  total_rows: number;
  imported_count: number;
  duplicate_count: number;
  invalid_count: number;
  created_by: string;
}

interface InstagramStatus {
  summary: {
    has_connected_account: boolean;
    pending_approval: number;
    scheduled: number;
    publishing_today: number;
    failed: number;
  };
  next_scheduled_post: {
    id: number;
    topic: string;
    caption: string;
    scheduled_at: string | null;
    image_url: string | null;
  } | null;
}

interface SafetyGovernance {
  emergency_freeze_enabled: boolean;
  quiet_hours_enabled: boolean;
  quiet_hours_start: string;
  quiet_hours_end: string;
  suppressed_contacts: number;
  opted_out_contacts: number;
  auto_suppress_on_failures: boolean;
  max_consecutive_failures: number;
}

interface IndexProps {
  operationalMetrics: OperationalMetrics;
  todayActivity: ActivityItem[];
  activeCampaigns: ActiveCampaignItem[];
  needsAttention: AttentionItem[];
  recentImports: ImportBatchItem[];
  instagramStatus: InstagramStatus;
  safetyGovernance: SafetyGovernance;
}

/* ─────────────────────────────────────────────────── helpers */

const CAMPAIGN_TONE: Record<string, CrmStatusTone> = {
  draft: 'neutral',
  scheduled: 'warn',
  preparing: 'warn',
  sending: 'warn',
  completed: 'good',
  paused: 'warn',
  failed: 'bad',
  cancelled: 'neutral',
};

const formatTime = (iso: string | null) => {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true });
};

const formatDateTime = (iso: string | null) => {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });
};

const humanize = (str: string) => str.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

/* ─────────────────────── CardHead — exact CRM Dashboard pattern */

function CardHead({
  icon,
  title,
  hint,
  href,
  openLabel,
  hue,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  hint?: string;
  href?: string;
  openLabel?: string;
  hue?: string;
}) {
  return (
    <div className="mb-5 flex items-start gap-3">
      <div className="min-w-0 flex-1">
        <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">{title}</h2>
        {hint && <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">{hint}</p>}
      </div>
      {hue && <CrmCardIcon icon={icon as never} hue={hue} />}
      {href && openLabel && <CrmOpenButton href={href} label={openLabel} />}
    </div>
  );
}

/* ─────────────────────── StatCard — exact CRM Dashboard pattern */

function StatCard({
  icon: Icon,
  label,
  value,
  hint,
  href,
  spark,
  sparkVariant = 'line',
  color,
  frost = false,
  step = 0,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: number | null;
  hint?: string;
  href: string;
  spark?: number[];
  sparkVariant?: 'line' | 'bars';
  color: string;
  frost?: boolean;
  step?: number;
}) {
  return (
    <CrmCard className="flex min-h-[168px] flex-col" step={step} frostHue={frost ? color : undefined}>
      <div className="mb-4 flex items-start justify-between gap-2">
        <CrmCardIcon icon={Icon as never} hue={color} />
        <CrmOpenButton href={href} label={`Open ${label}`} />
      </div>

      <p className="text-[13px] text-ovs-crm-text-secondary">{label}</p>

      <div className="mt-auto flex items-end justify-between gap-3 pt-3">
        <div className="min-w-0">
          {value !== null ? (
            <CrmValue value={value} size="major" />
          ) : (
            <span className="text-[18px] font-normal text-ovs-crm-text-muted">No data</span>
          )}
          {hint && <p className="mt-1 truncate text-[11.5px] text-ovs-crm-text-muted">{hint}</p>}
        </div>
        {spark && spark.length > 1 && (
          <CrmSparkline points={spark} color={color} variant={sparkVariant} className="shrink-0" />
        )}
      </div>
    </CrmCard>
  );
}

/* ═══════════════════════════════════════════════════════════════ page */

export default function MarketingOverviewIndex({
  operationalMetrics,
  todayActivity = [],
  activeCampaigns = [],
  needsAttention = [],
  recentImports = [],
  instagramStatus,
  safetyGovernance,
}: IndexProps) {
  const { contacts, campaigns, messages_today, replies, compliance } = operationalMetrics;

  /* Most urgent item drives the gradient hero card */
  const urgentItem = needsAttention[0] ?? null;

  /* Attention rows — sorted so non-zero items surface first */
  const attentionRows = [
    {
      key: 'replies',
      icon: MessageSquare,
      label: 'Conversations awaiting reply',
      count: replies.awaiting_action,
      href: replies.action_url,
      tone: 'warn' as CrmStatusTone,
    },
    {
      key: 'failed',
      icon: AlertCircle,
      label: 'Failed message deliveries',
      count: messages_today.failed,
      href: '/company/marketing/campaigns',
      tone: 'bad' as CrmStatusTone,
    },
    {
      key: 'untouched',
      icon: UserPlus,
      label: 'Untouched contacts',
      count: contacts.untouched,
      href: '/company/marketing/contacts',
      tone: 'warn' as CrmStatusTone,
    },
    {
      key: 'instagram',
      icon: Camera,
      label: 'Instagram posts pending review',
      count: instagramStatus.summary.pending_approval,
      href: '/company/marketing/instagram',
      tone: 'warn' as CrmStatusTone,
    },
    {
      key: 'opted_out',
      icon: Ban,
      label: 'Opted-out contacts',
      count: compliance.opted_out,
      href: '/company/marketing/suppression',
      tone: 'neutral' as CrmStatusTone,
    },
    {
      key: 'scheduled',
      icon: Clock,
      label: 'Campaigns scheduled today',
      count: campaigns.scheduled,
      href: '/company/marketing/campaigns',
      tone: 'neutral' as CrmStatusTone,
    },
  ].sort((a, b) =>
    (a.count > 0) === (b.count > 0) ? b.count - a.count : a.count > 0 ? -1 : 1
  );

  return (
    <MarketingShell title="Marketing Overview">
      <Head title="Marketing Command Center | Real-time Operations" />

      {/* ═══ EMERGENCY FREEZE BANNER ═════════════════════════════════════════ */}
      {safetyGovernance.emergency_freeze_enabled && (
        <div className="mb-7 flex flex-wrap items-center justify-between gap-4 rounded-[24px] bg-ovs-crm-accent-coral-soft p-5">
          <div className="flex items-center gap-3">
            <CrmCardIcon icon={Snowflake} hue={HUE.coral} />
            <div>
              <p className="text-[13px] font-semibold text-ovs-crm-accent-coral-text">
                Emergency Broadcast Freeze is Active
              </p>
              <p className="text-[11.5px] text-ovs-crm-accent-coral-text/80">
                All outbound marketing sends are paused. Queued messages will resume once lifted.
              </p>
            </div>
          </div>
          <CrmOpenButton href="/company/marketing/settings" label="Manage guardrails" />
        </div>
      )}

      {/* ═══ MASTHEAD ════════════════════════════════════════════════════════ */}
      {/*
       * Title and inline figures on one band — the same masthead shape the CRM
       * Overview uses, so moving between modules never changes where the eye starts.
       */}
      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Marketing
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Command Center
          </h1>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat
            icon={Users}
            value={contacts.total.toLocaleString('en-IN')}
            chip={contacts.today_new > 0 ? `+${contacts.today_new} today` : undefined}
            chipHue={HUE.green}
            caption="Total contacts"
          />
          <CrmPageStat
            icon={Megaphone}
            value={campaigns.total_active}
            chip={`${campaigns.sending} live`}
            chipHue={HUE.orange}
            caption="Active campaigns"
          />
          <CrmPageStat
            icon={Send}
            value={messages_today.sent.toLocaleString('en-IN')}
            chip={messages_today.delivered > 0 ? `${messages_today.delivered} delivered` : undefined}
            chipHue={HUE.teal}
            caption="Messages today"
          />
        </div>
      </div>

      {/* ═══ ROW 1 · STAT CARDS (8) + GRADIENT HERO (4) ═════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-8 xl:grid-cols-3">
          <StatCard
            step={1}
            icon={Users}
            label="Total contacts"
            value={contacts.total}
            hint={`${contacts.untouched.toLocaleString('en-IN')} never reached`}
            href="/company/marketing/contacts"
            spark={[contacts.untouched, contacts.total - contacts.untouched]}
            sparkVariant="bars"
            color={HUE.blue}
          />
          <StatCard
            step={2}
            icon={Megaphone}
            label="Active campaigns"
            value={campaigns.total_active}
            hint={`${campaigns.sending} sending · ${campaigns.scheduled} scheduled`}
            href="/company/marketing/campaigns"
            spark={[campaigns.sending, campaigns.scheduled]}
            sparkVariant="bars"
            color={HUE.orange}
          />
          <StatCard
            step={3}
            icon={Send}
            label="Messages today"
            value={messages_today.sent}
            hint={`${messages_today.delivered} delivered · ${messages_today.failed} failed`}
            href="/company/marketing/campaigns"
            spark={[messages_today.delivered, messages_today.failed, messages_today.sent]}
            color={HUE.teal}
            frost
          />
          <StatCard
            step={4}
            icon={MessageSquare}
            label="Replies awaiting"
            value={replies.awaiting_action}
            hint={replies.awaiting_action > 0 ? 'Open inbox to respond' : 'All replies addressed'}
            href={replies.action_url}
            spark={[replies.awaiting_action, 0]}
            sparkVariant="bars"
            color={replies.awaiting_action > 0 ? HUE.coral : HUE.green}
          />
          <StatCard
            step={5}
            icon={Ban}
            label="Opted out"
            value={compliance.opted_out}
            hint={`${compliance.suppressed} suppressed total`}
            href="/company/marketing/suppression"
            spark={[compliance.opted_out, compliance.suppressed]}
            sparkVariant="bars"
            color={HUE.coral}
          />
          <StatCard
            step={6}
            icon={Camera}
            label="Instagram queue"
            value={instagramStatus.summary.pending_approval + instagramStatus.summary.scheduled}
            hint={`${instagramStatus.summary.pending_approval} pending review`}
            href="/company/marketing/instagram"
            spark={[
              instagramStatus.summary.pending_approval,
              instagramStatus.summary.scheduled,
              instagramStatus.summary.publishing_today,
            ]}
            sparkVariant="bars"
            color={HUE.violet}
          />
        </div>

        {/* Gradient hero — most urgent attention item, or all-clear */}
        {urgentItem ? (
          <CrmGradientCard
            className="lg:col-span-4"
            eyebrow="Needs attention"
            timestamp={`${urgentItem.level} priority`}
            headline={urgentItem.title}
            emphasis={urgentItem.badge}
            href={urgentItem.action_url}
            label={urgentItem.action_label}
          />
        ) : (
          <CrmGradientCard
            className="lg:col-span-4"
            tone="calm"
            eyebrow="Needs attention"
            timestamp="Nothing outstanding"
            headline="Campaigns running, inbox"
            emphasis="all clear."
            href="/company/marketing/campaigns"
            label="Open campaigns"
          />
        )}
      </div>

      {/* ═══ ROW 2 · CAMPAIGNS (8) + ATTENTION (4) ══════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* Campaigns — pipeline-style hairline bars (CRM row 2 pattern) */}
        <CrmCard step={7} className="lg:col-span-8">
          <CardHead
            icon={Megaphone}
            hue={HUE.orange}
            title="Active & scheduled campaigns"
            hint="Broadcast queues currently sending or prepared for launch"
            href="/company/marketing/campaigns"
            openLabel="All campaigns"
          />

          {activeCampaigns.length === 0 ? (
            <p className="py-12 text-center text-[13px] text-ovs-crm-text-muted">
              No campaigns are currently sending or scheduled.
            </p>
          ) : (
            <div className="space-y-4">
              {activeCampaigns.map((camp) => (
                <Link key={camp.id} href={`/company/marketing/campaigns/${camp.id}`} className="block group">
                  <div className="mb-1.5 flex items-baseline justify-between gap-3">
                    <span className="truncate text-[13px] text-ovs-crm-text-secondary group-hover:text-ovs-crm-text-primary">
                      {camp.name}
                    </span>
                    <span className="flex items-baseline gap-3">
                      <span className="text-[13px] tabular-nums text-ovs-crm-text-muted">
                        {camp.delivered_count.toLocaleString('en-IN')} delivered
                      </span>
                      <CrmStatusPill tone={CAMPAIGN_TONE[camp.status] ?? 'neutral'}>
                        {humanize(camp.status)}
                      </CrmStatusPill>
                      <CrmValue value={camp.sent_count} unit={`/ ${camp.recipients_count}`} size="minor" />
                    </span>
                  </div>
                  <CrmHairlineBar
                    value={camp.sent_count}
                    max={Math.max(camp.recipients_count, 1)}
                    color={camp.status === 'completed' ? HUE.green : HUE.blue}
                    tooltip={`${camp.name}: ${camp.sent_count} / ${camp.recipients_count} dispatched (${camp.progress_percent}%)`}
                  />
                </Link>
              ))}
            </div>
          )}

          {/* Recent imports below campaigns — same border-top sub-section as CRM */}
          {recentImports.length > 0 && (
            <div className="mt-6 border-t border-ovs-crm-border pt-5">
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Recent contact imports
              </p>
              <div className="-mx-2.5 grid grid-cols-1 gap-0.5 sm:grid-cols-2">
                {recentImports.slice(0, 4).map((imp) => (
                  <div
                    key={imp.id}
                    className="flex items-center gap-2 rounded-[24px] px-2.5 py-2 transition-colors hover:bg-ovs-crm-surface-inset"
                  >
                    <FileSpreadsheet className="h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden="true" />
                    <span
                      className="min-w-0 flex-1 truncate text-[13px] text-ovs-crm-text-primary"
                      title={imp.original_filename}
                    >
                      {imp.original_filename}
                    </span>
                    <CrmStatusPill
                      tone={imp.status === 'completed' ? 'good' : imp.status === 'failed' ? 'bad' : 'warn'}
                    >
                      +{imp.imported_count.toLocaleString('en-IN')}
                    </CrmStatusPill>
                  </div>
                ))}
              </div>
            </div>
          )}
        </CrmCard>

        {/* Attention list — exact CRM "Needs attention" card pattern */}
        <CrmCard step={8} className="lg:col-span-4">
          <CardHead
            icon={AlertTriangle}
            title="Needs attention"
            hint="Work that stops if nobody acts on it"
            hue={HUE.coral}
          />

          <ul className="-mx-2.5 space-y-0.5">
            {attentionRows.map((row) => (
              <li key={row.key}>
                <Link
                  href={row.href}
                  className="flex items-center gap-3 rounded-[24px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
                >
                  <row.icon
                    className={`h-4 w-4 shrink-0 ${row.count === 0 ? 'text-ovs-crm-text-muted' : 'text-ovs-crm-text-secondary'}`}
                    aria-hidden="true"
                  />
                  <span
                    className={`min-w-0 flex-1 truncate text-[13px] ${row.count === 0 ? 'text-ovs-crm-text-muted' : 'text-ovs-crm-text-primary'}`}
                  >
                    {row.label}
                  </span>
                  {row.count === 0 ? (
                    <CrmStatusPill tone="good">Clear</CrmStatusPill>
                  ) : (
                    <CrmStatusPill tone={row.tone}>{row.count}</CrmStatusPill>
                  )}
                </Link>
              </li>
            ))}
          </ul>
        </CrmCard>
      </div>

      {/* ═══ ROW 3 · INSTAGRAM (4) + ACTIVITY (8) ═══════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* Instagram — CRM "closed business" cell pattern with accent bars */}
        <CrmCard step={9} className="lg:col-span-4">
          <CardHead
            icon={Camera}
            hue={HUE.violet}
            title="Instagram content"
            hint="AI-generated post queue and publication status"
            href="/company/marketing/instagram"
            openLabel="Open Instagram studio"
          />

          <div className="grid grid-cols-2 gap-3">
            {[
              { label: 'Pending review', value: instagramStatus.summary.pending_approval, hue: HUE.yellow },
              { label: 'Scheduled', value: instagramStatus.summary.scheduled, hue: HUE.blue },
              { label: 'Published today', value: instagramStatus.summary.publishing_today, hue: HUE.green },
              { label: 'Failed', value: instagramStatus.summary.failed, hue: HUE.coral },
            ].map((cell) => (
              <div key={cell.label}>
                <span
                  className="mb-1.5 block h-[3px] w-8 rounded-full"
                  style={{ backgroundColor: cell.hue }}
                  aria-hidden="true"
                />
                <CrmValue value={cell.value} size="minor" />
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{cell.label}</p>
              </div>
            ))}
          </div>

          <div className="mt-5 border-t border-ovs-crm-border pt-4">
            {instagramStatus.next_scheduled_post ? (
              <>
                <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                  Next scheduled post
                </p>
                <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                  {instagramStatus.next_scheduled_post.topic}
                </p>
                <p className="mt-0.5 font-mono text-[11.5px] text-ovs-crm-text-muted">
                  {formatDateTime(instagramStatus.next_scheduled_post.scheduled_at)}
                </p>
              </>
            ) : (
              <p className="text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                No future posts scheduled. Generate AI content to start building your queue.
              </p>
            )}
          </div>
        </CrmCard>

        {/* Activity feed — exact CRM "Recent activity" card pattern */}
        <CrmCard step={10} className="lg:col-span-8">
          <CardHead
            icon={Activity}
            hue={HUE.teal}
            title="Today's operational activity"
            hint="Real-time chronological events from campaigns, imports and conversations"
          />

          {todayActivity.length === 0 ? (
            <p className="py-10 text-center text-[13px] text-ovs-crm-text-muted">
              Nothing logged yet. Import contacts or launch a campaign to get started.
            </p>
          ) : (
            <ol className="-mx-2.5 grid grid-cols-1 gap-0.5 lg:grid-cols-2">
              {todayActivity.map((event) => {
                const IconComp =
                  event.type === 'import' ? Upload
                  : event.type === 'campaign' ? Megaphone
                  : event.type === 'reply' ? MessageSquare
                  : event.type === 'failed' ? AlertCircle
                  : Ban;

                return (
                  <li key={event.id}>
                    <Link
                      href={event.action_url ?? '#'}
                      className="flex items-center gap-3 rounded-[24px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
                    >
                      <IconComp className="h-4 w-4 shrink-0 text-ovs-crm-text-secondary" aria-hidden="true" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] text-ovs-crm-text-primary">{event.title}</p>
                        <p className="truncate text-[11.5px] text-ovs-crm-text-muted">{event.detail}</p>
                      </div>
                      <span className="shrink-0 text-[11.5px] text-ovs-crm-text-muted">
                        {formatTime(event.timestamp)}
                      </span>
                    </Link>
                  </li>
                );
              })}
            </ol>
          )}
        </CrmCard>
      </div>
    </MarketingShell>
  );
}
