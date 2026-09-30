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
  type CrmStatusTone,
} from '@/pages/Company/design';
import {
  CheckCircle2,
  Copy,
  PauseCircle,
  PlayCircle,
  Plus,
  Search,
  Zap,
} from 'lucide-react';
import { triggerLabel, TRIGGER_ICON, humanize } from './labels';

interface AutomationItem {
  id: number;
  name: string;
  description: string | null;
  status: 'draft' | 'active' | 'paused' | 'archived';
  trigger_type: string;
  executions_count: number;
  created_at: string;
  updated_at: string;
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
  automations: PaginatedData<AutomationItem>;
  metrics: {
    totalAutomations: number;
    activeCount: number;
    runningExecutionsCount: number;
    completedTodayCount: number;
  };
  filters: {
    search: string;
    status: string;
  };
}

/** Automation status → the pill tone that means the same thing. */
const AUTOMATION_TONE: Record<string, CrmStatusTone> = {
  draft: 'neutral',
  active: 'good',
  paused: 'warn',
  archived: 'neutral',
};

export default function AutomationsIndex({ automations, metrics, filters }: IndexProps) {
  const [search, setSearch] = useState(filters.search || '');
  const [status, setStatus] = useState(filters.status || 'all');

  const applyFilters = (newFilters: { search?: string; status?: string }) => {
    router.get(
      '/company/marketing/automations',
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

  const handleToggleActive = (id: number, currentStatus: string) => {
    if (currentStatus === 'active') {
      router.post(`/company/marketing/automations/${id}/pause`);
    } else {
      router.post(`/company/marketing/automations/${id}/activate`);
    }
  };

  const handleDuplicate = (id: number) => {
    router.post(`/company/marketing/automations/${id}/duplicate`);
  };

  /*
   * An automation is a rule that runs without anyone watching, so the two
   * things worth knowing are how many are switched on and whether their runs
   * are getting through. The old strip counted four unrelated totals; these
   * two questions are what someone opens this page to answer.
   */
  const openAutomation = (id: number) => router.visit(`/company/marketing/automations/${id}`);

  const lifecycle = [
    {
      key: 'active',
      label: 'Switched on',
      count: metrics.activeCount,
      hue: '#2FA96B',
      caption: 'Running without you',
    },
    {
      key: 'draft',
      label: 'Not live',
      count: Math.max(metrics.totalAutomations - metrics.activeCount, 0),
      hue: '#C9CDD6',
      caption: 'Built but never switched on',
    },
  ];

  return (
    <MarketingShell title="Automations">
      <CrmPageHead
        eyebrow="Marketing"
        title="Automations"
        stats={
          <>
            <CrmPageStat
              icon={Zap}
              value={metrics.activeCount}
              chip={
                metrics.runningExecutionsCount > 0
                  ? `${metrics.runningExecutionsCount} running`
                  : undefined
              }
              chipHue="#2F6BE4"
              caption="Rules running without you"
            />
            <CrmPageStat
              icon={CheckCircle2}
              value={metrics.completedTodayCount}
              caption="Runs finished today"
            />
          </>
        }
        actions={
          <CrmButton tone="primary" icon={Plus} href="/company/marketing/automations/create">
            New automation
          </CrmButton>
        }
      />

      {/* ═══ On, or built and left off ══════════════════════════════════ */}
      <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        {lifecycle.map((band, index) => {
          const on = status === band.key;

          return (
            <CrmCard key={band.key} step={index} className="!p-0">
              <button
                type="button"
                onClick={() => {
                  const next = on ? 'all' : band.key;
                  setStatus(next);
                  applyFilters({ status: next });
                }}
                aria-pressed={on}
                className="block w-full rounded-[24px] p-5 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
              >
                <div className="mb-3 flex items-start justify-between gap-2">
                  <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
                    {band.label}
                  </p>
                  <span
                    className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full"
                    style={{ backgroundColor: band.hue }}
                    aria-hidden="true"
                  />
                </div>
                <CrmValue value={band.count} size="major" />
                <p className="mt-1.5 text-[11.5px] text-ovs-crm-text-muted">
                  {on ? 'Showing these · tap to clear' : band.caption}
                </p>
                <CrmHairlineBar
                  className="mt-3"
                  value={band.count}
                  max={Math.max(metrics.totalAutomations, 1)}
                  color={band.hue}
                />
              </button>
            </CrmCard>
          );
        })}
      </div>

      {/* ═══ The rules ══════════════════════════════════════════════════ */}
      <CrmSection
        step={2}
        title="All automations"
        description={`${automations.total} rule${automations.total === 1 ? '' : 's'} matching`}
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
              placeholder="Search automation names"
              aria-label="Search automations"
              className="w-full pl-9 sm:w-72"
            />
          </div>
        }
      >
        {automations.data.length === 0 ? (
          <div className="mx-auto max-w-md py-16 text-center">
            {search || status !== 'all' ? (
              <p className="text-[13px] text-ovs-crm-text-muted">Nothing matches these filters.</p>
            ) : (
              <>
                <p className="text-[13px] text-ovs-crm-text-primary">
                  A campaign sends planned outreach to a segment. An automation reacts on its own.
                </p>
                <p className="mt-1.5 text-[13px] text-ovs-crm-text-muted">
                  For example: the moment a contact replies on WhatsApp, turn it into a CRM lead — no one has to be watching.
                </p>
              </>
            )}
            <CrmButton
              tone="primary"
              icon={Plus}
              className="mt-4"
              href="/company/marketing/automations/create"
            >
              Build the first one
            </CrmButton>
          </div>
        ) : (
          <div className="-mx-2.5">
            {automations.data.map((automation) => {
              const live = automation.status === 'active';

              return (
                <div
                  key={automation.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => openAutomation(automation.id)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      openAutomation(automation.id);
                    }
                  }}
                  className="group flex cursor-pointer items-center gap-4 rounded-[14px] px-2.5 py-3 transition-colors hover:bg-ovs-crm-surface-inset focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ovs-crm-ink/20"
                >
                  <CrmAvatar name={automation.name} size="sm" />

                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium text-ovs-crm-text-primary">
                      {automation.name}
                    </p>
                    <p className="flex items-center gap-1 truncate text-[11.5px] text-ovs-crm-text-muted">
                      {(() => {
                        const TriggerIcon = TRIGGER_ICON[automation.trigger_type];
                        return TriggerIcon ? <TriggerIcon className="h-3 w-3 shrink-0" aria-hidden="true" /> : null;
                      })()}
                      <span className="truncate">
                        {triggerLabel(automation.trigger_type)}
                        {' · '}
                        {automation.executions_count === 0
                          ? 'never run'
                          : `${automation.executions_count} run${automation.executions_count === 1 ? '' : 's'}`}
                      </span>
                    </p>
                  </div>

                  <span className="hidden w-24 shrink-0 justify-end md:flex">
                    <CrmStatusPill tone={AUTOMATION_TONE[automation.status] ?? 'neutral'}>
                      {humanize(automation.status)}
                    </CrmStatusPill>
                  </span>

                  <span className="flex shrink-0 items-center gap-1">
                    <CrmButton
                      size="sm"
                      icon={Copy}
                      onClick={(e) => {
                        e.stopPropagation();
                        handleDuplicate(automation.id);
                      }}
                    >
                      Duplicate
                    </CrmButton>
                    {automation.status !== 'archived' && (
                      <CrmButton
                        size="sm"
                        tone={live ? 'secondary' : 'primary'}
                        icon={live ? PauseCircle : PlayCircle}
                        onClick={(e) => {
                          e.stopPropagation();
                          handleToggleActive(automation.id, automation.status);
                        }}
                      >
                        {live ? 'Pause' : 'Switch on'}
                      </CrmButton>
                    )}
                  </span>
                </div>
              );
            })}
          </div>
        )}

        <CrmPagination
          links={automations.links}
          from={automations.from}
          to={automations.to}
          total={automations.total}
          className="mt-4"
        />
      </CrmSection>
    </MarketingShell>
  );
}
