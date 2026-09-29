import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { MarketingShell } from '../components/MarketingShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmStatusPill,
  CrmCardIcon,
  CrmButton,
  CrmInput,
  CrmSelect,
  CrmPagination,
  useCrmConfirm,
} from '@/pages/Company/design';
import { Copy, Layers, Plus, Search, Target, Trash2 } from 'lucide-react';
import { describeRules } from './rules';
import type { SegmentListItem } from './types';

/**
 * Segments — reusable targeting definitions.
 *
 * Deliberately not another Contacts list. Contacts is where someone works a
 * list of people; this is where someone names a slice of it once so a
 * campaign can point at it again next month. Each row answers three
 * questions and nothing more: what does this target, how many people match
 * right now, and has it actually been used — the count and the chips come
 * straight from the same rules Contacts itself filters by, so there is no
 * second definition of "matches" to keep in sync with the first.
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

interface IndexProps {
  audiences: PaginatedData<SegmentListItem>;
  filters: {
    search: string;
    status: string;
  };
  metrics: {
    total_audiences: number;
    active_audiences: number;
  };
}

const relativeDay = (iso: string | null): string => {
  if (!iso) return '';
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000);

  if (days <= 0) return 'today';
  if (days === 1) return 'yesterday';
  if (days < 30) return `${days} days ago`;

  return new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
};

export default function AudiencesIndex({
  audiences,
  filters,
  metrics = { total_audiences: 0, active_audiences: 0 },
}: IndexProps) {
  const [search, setSearch] = useState(filters.search || '');
  const [status, setStatus] = useState(filters.status || 'all');
  const { confirm, dialog: confirmDialog } = useCrmConfirm();

  const applyFilters = (next: { search?: string; status?: string }) => {
    router.get(
      '/company/marketing/audiences',
      {
        search: next.search !== undefined ? next.search : search,
        status: next.status !== undefined ? next.status : status,
      },
      { preserveState: true, preserveScroll: true }
    );
  };

  const openSegment = (id: number) => router.visit(`/company/marketing/audiences/${id}`);

  const handleDuplicate = (id: number, name: string) => {
    confirm({
      title: `Duplicate "${name}"?`,
      description: 'A copy is created with the same rules, paused, so it can be adjusted before it targets anyone.',
      confirmLabel: 'Create the copy',
      onConfirm: () => router.post(`/company/marketing/audiences/${id}/duplicate`, {}, { preserveScroll: true }),
    });
  };

  /*
   * The reassurance here matters: people hesitate to delete a Segment because
   * they think it takes the contacts with it. It never can — a Segment never
   * stored any contacts to begin with.
   */
  const handleDelete = (id: number, name: string) => {
    confirm({
      title: `Delete "${name}"?`,
      description:
        'Only the Segment goes — every contact it matched stays exactly where it is. Campaigns that already used it keep their sent history.',
      confirmLabel: 'Delete Segment',
      destructive: true,
      onConfirm: () => router.delete(`/company/marketing/audiences/${id}`, { preserveScroll: true }),
    });
  };

  return (
    <MarketingShell title="Segments">
      <CrmPageHead
        eyebrow="Marketing"
        title="Segments"
        stats={
          <>
            <CrmPageStat icon={Layers} value={metrics.total_audiences} caption="Reusable targeting definitions" />
            <CrmPageStat
              icon={Target}
              value={metrics.active_audiences}
              chip={
                metrics.total_audiences > metrics.active_audiences
                  ? `${metrics.total_audiences - metrics.active_audiences} paused`
                  : undefined
              }
              chipHue="#E0B21E"
              caption="Switched on"
            />
          </>
        }
        actions={
          <CrmButton tone="primary" icon={Plus} href="/company/marketing/audiences/create">
            Create Segment
          </CrmButton>
        }
      />

      <CrmSection
        step={0}
        title="Segments"
        description={`${audiences.total} segment${audiences.total === 1 ? '' : 's'}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <CrmSelect
              aria-label="Filter by status"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                applyFilters({ status: e.target.value });
              }}
              className="w-auto"
            >
              <option value="all">All</option>
              <option value="active">Switched on</option>
              <option value="paused">Paused</option>
            </CrmSelect>

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
                placeholder="Search Segments"
                aria-label="Search Segments"
                className="w-full pl-9 sm:w-64"
              />
            </div>
          </div>
        }
      >
        {audiences.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || status !== 'all'
                ? 'Nothing matches these filters.'
                : 'No Segments yet. A Segment is a saved set of targeting rules a campaign can point at.'}
            </p>
            <CrmButton tone="primary" icon={Plus} className="mt-4" href="/company/marketing/audiences/create">
              Create the first one
            </CrmButton>
          </div>
        ) : (
          <div className="-mx-2.5">
            {audiences.data.map((segment) => {
              const chips = describeRules(segment.rules);

              return (
                <div
                  key={segment.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => openSegment(segment.id)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      openSegment(segment.id);
                    }
                  }}
                  className="group flex cursor-pointer items-start gap-3 rounded-[14px] px-2.5 py-3.5 transition-colors hover:bg-ovs-crm-surface-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
                >
                  <CrmCardIcon icon={Target} hue="#2F6BE4" />

                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                        {segment.name}
                      </p>
                      <CrmStatusPill tone={segment.status === 'active' ? 'good' : 'neutral'}>
                        {segment.status === 'active' ? 'On' : 'Paused'}
                      </CrmStatusPill>
                    </div>

                    <p className="mt-0.5 text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                      {segment.match_count.toLocaleString('en-IN')} contact
                      {segment.match_count === 1 ? '' : 's'} currently match
                    </p>

                    {chips.length > 0 && (
                      <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {chips.map((chip) => (
                          <span
                            key={chip.key}
                            className="rounded-full bg-ovs-crm-surface-inset px-2 py-0.5 text-[11px] font-medium text-ovs-crm-text-secondary"
                          >
                            {chip.label}
                          </span>
                        ))}
                      </div>
                    )}

                    <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
                      {segment.campaigns_count > 0
                        ? `Used in ${segment.campaigns_count} campaign${segment.campaigns_count === 1 ? '' : 's'}`
                        : 'Not used in a campaign yet'}
                      {segment.last_used_at ? ` · Last used ${relativeDay(segment.last_used_at)}` : ''}
                    </p>
                  </div>

                  <span className="flex shrink-0 items-center gap-1">
                    <CrmButton
                      size="sm"
                      icon={Copy}
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDuplicate(segment.id, segment.name);
                      }}
                    >
                      Duplicate
                    </CrmButton>
                    <CrmButton
                      size="sm"
                      icon={Trash2}
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDelete(segment.id, segment.name);
                      }}
                    >
                      Delete
                    </CrmButton>
                  </span>
                </div>
              );
            })}
          </div>
        )}

        <CrmPagination
          links={audiences.links}
          from={audiences.from}
          to={audiences.to}
          total={audiences.total}
          className="mt-4"
        />
      </CrmSection>

      {confirmDialog}
    </MarketingShell>
  );
}
