import React from 'react';
import { Link, router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmCard,
  CrmCardIcon,
  CrmSection,
  CrmValue,
  CrmStatusPill,
  CrmButton,
  CrmAvatar,
  useCrmConfirm,
} from '@/pages/Company/design';
import { ArrowRight, BarChart3, Copy, Edit3, Target, Trash2 } from 'lucide-react';
import { describeRules, segmentContactsUrl } from './rules';
import type { CampaignUsage, SegmentDetail, SegmentPreviewContact } from './types';

/**
 * A single Segment: what it targets, how many people it matches right now,
 * and where it has actually been used.
 *
 * There is no member list here. The full list a Segment describes already
 * has a page — Contacts, filtered by these same rules — so "view matching
 * contacts" leads there rather than this page trying to be a second,
 * smaller version of it.
 */

interface ShowProps {
  audience: SegmentDetail;
  campaigns: CampaignUsage[];
  preview: SegmentPreviewContact[];
}

const CAMPAIGN_STATUS_TONE: Record<string, 'good' | 'warn' | 'bad' | 'neutral'> = {
  completed: 'good',
  sending: 'warn',
  preparing: 'warn',
  scheduled: 'warn',
  draft: 'neutral',
  paused: 'warn',
  cancelled: 'bad',
  failed: 'bad',
};

const humanizeStatus = (status: string) => status.charAt(0).toUpperCase() + status.slice(1);

export default function AudienceShow({ audience, campaigns, preview }: ShowProps) {
  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  const handleDuplicate = () => {
    confirm({
      title: `Duplicate "${audience.name}"?`,
      description: 'A copy is created with the same rules, paused, so it can be adjusted before it targets anyone.',
      confirmLabel: 'Create the copy',
      onConfirm: () => router.post(`/company/marketing/audiences/${audience.id}/duplicate`),
    });
  };

  const handleDelete = () => {
    confirm({
      title: `Delete "${audience.name}"?`,
      description:
        'Only the Segment goes — every contact it matched stays exactly where it is. Campaigns that already used it keep their sent history.',
      confirmLabel: 'Delete Segment',
      destructive: true,
      onConfirm: () => router.delete(`/company/marketing/audiences/${audience.id}`),
    });
  };

  const chips = describeRules(audience.rules);
  const contactsUrl = segmentContactsUrl(audience.rules);

  return (
    <MarketingShell title={audience.name}>
      <CrmPageHead
        eyebrow="Marketing"
        title={audience.name}
        actions={
          <>
            <CrmButton href="/company/marketing/audiences">All Segments</CrmButton>
            {/*
              * How this segment has actually performed — every campaign sent
              * to it, with reply rates. The page already existed and was
              * fully built, but nothing linked to it, so it could only be
              * reached by typing the URL.
              */}
            <CrmButton
              icon={BarChart3}
              href={`/company/marketing/analytics/audiences/${audience.id}`}
            >
              Performance
            </CrmButton>
            <CrmButton icon={Copy} onClick={handleDuplicate}>
              Duplicate
            </CrmButton>
            <CrmButton icon={Trash2} onClick={handleDelete}>
              Delete
            </CrmButton>
            <CrmButton tone="primary" icon={Edit3} href={`/company/marketing/audiences/${audience.id}/edit`}>
              Edit rules
            </CrmButton>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* ═══ What this is ═══════════════════════════════════════════ */}
        <CrmCard step={0} className="lg:col-span-5">
          <div className="mb-4 flex items-start justify-between gap-3">
            <div className="flex items-center gap-3">
              <CrmCardIcon icon={Target} hue="#2F6BE4" />
              <div>
                <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                  {audience.name}
                </h2>
                <CrmStatusPill tone={audience.status === 'active' ? 'good' : 'neutral'}>
                  {audience.status === 'active' ? 'Switched on' : 'Paused'}
                </CrmStatusPill>
              </div>
            </div>
          </div>

          {audience.description && (
            <p className="mb-4 text-[12.5px] leading-relaxed text-ovs-crm-text-secondary">
              {audience.description}
            </p>
          )}

          {/* Who this includes, read as a plain AND-chain. */}
          <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Who this includes
          </p>

          {chips.length === 0 ? (
            <p className="text-[13px] text-ovs-crm-text-muted">
              No rules — this Segment currently matches everybody.
            </p>
          ) : (
            <div className="space-y-1.5">
              {chips.map((chip, index) => (
                <div key={chip.key} className="flex items-center gap-2 text-[13px]">
                  {index > 0 && (
                    <span className="text-[11px] font-semibold uppercase tracking-wide text-ovs-crm-text-muted">
                      and
                    </span>
                  )}
                  <span className="rounded-full bg-ovs-crm-surface-inset px-3 py-1 font-medium text-ovs-crm-text-primary">
                    {chip.label}
                  </span>
                </div>
              ))}
            </div>
          )}
        </CrmCard>

        {/* ═══ How many match right now ═══════════════════════════════ */}
        <CrmCard step={1} className="lg:col-span-7">
          <p className="mb-2 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Matching contacts
          </p>
          <CrmValue value={audience.match_count.toLocaleString('en-IN')} size="hero" />
          <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
            Updated based on current contact data — this is never a saved copy.
          </p>

          {preview.length > 0 && (
            <div className="mt-5 space-y-2 border-t border-ovs-crm-border pt-4">
              {preview.map((contact) => (
                <div key={contact.id} className="flex items-center gap-3">
                  <CrmAvatar name={contact.name} size="sm" />
                  <p className="min-w-0 truncate text-[12.5px] text-ovs-crm-text-secondary">
                    <span className="font-medium text-ovs-crm-text-primary">{contact.name}</span>
                    {[contact.organization, contact.city].filter(Boolean).length > 0 &&
                      ` — ${[contact.organization, contact.city].filter(Boolean).join(', ')}`}
                  </p>
                </div>
              ))}
            </div>
          )}

          <Link
            href={contactsUrl}
            className="mt-4 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-ovs-crm-ink hover:underline"
          >
            View matching contacts
            <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </CrmCard>
      </div>

      {/* ═══ Where this has been used ══════════════════════════════════ */}
      <CrmSection
        step={2}
        title="Used in"
        description={
          campaigns.length > 0
            ? `${campaigns.length} campaign${campaigns.length === 1 ? '' : 's'} targeted this Segment`
            : 'Not used in a campaign yet'
        }
      >
        {campaigns.length === 0 ? (
          <p className="py-8 text-center text-[13px] text-ovs-crm-text-muted">
            When a campaign is created against this Segment, it will show up here.
          </p>
        ) : (
          <div className="-mx-2.5">
            {campaigns.map((campaign) => (
              <Link
                key={campaign.id}
                href={`/company/marketing/campaigns/${campaign.id}`}
                className="flex items-center justify-between gap-3 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
              >
                <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                  {campaign.name}
                </p>
                <CrmStatusPill tone={CAMPAIGN_STATUS_TONE[campaign.status] ?? 'neutral'}>
                  {humanizeStatus(campaign.status)}
                </CrmStatusPill>
              </Link>
            ))}
          </div>
        )}
      </CrmSection>

      {confirmDialog}
    </MarketingShell>
  );
}
