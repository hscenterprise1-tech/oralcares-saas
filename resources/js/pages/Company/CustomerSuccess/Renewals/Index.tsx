import React, { useMemo, useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
  RefreshCw,
  ShieldAlert,
  Building2,
  Calendar,
  TrendingUp,
  AlertTriangle,
  CheckCircle2,
  ExternalLink,
  Sparkles,
} from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFilterBar,
  CrmPagination,
  CrmSelect,
  CrmDataState,
  CrmSection,
  CrmTable,
  healthTone,
  churnTone,
  humanize,
  inr,
  type CrmColumn,
  type CrmStatusTone,
  type PaginatorLink,
  type CrmFilterDef,
} from '@/pages/Company/design';

import { ContractActionModal, type ContractAction, type ContractPlan } from '../components/ContractActions';
import { CustomerSuccessShell } from '../components/CustomerSuccessShell';

export interface RenewalRecord {
  id: number;
  organizationId: number;
  companyName: string;
  contactName: string;
  contactEmail?: string;
  planName: string;
  status: string;
  mrr: string;
  mrrRaw?: number | null;
  arr: string;
  arrRaw?: number | null;
  currentArr: string;
  currentArrRaw?: number | null;
  isMrrRecorded?: boolean;
  startsAt: string | null;
  endsAt: string | null;
  renewalDate: string | null;
  daysToRenewal: number;
  renewalProbability?: number | null;
  healthScore?: number | null;
  churnRisk?: string | null;
  retentionStatus: 'Ready to Renew' | 'Expansion Opportunity' | 'At Risk' | string;
  expansionTarget: string;
  contractCode: string;
  billingFrequency?: string;
  productFamily?: string;
  doctorSeats?: number | null;
  branchCount?: number | null;
  purchasedSeats?: number;
  usedSeats?: number;
  seatUtilizationPct?: number | null;
  activeBranches?: number;
  branchUtilizationPct?: number | null;
  accountManager?: {
    name: string;
    avatarInitials?: string;
  } | null;
}

export interface RenewalsQueueProps {
  renewals: RenewalRecord[];
  eligibleOwners?: Array<{ id: number; name: string; email: string }>;
  paginator?: {
    links?: PaginatorLink[];
    from?: number | null;
    to?: number | null;
    total?: number;
    current_page?: number;
    last_page?: number;
    per_page?: number;
  };
  metrics: {
    due_this_month: number;
    at_risk_renewals: number;
    expected_renewal_mrr: number;
    recorded_subscriptions_count?: number;
    unrecorded_subscriptions_count?: number;
    retention_rate: number | null;
    retention_sample_size: number;
    retention_available: boolean;
    retention_unavailable_reason?: string | null;
  };
  filters: {
    search?: string;
    horizon?: string;
    window?: string;
    status?: string;
    owner_id?: string | number;
    account_id?: string | number;
    sort?: string;
    direction?: 'asc' | 'desc';
    per_page?: number;
  };
  plans?: ContractPlan[];
  can?: {
    manageContract?: boolean;
    viewAccount?: boolean;
  };
}

