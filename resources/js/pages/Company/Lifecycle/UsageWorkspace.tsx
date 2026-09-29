import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  AlertTriangle,
  Boxes,
  Building2,
  GaugeCircle,
  Users,
  Stethoscope,
  TrendingUp,
  Search,
  Filter,
  RefreshCw,
  ChevronRight,
  ExternalLink,
  ArrowUpDown,
  CheckCircle2,
  ShieldAlert,
  Sparkles,
  Plus,
  Clock,
  Briefcase,
} from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageHead,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmHairlineBar,
  CrmDataState,
  CrmPagination,
  CrmSection,
  CrmTable,
  healthTone,
  churnTone,
  lifecycleStageTone,
  utilizationTone,
  humanize,
  CrmModal,
  inr,
  type CrmStatusTone,
  type PaginatorLink,
} from '@/pages/Company/design';

import { LifecycleShell } from './components/LifecycleShell';

const HUE = {
  teal: '#3E93A3',
  blue: '#2F6BE4',
  orange: '#F59245',
  coral: '#EF5B39',
  green: '#2FA96B',
  purple: '#8B5CF6',
  gray: '#6B7488',
} as const;

interface Row {
  organization_id: number;
  account: string;
  owner: {
    id: number;
    name: string;
    email: string;
  } | null;
  has_subscription: boolean;
  subscription_status: string;
  plan_name: string | null;
  mrr: number | null;
  mrr_formatted: string;
  purchased_seats: number;
  used_seats: number;
  seat_utilization_pct: number | null;
  purchased_branches: number;
  active_branches: number;
  branch_utilization_pct: number | null;
  purchased_doctors: number | null;
  modules: string[];
  near_seat_limit: boolean;
  over_seat_limit: boolean;
  near_branch_limit: boolean;
  over_branch_limit: boolean;
  over_capacity: boolean;
  near_capacity: boolean;
  expansion_opportunity: boolean;
  potential_arr_upsell: number;
  health_score: number;
  churn_risk: string;
  renewal_probability: number | null;
  stage: string;
  days_to_expiry: number | null;
  ends_at: string | null;
  last_activity_at: string | null;
}

interface Props {
  rows: Row[];
  paginator: {
    links: PaginatorLink[];
    from: number | null;
    to: number | null;
    total: number | null;
    current_page?: number;
    last_page?: number;
    per_page?: number;
  };
  filters: {
    search?: string;
    type?: string;
    flag?: string;
    threshold?: string;
    expansion?: string;
    churn_risk?: string;
    stage?: string;
    subscription_status?: string;
    owner_id?: number | string;
    sort_by?: string;
    direction?: string;
    per_page?: number;
  };
  summary: {
    accounts_on_page: number;
    total_accounts: number;
    over_capacity: number;
    near_capacity: number;
    expansion_opportunities: number;
    near_seat_limit: number;
    over_seat_limit: number;
    without_subscription: number;
  };
  owners?: Array<{ id: number; name: string; email: string }>;
}

