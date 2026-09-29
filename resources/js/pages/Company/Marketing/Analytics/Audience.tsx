import React from 'react';
import { Link } from '@inertiajs/react';
import { ExternalLink, MessageSquare, Users } from 'lucide-react';
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
  CrmStatusPill,
  CrmButton,
  type CrmStatusTone,
} from '@/pages/Company/design';

/**
 * How one audience has actually performed.
 *
 * A segment's own page tells you who is in it; this one tells you whether
 * writing to them was worth doing. So the figure that leads is the reply rate,
 * not the headcount — a big list nobody answers is not a good audience.
 */

interface AudienceModel {
  id: number;
  name: string;
  description: string | null;
  created_at: string;
}

interface CampaignRow {
  id: number;
  name: string;
  status: string;
  recipients: number;
  replies: number;
  created_at: string;
}

interface AudienceProps {
  audience: AudienceModel;
  campaigns: CampaignRow[];
  summary: {
    campaigns_count: number;
    total_recipients: number;
    total_replies: number;
  };
}

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

const shortDate = (iso: string) => {
  const d = new Date(iso);

  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

export default function AudienceAnalyticsDetail({ audience, campaigns, summary }: AudienceProps) {
  const replyRate =
    summary.total_recipients > 0 ? (summary.total_replies / summary.total_recipients) * 100 : 0;

  const best = campaigns.reduce(
    (top, c) =>
      c.recipients > 0 && c.replies / c.recipients > (top ? top.replies / Math.max(top.recipients, 1) : -1)
        ? c
        : top,
    null as CampaignRow | null
  );

  return (
    <MarketingShell title={audience.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={audience.name}
        stats={
          <>
            <CrmPageStat
              icon={MessageSquare}
              value={summary.total_replies}
              caption="People from this segment who wrote back"
            />
            <CrmPageStat
              icon={Users}
              value={summary.total_recipients.toLocaleString('en-IN')}
              caption="Messages this segment has received"
            />
          </>
        }
        actions={
          <>
            <CrmButton href="/company/marketing/analytics">Analytics</CrmButton>
            <CrmButton
              tone="primary"
              icon={ExternalLink}
              href={`/company/marketing/audiences/${audience.id}`}
            >
              Open the segment
            </CrmButton>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        <CrmCard step={0} className="flex flex-col items-center lg:col-span-4">
          <div className="mb-4 w-full">
            <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
              Reply rate
            </h2>
            <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
              The measure of whether this segment is worth writing to
            </p>
          </div>
          <CrmRing
            value={summary.total_replies}
            max={Math.max(summary.total_recipients, 1)}
            color={replyRate >= 5 ? '#2FA96B' : '#E0B21E'}
            label={`${replyRate.toFixed(1)}%`}
            caption={`${summary.total_replies} of ${summary.total_recipients.toLocaleString('en-IN')}`}
            size={150}
          />
        </CrmCard>

        <CrmCard step={1} className="lg:col-span-8">
          <div className="mb-6 flex items-start justify-between gap-4">
            <div className="min-w-0">
              <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                Campaigns sent to this segment
              </p>
              <CrmValue value={summary.campaigns_count} size="hero" />
              <p className="mt-2 text-[11.5px] leading-relaxed text-ovs-crm-text-muted">
                {best && best.recipients > 0
                  ? `"${best.name}" did best — ${((best.replies / best.recipients) * 100).toFixed(1)}% wrote back`
                  : audience.description || 'No campaign has produced a reply yet'}
              </p>
            </div>
            <CrmCardIcon icon={MessageSquare} hue="#2F6BE4" />
          </div>

          <p className="border-t border-ovs-crm-border pt-4 text-[11.5px] text-ovs-crm-text-muted">
            Segment created {shortDate(audience.created_at)}
          </p>
        </CrmCard>
      </div>

      <CrmSection
        step={2}
        title="Campaign by campaign"
        description="Ordered as sent — the bar is how many wrote back"
      >
        {campaigns.length === 0 ? (
          <p className="py-16 text-center text-[13px] text-ovs-crm-text-muted">
            No campaign has been sent to this segment yet.
          </p>
        ) : (
          <div className="-mx-2.5">
            {campaigns.map((campaign) => (
              <Link
                key={campaign.id}
                href={`/company/marketing/campaigns/${campaign.id}`}
                className="flex items-center gap-4 rounded-[14px] px-2.5 py-3 transition-colors hover:bg-ovs-crm-surface-inset"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                    {campaign.name}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    {campaign.recipients.toLocaleString('en-IN')} recipients · {shortDate(campaign.created_at)}
                  </p>
                </div>

                <div className="hidden w-32 shrink-0 sm:block">
                  <CrmHairlineBar
                    value={campaign.replies}
                    max={Math.max(campaign.recipients, 1)}
                    color="#2FA96B"
                    tooltip={`${campaign.replies} of ${campaign.recipients} wrote back`}
                  />
                  <p className="mt-1 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {campaign.recipients > 0
                      ? `${((campaign.replies / campaign.recipients) * 100).toFixed(1)}% replied`
                      : 'not sent'}
                  </p>
                </div>

                <span className="shrink-0">
                  <CrmStatusPill tone={CAMPAIGN_TONE[campaign.status] ?? 'neutral'}>
                    {humanize(campaign.status)}
                  </CrmStatusPill>
                </span>
              </Link>
            ))}
          </div>
        )}
      </CrmSection>
    </MarketingShell>
  );
}
