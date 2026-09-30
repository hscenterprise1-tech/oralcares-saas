import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmSection,
  CrmValue,
  CrmHairlineBar,
  CrmStatusPill,
  CrmButton,
  CrmInput,
  CrmAvatar,
  CrmPagination,
  useCrmConfirm,
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  ArrowUpRight,
  CheckCircle2,
  Copy,
  Megaphone,
  Plus,
  Search,
} from 'lucide-react';

interface CampaignItem {
  id: number;
  name: string;
  description: string | null;
  status: 'draft' | 'scheduled' | 'preparing' | 'sending' | 'paused' | 'completed' | 'cancelled' | 'failed';
  created_at: string;
  whatsapp_account?: { verified_name: string; display_phone_number: string };
  audience?: { name: string };
  template?: { name: string };
}

interface Metrics {
  total: number;
  sending: number;
  completed: number;
  drafts: number;
}

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

interface IndexProps {
  campaigns: PaginatedData<CampaignItem>;
  metrics: Metrics;
  filters: {
    search: string;
    status: string;
  };
}

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

const humanize = (value: string) => value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function CampaignsDirectory({ campaigns, metrics, filters }: IndexProps) {
  const [search, setSearch] = useState(filters.search || '');
  const [status, setStatus] = useState(filters.status || 'all');
  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  const applyFilters = (newFilters: { search?: string; status?: string }) => {
    router.get(
      '/company/marketing/campaigns',
      {
        search: newFilters.search !== undefined ? newFilters.search : search,
        status: newFilters.status !== undefined ? newFilters.status : status,
      },
      {
        preserveState: true,
        preserveScroll: true,
      }
    );
  };

  const handleDuplicate = (id: number) => {
    confirm({
      title: 'Duplicate this campaign?',
      description: 'A new draft is created with the same audience and template. Nothing is sent until you launch it.',
      confirmLabel: 'Create the draft',
      onConfirm: () => router.post(`/company/marketing/campaigns/${id}/duplicate`),
    });
  };

  /*
   * A campaign is a batch of messages with a lifecycle, so the board is
   * organised around where each one currently is rather than around its
   * columns. The counts come off the metrics the controller already sends.
   */
  const lifecycle = [
    { key: 'draft', label: 'Draft', count: metrics.drafts, hue: '#C9CDD6', caption: 'Not sent to anyone yet' },
    { key: 'sending', label: 'Sending', count: metrics.sending, hue: '#E0B21E', caption: 'Going out right now' },
    { key: 'completed', label: 'Completed', count: metrics.completed, hue: '#2FA96B', caption: 'Finished sending' },
  ];

  const openCampaign = (id: number) => router.visit(`/company/marketing/campaigns/${id}`);

  return (
    <MarketingShell title="Campaigns">
      <CrmPageHead
        eyebrow="Marketing"
        title="Campaigns"
        stats={
          <>
            <CrmPageStat
              icon={Megaphone}
              value={metrics.total}
              chip={metrics.sending > 0 ? `${metrics.sending} sending` : undefined}
              chipHue="#E0B21E"
              caption="Campaigns built so far"
            />
            <CrmPageStat
              icon={CheckCircle2}
              value={metrics.completed}
              caption="Have finished sending"
            />
          </>
        }
        actions={
          <CrmButton tone="primary" icon={Plus} href="/company/marketing/campaigns/create">
            New campaign
          </CrmButton>
        }
      />

      {/* ═══ Where every campaign stands ════════════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        {lifecycle.map((stage, index) => {
          const on = status === stage.key;

          return (
            <CrmCard key={stage.key} step={index} className="!p-0">
              <button
                type="button"
                onClick={() => {
                  const next = on ? 'all' : stage.key;
                  setStatus(next);
                  applyFilters({ status: next });
                }}
                aria-pressed={on}
                className="block w-full rounded-[24px] p-5 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
              >
                <div className="mb-3 flex items-start justify-between gap-2">
                  <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                    {stage.label}
                  </p>
                  <span
                    className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                    style={{ backgroundColor: stage.hue }}
                    aria-hidden="true"
                  />
                </div>
                <CrmValue value={stage.count} size="major" />
                <p className="mt-1.5 text-[11.5px] text-ovs-crm-text-muted">
                  {on ? 'Showing these · tap to clear' : stage.caption}
                </p>
                <CrmHairlineBar
                  className="mt-3"
                  value={stage.count}
                  max={Math.max(metrics.total, 1)}
                  color={stage.hue}
                />
              </button>
            </CrmCard>
          );
        })}
      </div>

      {/* ═══ The campaigns ══════════════════════════════════════════════ */}
      <CrmSection
        step={3}
        title="All campaigns"
        description={`${campaigns.total} campaign${campaigns.total === 1 ? '' : 's'} matching`}
        actions={
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
              placeholder="Search campaign names"
              aria-label="Search campaigns"
              className="w-full pl-9 sm:w-72"
            />
          </div>
        }
      >
        {campaigns.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || status !== 'all'
                ? 'Nothing matches these filters.'
                : 'No campaigns yet. A campaign sends one approved template to one audience.'}
            </p>
            <CrmButton
              tone="primary"
              icon={Plus}
              className="mt-4"
              href="/company/marketing/campaigns/create"
            >
              Build the first one
            </CrmButton>
          </div>
        ) : (
          <div className="-mx-2.5">
            {campaigns.data.map((campaign) => (
              <div
                key={campaign.id}
                role="button"
                tabIndex={0}
                onClick={() => openCampaign(campaign.id)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openCampaign(campaign.id);
                  }
                }}
                className="group flex cursor-pointer items-center gap-4 rounded-[14px] px-2.5 py-3 transition-colors hover:bg-ovs-crm-surface-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
              >
                <CrmAvatar name={campaign.name} size="sm" />

                <div className="min-w-0 flex-1">
                  <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                    {campaign.name}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    {[
                      campaign.audience?.name,
                      campaign.template?.name,
                      campaign.whatsapp_account?.display_phone_number,
                    ]
                      .filter(Boolean)
                      .join(' · ') || 'No audience or template picked yet'}
                  </p>
                </div>

                <span className="hidden w-28 shrink-0 justify-end md:flex">
                  <CrmStatusPill tone={CAMPAIGN_TONE[campaign.status] ?? 'neutral'}>
                    {humanize(campaign.status)}
                  </CrmStatusPill>
                </span>

                {/*
                  * Duplicate and the open affordance are visible at rest —
                  * a row action nobody can see until they hover it is, for
                  * a touch user, a row action that does not exist. Hover
                  * still darkens the row and the arrow; it just is not what
                  * brings them into being.
                  */}
                <span className="flex shrink-0 items-center">
                  <CrmButton
                    size="sm"
                    icon={Copy}
                    onClick={(e) => {
                      e.stopPropagation();
                      handleDuplicate(campaign.id);
                    }}
                  >
                    Duplicate
                  </CrmButton>
                </span>

                <ArrowUpRight
                  className="h-4 w-4 shrink-0 text-ovs-crm-text-muted transition-colors group-hover:text-ovs-crm-text-primary"
                  aria-hidden="true"
                />
              </div>
            ))}
          </div>
        )}

        <CrmPagination
          links={campaigns.links}
          from={campaigns.from}
          to={campaigns.to}
          total={campaigns.total}
          className="mt-4"
        />
      </CrmSection>
      {confirmDialog}
    </MarketingShell>
  );
}