export default function UsageWorkspace({ rows, paginator, filters, summary, owners = [] }: Props) {
  const [dealModalRow, setDealModalRow] = useState<Row | null>(null);
  const [dealType, setDealType] = useState<'expansion' | 'renewal'>('expansion');
  const [expectedArr, setExpectedArr] = useState<string>('240000');
  const [dealNotes, setDealNotes] = useState<string>('');
  const [isSubmittingDeal, setIsSubmittingDeal] = useState<boolean>(false);

  const activeTab = filters.type ?? filters.flag ?? 'all';

  const apply = (changes: Record<string, string | number | undefined>) => {
    const next = { ...filters, ...changes };
    Object.keys(next).forEach((k) => {
      if (next[k as keyof typeof next] === undefined || next[k as keyof typeof next] === '' || next[k as keyof typeof next] === null) {
        delete next[k as keyof typeof next];
      }
    });

    router.get('/company/customer-success/usage', next as any, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const handleCreateDealSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!dealModalRow) return;

    setIsSubmittingDeal(true);
    router.post(
      `/company/customer-success/accounts/${dealModalRow.organization_id}/create-deal`,
      {
        deal_type: dealType,
        expected_revenue: parseFloat(expectedArr) || (dealType === 'expansion' ? 240000 : 50000),
        notes: dealNotes,
      },
      {
        onSuccess: () => {
          setDealModalRow(null);
          setDealNotes('');
          setIsSubmittingDeal(false);
        },
        onError: () => {
          setIsSubmittingDeal(false);
        },
      }
    );
  };

  const openDealModal = (row: Row, type: 'expansion' | 'renewal' = 'expansion') => {
    setDealModalRow(row);
    setDealType(type);
    setExpectedArr(type === 'expansion' ? '240000' : '60000');
    setDealNotes(
      type === 'expansion'
        ? `Capacity expansion deal for ${row.account}. Seat utilization at ${row.seat_utilization_pct ?? 0}%, branches at ${row.branch_utilization_pct ?? 0}%.`
        : `Renewal deal for ${row.account}. Current contract expires in ${row.days_to_expiry ?? 0} days.`
    );
  };

  const resetFilters = () => {
    router.get(
      '/company/customer-success/usage',
      {},
      { preserveState: true, preserveScroll: true, replace: true }
    );
  };

  const hasActiveFilters = Boolean(
    filters.search ||
      (filters.type && filters.type !== 'all') ||
      filters.threshold ||
      filters.expansion ||
      filters.churn_risk ||
      filters.stage ||
      filters.subscription_status ||
      filters.owner_id
  );

  return (
    /*
     * Named "Usage", because that is what the rail calls it.
     *
     * The page titled itself "Capacity & Expansion Worklist" while the one link
     * that reaches it says "Usage" — so the reader clicked one word and arrived
     * somewhere with a different name at the top. What it is *for* is the
     * description line's job.
     */
    <LifecycleShell title="Usage">
      <Head title="Usage | Customer Success" />

      <CrmPageHead
        eyebrow="Customer success"
        title="Usage"
        description="What each account is consuming against what it bought — and who is at or over capacity."
        stats={
          <>
          <CrmPageStat
            icon={Building2}
            value={summary.total_accounts}
            caption="Customer Accounts"
          />
          <CrmPageStat
            icon={AlertTriangle}
            value={summary.over_capacity}
            chip={summary.over_capacity > 0 ? 'Over Cap' : undefined}
            chipHue={HUE.coral}
            caption="Over Capacity"
          />
          <CrmPageStat
            icon={GaugeCircle}
            value={summary.near_capacity}
            chip={summary.near_capacity > 0 ? '≥80% Near' : undefined}
            chipHue={HUE.orange}
            caption="Near Capacity"
          />
          <CrmPageStat
            icon={Sparkles}
            value={summary.expansion_opportunities}
            chip="+₹2.4L ARR"
            chipHue={HUE.green}
            caption="Expansion Potential"
          />
          </>
        }
      />

      {/* ── 2. Capacity Worklist Horizon Tabs ─────────────────────────────────── */}
      <div className="mb-4 flex flex-wrap items-center gap-1.5 rounded-xl border border-ovs-crm-border bg-white p-1 shadow-sm">
        {[
          { key: 'all', label: 'All Accounts', count: summary.total_accounts },
          { key: 'over_capacity', label: 'Over Capacity', count: summary.over_capacity, tone: 'bad' },
          { key: 'near_capacity', label: 'Near Capacity (≥80%)', count: summary.near_capacity, tone: 'warn' },
          { key: 'seats', label: 'Seats Focus', count: undefined },
          { key: 'branches', label: 'Branches Focus', count: undefined },
          { key: 'doctors', label: 'Doctors Focus', count: undefined },
          { key: 'expansion', label: 'Expansion Opportunities', count: summary.expansion_opportunities, tone: 'good' },
        ].map((tab) => {
          const isActive = activeTab === tab.key;
          return (
            <button
              key={tab.key}
              type="button"
              onClick={() => apply({ type: tab.key, flag: undefined })}
              className={`inline-flex items-center gap-2 rounded-lg px-3.5 py-1.5 text-[12.5px] font-medium transition-all ${
                isActive
                  ? 'bg-ovs-crm-surface-inset text-ovs-crm-text-primary shadow-xs'
                  : 'text-ovs-crm-text-muted hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary'
              }`}
            >
              <span>{tab.label}</span>
              {tab.count !== undefined && (
                <span
                  className={`rounded-full px-1.5 py-0.2 text-[10.5px] font-semibold tabular-nums ${
                    tab.tone === 'bad'
                      ? 'bg-ovs-crm-danger-soft text-ovs-crm-danger-text'
                      : tab.tone === 'warn'
                      ? 'bg-ovs-crm-warning-soft text-ovs-crm-warning-text'
                      : tab.tone === 'good'
                      ? 'bg-ovs-crm-success-soft text-ovs-crm-success-text'
                      : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary'
                  }`}
                >
                  {tab.count}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {/* ── 3. Filters Toolbar ──────────────────────────────────────────────── */}
      <CrmCard className="mb-4 px-5 py-4">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6">
          <div className="lg:col-span-2">
            <CrmFormField label="Search">
              <div className="relative">
                <CrmInput
                  type="search"
                  placeholder="Search account, contact or clinic..."
                  defaultValue={filters.search ?? ''}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      apply({ search: (e.target as HTMLInputElement).value || undefined });
                    }
                  }}
                  className="pl-8 text-[12.5px]"
                />
                <Search className="pointer-events-none absolute left-2.5 top-2.5 h-3.5 w-3.5 text-ovs-crm-text-muted" />
              </div>
            </CrmFormField>
          </div>

          <div>
            <CrmFormField label="Capacity Threshold">
              <CrmSelect
                value={filters.threshold ?? ''}
                onChange={(e) => apply({ threshold: e.target.value || undefined })}
              >
                <option value="">All thresholds</option>
                <option value="80">≥ 80% (Approaching cap)</option>
                <option value="90">≥ 90% (Critical capacity)</option>
                <option value="100">100%+ (Over capacity)</option>
              </CrmSelect>
            </CrmFormField>
          </div>

          <div>
            <CrmFormField label="Expansion Radar">
              <CrmSelect
                value={filters.expansion ?? ''}
                onChange={(e) => apply({ expansion: e.target.value || undefined })}
              >
                <option value="">All expansion states</option>
                <option value="yes">Expansion Potential (+₹ ARR)</option>
                <option value="no">Standard Capacity</option>
              </CrmSelect>
            </CrmFormField>
          </div>

          <div>
            <CrmFormField label="Churn Risk">
              <CrmSelect
                value={filters.churn_risk ?? ''}
                onChange={(e) => apply({ churn_risk: e.target.value || undefined })}
              >
                <option value="">All churn risks</option>
                <option value="low">Low Risk</option>
                <option value="medium">Medium Risk</option>
                <option value="high">High Risk</option>
                <option value="critical">Critical Risk</option>
              </CrmSelect>
            </CrmFormField>
          </div>

          <div>
            <CrmFormField label="Sort By">
              <CrmSelect
                value={`${filters.sort_by ?? 'seat_utilization'}:${filters.direction ?? 'desc'}`}
                onChange={(e) => {
                  const [sort_by, direction] = e.target.value.split(':');
                  apply({ sort_by, direction });
                }}
              >
                <option value="seat_utilization:desc">Seat Util: High to Low</option>
                <option value="seat_utilization:asc">Seat Util: Low to High</option>
                <option value="branch_utilization:desc">Branch Util: High to Low</option>
                <option value="health_score:asc">Health: At-Risk First</option>
                <option value="health_score:desc">Health: High to Low</option>
                <option value="account:asc">Account Name: A-Z</option>
                <option value="days_to_expiry:asc">Renewal: Soonest First</option>
                <option value="last_activity:desc">Recent Activity First</option>
              </CrmSelect>
            </CrmFormField>
          </div>
        </div>

        {/* Extended filters row */}
        <div className="mt-3 flex flex-wrap items-center justify-between border-t border-ovs-crm-border pt-3 text-[12px]">
          <div className="flex flex-wrap items-center gap-3">
            {owners.length > 0 && (
              <div className="flex items-center gap-1.5">
                <span className="text-ovs-crm-text-muted">Owner:</span>
                <select
                  value={filters.owner_id ?? ''}
                  onChange={(e) => apply({ owner_id: e.target.value || undefined })}
                  className="rounded-md border border-ovs-crm-border bg-white px-2 py-1 text-[11.5px] text-ovs-crm-text-primary focus:outline-hidden"
                >
                  <option value="">All Owners</option>
                  {owners.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            <div className="flex items-center gap-1.5">
              <span className="text-ovs-crm-text-muted">Subscription:</span>
              <select
                value={filters.subscription_status ?? ''}
                onChange={(e) => apply({ subscription_status: e.target.value || undefined })}
                className="rounded-md border border-ovs-crm-border bg-white px-2 py-1 text-[11.5px] text-ovs-crm-text-primary focus:outline-hidden"
              >
                <option value="">All Subscriptions</option>
                <option value="active">Active Subscription</option>
                <option value="trialing">Trialing</option>
                <option value="none">No Subscription</option>
              </select>
            </div>

            <div className="flex items-center gap-1.5">
              <span className="text-ovs-crm-text-muted">Lifecycle Stage:</span>
              <select
                value={filters.stage ?? ''}
                onChange={(e) => apply({ stage: e.target.value || undefined })}
                className="rounded-md border border-ovs-crm-border bg-white px-2 py-1 text-[11.5px] text-ovs-crm-text-primary focus:outline-hidden"
              >
                <option value="">All Stages</option>
                <option value="live">Live</option>
                <option value="onboarding">Onboarding</option>
                <option value="not_provisioned">Not Provisioned</option>
                <option value="churned">Churned</option>
              </select>
            </div>

            {hasActiveFilters && (
              <button
                type="button"
                onClick={resetFilters}
                className="inline-flex items-center gap-1 rounded px-2 py-1 text-[11.5px] font-medium text-ovs-crm-text-muted hover:bg-ovs-crm-surface-inset hover:text-ovs-crm-text-primary"
              >
                <RefreshCw className="h-3 w-3" />
                Reset Filters
              </button>
            )}
          </div>

          <div className="text-ovs-crm-text-muted">
            Showing <span className="font-semibold text-ovs-crm-text-primary tabular-nums">{rows.length}</span> of{' '}
            <span className="font-semibold text-ovs-crm-text-primary tabular-nums">{paginator.total ?? rows.length}</span> accounts
          </div>
        </div>
      </CrmCard>

      {/* ── 4. Main Operational Capacity Table ─────────────────────────────── */}
      <CrmSection
        flush
        title="Capacity"
        description={
          (paginator?.total ?? 0) > 0
            ? `${paginator?.from ?? 0}–${paginator?.to ?? 0} of ${paginator?.total ?? 0}`
            : undefined
        }
      >
        <CrmTable<Row>
          columns={[
            {
              key: 'account',
              header: 'Account',
              mobileLabel: 'Account',
              primary: true,
              width: '22%',
              cell: (row) => (
                <span className="block min-w-0">
                  <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                    {row.account}
                  </span>
                  <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                    {row.plan_name ?? 'No plan'}
                    {row.owner?.name ? ` · ${row.owner.name}` : ''}
                  </span>
                </span>
              ),
            },
            {
              key: 'seats',
              header: 'Seats',
              mobileLabel: 'Seats',
              width: '160px',
              cell: (row) => (
                <span className="block">
                  <span className="block text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {row.used_seats} / {row.purchased_seats || '—'}
                  </span>
                  {row.seat_utilization_pct !== null && (
                    <CrmStatusPill tone={utilizationTone(row.seat_utilization_pct)}>
                      {row.seat_utilization_pct}%
                    </CrmStatusPill>
                  )}
                </span>
              ),
            },
            {
              key: 'branches',
              header: 'Branches',
              mobileLabel: 'Branches',
              width: '160px',
              hideOnMobile: true,
              cell: (row) => (
                <span className="block">
                  <span className="block text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                    {row.active_branches} / {row.purchased_branches || '—'}
                  </span>
                  {row.branch_utilization_pct !== null && (
                    <CrmStatusPill tone={utilizationTone(row.branch_utilization_pct)}>
                      {row.branch_utilization_pct}%
                    </CrmStatusPill>
                  )}
                </span>
              ),
            },
            {
              key: 'state',
              header: 'Capacity state',
              mobileLabel: 'Capacity state',
              width: '150px',
              cell: (row) =>
                row.over_capacity ? (
                  <CrmStatusPill tone="bad">Over capacity</CrmStatusPill>
                ) : row.near_capacity ? (
                  <CrmStatusPill tone="warn">Near limit</CrmStatusPill>
                ) : (
                  <CrmStatusPill tone="good">Within plan</CrmStatusPill>
                ),
            },
            {
              key: 'health',
              header: 'Health',
              mobileLabel: 'Health',
              width: '150px',
              hideOnMobile: true,
              cell: (row) => (
                <span className="flex flex-wrap items-center gap-1.5">
                  <CrmStatusPill tone={healthTone(row.health_score)}>{row.health_score}/100</CrmStatusPill>
                  <CrmStatusPill tone={churnTone(row.churn_risk)}>{humanize(row.churn_risk)}</CrmStatusPill>
                </span>
              ),
            },
            {
              key: 'stage',
              header: 'Stage',
              mobileLabel: 'Stage',
              width: '130px',
              hideOnMobile: true,
              cell: (row) => (
                <CrmStatusPill tone={lifecycleStageTone(row.stage)}>{humanize(row.stage)}</CrmStatusPill>
              ),
            },
          ]}
          rows={rows}
          rowKey={(row) => row.organization_id}
          onRowClick={(row) =>
            router.visit(
              route('company.customer-success.accounts.show', { organization: row.organization_id, tab: 'usage' })
            )
          }
          isFiltered={hasActiveFilters}
          rowActions={(row) => (
            <CrmButton
              size="sm"
              tone="ghost"
              icon={Sparkles}
              onClick={(event) => {
                event.stopPropagation();
                openDealModal(row, 'expansion');
              }}
              aria-label={`Raise an expansion deal for ${row.account}`}
            >
              Expand
            </CrmButton>
          )}
          empty={{
            icon: GaugeCircle,
            title: 'Nobody is near a limit',
            description:
              'Accounts appear here as they approach or exceed the seats and branches they bought.',
          }}
          emptyFiltered={{
            title: 'No accounts match this capacity filter',
            description: 'Try another horizon tab, or reset the search and threshold filters.',
            action: <CrmButton onClick={resetFilters}>Reset filters</CrmButton>,
          }}
          footer={
            paginator ? (
              <CrmPagination
                links={paginator.links}
                from={paginator.from}
                to={paginator.to}
                total={paginator.total}
              />
            ) : undefined
          }
        />
      </CrmSection>
      {/* ── 5. Create Expansion / Renewal Deal Modal ──────────────────────── */}
      {dealModalRow && (
        <CrmModal
          open={true}
          onClose={() => setDealModalRow(null)}
          title={`Create ${dealType === 'expansion' ? 'Capacity Expansion' : 'Renewal'} Deal`}
          description={`Raise a commercial deal for ${dealModalRow.account} directly into the CRM pipeline.`}
        >
          <form onSubmit={handleCreateDealSubmit} className="space-y-4 pt-2">
            <div className="rounded-lg border border-ovs-crm-border bg-ovs-crm-surface-inset/70 p-3 text-[12px]">
              <div className="flex justify-between py-0.5">
                <span className="text-ovs-crm-text-muted">Account:</span>
                <span className="font-semibold text-ovs-crm-text-primary">{dealModalRow.account}</span>
              </div>
              <div className="flex justify-between py-0.5">
                <span className="text-ovs-crm-text-muted">Current Plan:</span>
                <span className="font-medium text-ovs-crm-text-primary">{dealModalRow.plan_name ?? 'Standard'}</span>
              </div>
              <div className="flex justify-between py-0.5">
                <span className="text-ovs-crm-text-muted">Seats Utilisation:</span>
                <span className="font-medium tabular-nums text-ovs-crm-text-primary">
                  {dealModalRow.used_seats} / {dealModalRow.purchased_seats} ({dealModalRow.seat_utilization_pct ?? 0}%)
                </span>
              </div>
              <div className="flex justify-between py-0.5">
                <span className="text-ovs-crm-text-muted">Branches Utilisation:</span>
                <span className="font-medium tabular-nums text-ovs-crm-text-primary">
                  {dealModalRow.active_branches} / {dealModalRow.purchased_branches} ({dealModalRow.branch_utilization_pct ?? 0}%)
                </span>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <CrmFormField label="Deal Type">
                  <CrmSelect
                    value={dealType}
                    onChange={(e) => {
                      const t = e.target.value as 'expansion' | 'renewal';
                      setDealType(t);
                      setExpectedArr(t === 'expansion' ? '240000' : '60000');
                    }}
                  >
                    <option value="expansion">Capacity Expansion (+ARR)</option>
                    <option value="renewal">Contract Renewal</option>
                  </CrmSelect>
                </CrmFormField>
              </div>

              <div>
                <CrmFormField label="Expected ARR (₹)">
                  <CrmInput
                    type="number"
                    step="1000"
                    value={expectedArr}
                    onChange={(e) => setExpectedArr(e.target.value)}
                    required
                  />
                </CrmFormField>
              </div>
            </div>

            <div>
              <CrmFormField label="Deal Notes">
                <textarea
                  rows={3}
                  value={dealNotes}
                  onChange={(e) => setDealNotes(e.target.value)}
                  className="w-full rounded-md border border-ovs-crm-border bg-white px-3 py-2 text-[12.5px] text-ovs-crm-text-primary focus:outline-hidden"
                  placeholder="Notes for the sales rep..."
                />
              </CrmFormField>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <CrmButton
                type="button"
                tone="ghost"
                onClick={() => setDealModalRow(null)}
                disabled={isSubmittingDeal}
              >
                Cancel
              </CrmButton>
              <CrmButton
                type="submit"
                tone="primary"
                disabled={isSubmittingDeal}
              >
                {isSubmittingDeal ? 'Creating...' : 'Create Deal in CRM'}
              </CrmButton>
            </div>
          </form>
        </CrmModal>
      )}
    </LifecycleShell>
  );
}