export default function RenewalsIndex({
  renewals = [],
  eligibleOwners = [],
  paginator,
  metrics = {
    due_this_month: 0,
    at_risk_renewals: 0,
    expected_renewal_mrr: 0,
    recorded_subscriptions_count: 0,
    unrecorded_subscriptions_count: 0,
    retention_rate: null,
    retention_sample_size: 0,
    retention_available: false,
  },
  filters = {},
  plans = [],
  can = {},
}: RenewalsQueueProps) {
  const safeFilters = filters && typeof filters === 'object' && !Array.isArray(filters) ? filters : {};
  const [searchQuery, setSearchQuery] = useState<string>(safeFilters.search ?? '');
  const [selectedRenewal, setSelectedRenewal] = useState<RenewalRecord | null>(null);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState<boolean>(false);

  const renewalList: RenewalRecord[] = useMemo(() => {
    if (Array.isArray(renewals)) return renewals;
    if (renewals && typeof renewals === 'object') {
      if (Array.isArray((renewals as any).data)) return (renewals as any).data;
      return Object.values(renewals);
    }
    return [];
  }, [renewals]);

  const applyFilters = (updates: Record<string, any>) => {
    const next: Record<string, any> = {
      ...safeFilters,
      ...updates,
    };

    Object.keys(next).forEach((k) => {
      if (next[k] === '' || next[k] === null || next[k] === undefined || next[k] === 'all') {
        delete next[k];
      }
    });

    router.get(route('company.customer-success.renewals.index'), next, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  useEffect(() => {
    const timer = setTimeout(() => {
      if (searchQuery !== (safeFilters.search ?? '')) {
        applyFilters({ search: searchQuery, page: 1 });
      }
    }, 350);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  const handleSort = (column: string) => {
    const currentSort = safeFilters.sort ?? 'ends_at';
    const currentDirection = safeFilters.direction ?? 'asc';
    const nextDirection = currentSort === column && currentDirection === 'asc' ? 'desc' : 'asc';

    applyFilters({ sort: column, direction: nextDirection, page: 1 });
  };

  const due = (days: number) => {
    if (days < 0) return { text: `${Math.abs(days)}d overdue`, tone: 'bad' as CrmStatusTone };
    if (days === 0) return { text: 'Due today', tone: 'bad' as CrmStatusTone };
    if (days === 1) return { text: 'Due tomorrow', tone: 'warn' as CrmStatusTone };
    if (days <= 30) return { text: `${days} days`, tone: 'warn' as CrmStatusTone };
    return { text: `${days} days`, tone: 'good' as CrmStatusTone };
  };

  const retentionTone = (status: string): CrmStatusTone => {
    if (status === 'At Risk') return 'bad';
    if (status === 'Expansion Opportunity') return 'info';
    return 'neutral';
  };

  /*
   * Renewing happens here.
   *
   * This used to POST a CRM *deal* — the pipeline built for winning a stranger
   * — against a customer who had already bought. Another year on the plan they
   * already hold is not a sale, and routing it through a second module is why
   * this queue was a list you could read and not act on.
   */
  const [contractAction, setContractAction] = useState<ContractAction | null>(null);
  const [contractRow, setContractRow] = useState<RenewalRecord | null>(null);

  /*
   * A renewals queue is a list of dates whose answer is usually "yes, another
   * year". Doing that one modal at a time is four clicks per customer, and a
   * team holding a hundred and twenty thousand accounts cannot spend a morning
   * on a week of renewals.
   */
  const [selectedKeys, setSelectedKeys] = useState<React.Key[]>([]);
  const [bulkMonths, setBulkMonths] = useState('12');
  const [isBulkRenewing, setIsBulkRenewing] = useState(false);

  const bulkRenew = () => {
    if (selectedKeys.length === 0) return;

    setIsBulkRenewing(true);
    router.post(
      route('company.customer-success.subscriptions.bulk-renew'),
      { ids: selectedKeys.map(Number), months: Number(bulkMonths) || 12 },
      {
        preserveScroll: true,
        onFinish: () => setIsBulkRenewing(false),
        onSuccess: () => setSelectedKeys([]),
      },
    );
  };

  const openContractAction = (item: RenewalRecord, action: ContractAction) => {
    if (! can?.manageContract) return;
    setContractRow(item);
    setContractAction(action);
  };

  const openDetails = (item: RenewalRecord) => {
    setSelectedRenewal(item);
    setIsDetailModalOpen(true);
  };

  const currentHorizon = safeFilters.horizon === '30'
    ? '0-30'
    : (safeFilters.horizon === '60' ? '31-60' : (safeFilters.horizon === '90' ? '61-90' : (safeFilters.horizon ?? safeFilters.window ?? 'all')));

  const horizonSegments = [
    { key: 'all', label: 'All' },
    { key: 'overdue', label: 'Overdue' },
    { key: '0-30', label: '0–30 Days' },
    { key: '31-60', label: '31–60 Days' },
    { key: '61-90', label: '61–90 Days' },
  ];

  const filterDefs: CrmFilterDef[] = [
    {
      key: 'status',
      label: 'Classification',
      value: safeFilters.status || 'all',
      options: [
        { value: 'all', label: 'All Classifications' },
        { value: 'at_risk', label: 'At Risk' },
        { value: 'expansion', label: 'Expansion Opportunity' },
        { value: 'ready', label: 'Ready to Renew' },
      ],
    },
    ...(eligibleOwners.length > 0
      ? [
          {
            key: 'owner_id',
            label: 'Owner',
            value: safeFilters.owner_id ? String(safeFilters.owner_id) : 'all',
            options: [
              { value: 'all', label: 'All Owners' },
              ...eligibleOwners.map((o) => ({ value: String(o.id), label: o.name })),
            ],
          },
        ]
      : []),
  ];

  const headStats = [
    metrics.due_this_month > 0 && (
      <CrmPageStat
        key="soon"
        icon={Calendar}
        value={metrics.due_this_month}
        caption={metrics.expected_renewal_mrr > 0
          ? `Due in 30d · ${inr(metrics.expected_renewal_mrr, { compact: true })}/mo recorded`
          : 'Renewing in 30d'}
      />
    ),
    metrics.at_risk_renewals > 0 && (
      <CrmPageStat
        key="risk"
        icon={ShieldAlert}
        value={metrics.at_risk_renewals}
        caption="Flagged at risk"
      />
    ),
    metrics.retention_available && metrics.retention_rate != null && (
      <CrmPageStat
        key="retention"
        icon={CheckCircle2}
        value={`${Math.round(metrics.retention_rate)}%`}
        caption={`Renewed so far · ${metrics.retention_sample_size} contracts`}
      />
    ),
  ].filter(Boolean);

  const activeSort = safeFilters.sort ?? 'ends_at';
  const activeDir = (safeFilters.direction ?? 'asc') as 'asc' | 'desc';

  return (
    <CustomerSuccessShell title="Renewals">
      <Head title="Renewals | Customer Success" />

      <CrmPageHead
        eyebrow="Customer success"
        title="Renewals"
        stats={headStats.length > 0 ? <>{headStats}</> : undefined}
      />

      <div className="space-y-4 pb-12">
        <CrmFilterBar
          searchValue={searchQuery}
          onSearchChange={setSearchQuery}
          searchPlaceholder="Search account, code or contact..."
          segments={horizonSegments}
          segmentValue={currentHorizon}
          onSegmentChange={(key) => applyFilters({ horizon: key, page: 1 })}
          inlineSegments
          filters={filterDefs}
          onFilterChange={(key, value) => applyFilters({ [key]: value, page: 1 })}
          onClearAll={() => {
            setSearchQuery('');
            applyFilters({ search: '', horizon: 'all', status: 'all', page: 1 });
          }}
          resultCount={paginator?.total ?? renewalList.length}
          resultNoun="subscription"
        />

        {can?.manageContract && selectedKeys.length > 0 && (
          <div className="flex flex-wrap items-center gap-3 rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-3">
            <span className="text-[13px] font-semibold text-ovs-crm-text-primary">
              {selectedKeys.length} selected
            </span>

            <CrmSelect
              value={bulkMonths}
              onChange={(event) => setBulkMonths(event.target.value)}
              aria-label="Renewal term"
              className="w-40"
            >
              <option value="1">1 month</option>
              <option value="3">3 months</option>
              <option value="6">6 months</option>
              <option value="12">12 months</option>
              <option value="24">24 months</option>
              <option value="36">36 months</option>
            </CrmSelect>

            <CrmButton size="sm" tone="primary" icon={RefreshCw} onClick={bulkRenew} disabled={isBulkRenewing}>
              {isBulkRenewing ? 'Renewing…' : `Renew ${selectedKeys.length}`}
            </CrmButton>

            <CrmButton size="sm" tone="ghost" onClick={() => setSelectedKeys([])}>
              Clear
            </CrmButton>

            {/*
              * Term only. Plan and price differ per customer, and a bulk form
              * that can silently reprice a hundred contracts is a bulk form
              * that eventually does — those stay one contract at a time.
              */}
            <span className="text-[11.5px] text-ovs-crm-text-muted">
              Extends the term. Plan and price stay as they are.
            </span>
          </div>
        )}

        <CrmSection
          flush
          title={horizonSegments.find((segment) => segment.key === currentHorizon)?.label ?? 'All renewals'}
          description={
            (paginator?.total ?? 0) > 0
              ? `${paginator?.from ?? 0}–${paginator?.to ?? 0} of ${paginator?.total ?? 0}`
              : undefined
          }
        >
          <CrmTable<RenewalRecord>
            columns={[
              {
                key: 'account',
                header: 'Account',
                mobileLabel: 'Account',
                primary: true,
                width: '24%',
                cell: (item) => (
                  <span className="block min-w-0">
                    <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                      {item.companyName}
                    </span>
                    <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
                      {item.contractCode}
                      {item.planName ? ` · ${item.planName}` : ''}
                    </span>
                  </span>
                ),
              },
              {
                key: 'due',
                header: 'Renewal date',
                mobileLabel: 'Renewal date',
                width: '160px',
                cell: (item) => (
                  <span className="block">
                    <span className="block text-[12.5px] tabular-nums text-ovs-crm-text-primary">
                      {item.renewalDate ?? '—'}
                    </span>
                    <span className="block text-[11.5px]">
                      {item.daysToRenewal <= 0 ? (
                        <CrmStatusPill tone="bad">{Math.abs(item.daysToRenewal)}d overdue</CrmStatusPill>
                      ) : item.daysToRenewal <= 30 ? (
                        <CrmStatusPill tone="warn">{item.daysToRenewal}d left</CrmStatusPill>
                      ) : (
                        <span className="tabular-nums text-ovs-crm-text-muted">{item.daysToRenewal}d left</span>
                      )}
                    </span>
                  </span>
                ),
              },
              {
                key: 'value',
                header: 'Contract value',
                mobileLabel: 'Contract value',
                width: '150px',
                align: 'right',
                cell: (item) =>
                  item.isMrrRecorded === false ? (
                    <span className="text-[12.5px] text-ovs-crm-text-muted">Not recorded</span>
                  ) : (
                    <span className="block">
                      <span className="block text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                        {item.currentArr}
                      </span>
                      <span className="block text-[11.5px] tabular-nums text-ovs-crm-text-muted">{item.mrr}</span>
                    </span>
                  ),
              },
              {
                key: 'health',
                header: 'Health',
                mobileLabel: 'Health',
                width: '140px',
                hideOnMobile: true,
                cell: (item) => (
                  <span className="flex flex-wrap items-center gap-1.5">
                    {item.healthScore !== null && item.healthScore !== undefined && (
                      <CrmStatusPill tone={healthTone(item.healthScore)}>{item.healthScore}/100</CrmStatusPill>
                    )}
                    {item.churnRisk && (
                      <CrmStatusPill tone={churnTone(item.churnRisk)}>{humanize(item.churnRisk)}</CrmStatusPill>
                    )}
                  </span>
                ),
              },
              {
                key: 'retention',
                header: 'Outlook',
                mobileLabel: 'Outlook',
                width: '160px',
                cell: (item) => (
                  <CrmStatusPill tone={retentionTone(item.retentionStatus)}>{item.retentionStatus}</CrmStatusPill>
                ),
              },
              {
                key: 'owner',
                header: 'Owner',
                mobileLabel: 'Owner',
                width: '140px',
                hideOnMobile: true,
                cell: (item) =>
                  item.accountManager?.name && item.accountManager.name !== 'Unassigned' ? (
                    <span className="block truncate text-[12.5px] text-ovs-crm-text-secondary">
                      {item.accountManager.name}
                    </span>
                  ) : (
                    <CrmStatusPill tone="warn">Unassigned</CrmStatusPill>
                  ),
              },
            ]}
            rows={renewalList}
            rowKey={(item) => item.id}
            selectable={Boolean(can?.manageContract)}
            selectedKeys={selectedKeys}
            onSelectionChange={setSelectedKeys}
            onRowClick={openDetails}
            isFiltered={Boolean(safeFilters.search || safeFilters.horizon || safeFilters.status)}
            rowActions={(item) =>
              can?.manageContract ? (
                <>
                  <CrmButton
                    size="sm"
                    tone="ghost"
                    icon={RefreshCw}
                    onClick={(event) => {
                      event.stopPropagation();
                      openContractAction(item, 'renew');
                    }}
                    aria-label={`Renew ${item.companyName}`}
                  >
                    Renew
                  </CrmButton>
                  {/*
                    * "Upgrade", not "expansion deal". A customer who needs a
                    * bigger plan gets one here; there is no sale to hand
                    * anybody and no second module to wait on.
                    */}
                  <CrmButton
                    size="sm"
                    tone="ghost"
                    icon={Sparkles}
                    onClick={(event) => {
                      event.stopPropagation();
                      openContractAction(item, 'change-plan');
                    }}
                    aria-label={`Change plan for ${item.companyName}`}
                  >
                    Upgrade
                  </CrmButton>
                </>
              ) : null
            }
            empty={{
              icon: Calendar,
              title: 'No renewals due yet',
              description: 'Contracts appear here automatically as their end dates approach, soonest first.',
              action: (
                <CrmButton icon={Building2} href="/company/customer-success/accounts">
                  See the accounts
                </CrmButton>
              ),
            }}
            emptyFiltered={{
              title: 'No matching renewals',
              description: 'No contract satisfies every filter currently applied.',
              action: (
                <CrmButton
                  tone="secondary"
                  size="sm"
                  onClick={() => {
                    setSearchQuery('');
                    applyFilters({ search: '', horizon: 'all', status: 'all', page: 1 });
                  }}
                >
                  Clear filters
                </CrmButton>
              ),
            }}
            footer={
              paginator ? (
                <CrmPagination
                  links={paginator.links || []}
                  from={paginator.from}
                  to={paginator.to}
                  total={paginator.total}
                />
              ) : undefined
            }
          />
        </CrmSection>
      </div>

      {/* Contract Detail Modal */}
      {isDetailModalOpen && selectedRenewal && (
        <CrmModal
          open={isDetailModalOpen}
          onClose={() => setIsDetailModalOpen(false)}
          title={`Contract ${selectedRenewal.contractCode} — ${selectedRenewal.companyName}`}
          description="Subscription term telemetry and retention details."
        >
          <div className="space-y-4 text-xs">
            <div className="grid grid-cols-2 gap-4 rounded-2xl border border-ovs-crm-border/60 bg-ovs-crm-surface-inset/60 p-4">
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Plan Name</span>
                <p className="mt-0.5 text-sm font-bold text-ovs-crm-text-primary">{selectedRenewal.planName}</p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Recorded ARR</span>
                <p className="mt-0.5 text-sm font-bold text-ovs-crm-brand">{selectedRenewal.currentArr}</p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Renewal Date</span>
                <p className="mt-0.5 text-xs font-medium text-ovs-crm-text-primary">{selectedRenewal.renewalDate ?? 'N/A'}</p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Days Remaining</span>
                <p className="mt-0.5 text-xs font-medium text-ovs-crm-text-primary">{selectedRenewal.daysToRenewal} Days</p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Renewal Probability</span>
                <p className="mt-0.5 text-xs font-medium text-ovs-crm-text-primary">
                  {selectedRenewal.renewalProbability != null ? `${selectedRenewal.renewalProbability}%` : '—'}
                </p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Health & Risk</span>
                <p className="mt-0.5 text-xs font-medium text-ovs-crm-text-primary">
                  {selectedRenewal.healthScore != null ? `${selectedRenewal.healthScore}/100` : '—'}
                  {selectedRenewal.churnRisk ? ` · ${selectedRenewal.churnRisk}` : ''}
                </p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Billing Cycle</span>
                <p className="mt-0.5 text-xs font-medium text-ovs-crm-text-primary">{selectedRenewal.billingFrequency ?? 'Annual Pre-Paid'}</p>
              </div>
              <div>
                <span className="text-[11px] font-bold uppercase text-ovs-crm-text-muted">Subscription Status</span>
                <p className="mt-0.5 text-xs font-medium text-ovs-crm-text-primary">{selectedRenewal.status}</p>
              </div>
            </div>

            {/* Utilization & Capacity */}
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
                <span className="text-[10.5px] font-semibold uppercase text-ovs-crm-text-muted">Seat Utilization</span>
                <p className="mt-1 text-sm font-bold text-ovs-crm-text-primary">
                  {selectedRenewal.usedSeats ?? 0} / {selectedRenewal.purchasedSeats ?? 0} seats
                  {selectedRenewal.seatUtilizationPct != null && (
                    <span className="ml-1.5 text-xs font-normal text-ovs-crm-text-muted">
                      ({selectedRenewal.seatUtilizationPct}%)
                    </span>
                  )}
                </p>
              </div>
              <div className="rounded-xl border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
                <span className="text-[10.5px] font-semibold uppercase text-ovs-crm-text-muted">Branch Utilization</span>
                <p className="mt-1 text-sm font-bold text-ovs-crm-text-primary">
                  {selectedRenewal.activeBranches ?? 0} / {selectedRenewal.branchCount ?? 1} clinics
                  {selectedRenewal.branchUtilizationPct != null && (
                    <span className="ml-1.5 text-xs font-normal text-ovs-crm-text-muted">
                      ({selectedRenewal.branchUtilizationPct}%)
                    </span>
                  )}
                </p>
              </div>
            </div>

            {/* Account Manager & Contact */}
            <div className="grid grid-cols-2 gap-3 rounded-xl border border-ovs-crm-border bg-ovs-crm-surface-inset p-3">
              <div>
                <span className="text-[10.5px] font-semibold uppercase text-ovs-crm-text-muted">Primary Contact</span>
                <p className="mt-0.5 font-medium text-ovs-crm-text-primary">{selectedRenewal.contactName}</p>
                {selectedRenewal.contactEmail && (
                  <p className="text-[11px] text-ovs-crm-text-muted">{selectedRenewal.contactEmail}</p>
                )}
              </div>
              <div>
                <span className="text-[10.5px] font-semibold uppercase text-ovs-crm-text-muted">Account Manager</span>
                <p className="mt-0.5 font-medium text-ovs-crm-text-primary">{selectedRenewal.accountManager?.name ?? 'Unassigned'}</p>
              </div>
            </div>

            <div className="flex items-center justify-between rounded-xl border border-ovs-crm-border bg-ovs-crm-surface-inset p-3.5">
              <div>
                <span className="font-semibold text-ovs-crm-text-primary">Retention Classification:</span>
                {selectedRenewal.expansionTarget && (
                  <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">{selectedRenewal.expansionTarget}</p>
                )}
              </div>
              <CrmStatusPill tone={retentionTone(selectedRenewal.retentionStatus)}>
                {selectedRenewal.retentionStatus}
              </CrmStatusPill>
            </div>

            <div className="flex justify-end gap-2 border-t border-ovs-crm-border pt-3">
              <CrmButton
                tone="secondary"
                size="sm"
                onClick={() => setIsDetailModalOpen(false)}
              >
                Close
              </CrmButton>
              <CrmButton
                tone="secondary"
                size="sm"
                icon={ExternalLink}
                href={`/company/customer-success/subscriptions/${selectedRenewal.id}`}
              >
                Subscription
              </CrmButton>
              {can?.viewAccount !== false && (
                <CrmButton
                  tone="secondary"
                  size="sm"
                  icon={ExternalLink}
                  href={`/company/customer-success/accounts/${selectedRenewal.organizationId}?tab=renewal`}
                >
                  Account 360
                </CrmButton>
              )}
              {can?.manageContract && (
                <CrmButton
                  tone="primary"
                  size="sm"
                  icon={RefreshCw}
                  onClick={() => {
                    setIsDetailModalOpen(false);
                    openContractAction(selectedRenewal, 'renew');
                  }}
                >
                  Renew
                </CrmButton>
              )}
            </div>
          </div>
        </CrmModal>
      )}

      {/*
        * One modal for all four contract verbs, shared with the contract book
        * and the capacity worklist — four copies of a money form is four places
        * for them to drift.
        */}
      <ContractActionModal
        action={contractAction}
        contract={
          contractRow
            ? {
              id: contractRow.id,
              account: contractRow.companyName,
              planName: contractRow.planName,
              mrr: contractRow.mrrRaw ?? null,
              maxUsers: contractRow.purchasedSeats ?? null,
              maxBranches: contractRow.branchCount ?? null,
              endsAt: contractRow.renewalDate,
            }
            : null
        }
        plans={plans}
        onClose={() => {
          setContractAction(null);
          setContractRow(null);
        }}
      />
    </CustomerSuccessShell>
  );
}
