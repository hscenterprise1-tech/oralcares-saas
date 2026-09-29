import React, { useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowUp,
  CalendarClock,
  ChevronsUpDown,
  CreditCard,
  ExternalLink,
  Landmark,
  RefreshCw,
  TrendingUp,
  X,
} from 'lucide-react';
import {
  CrmButton,
  CrmCard,
  CrmCardIcon,
  CrmFilterBar,
  CrmFormField,
  CrmModal,
  CrmPageHead,
  CrmPageStat,
  CrmPagination,
  CrmSection,
  CrmSelect,
  CrmStatusPill,
  CrmTable,
  CrmTextarea,
  inr,
  humanize,
  statusTone,
  utilizationTone,
  type CrmColumn,
  type CrmFilterDef,
  type PaginatorLink,
} from '@/pages/Company/design';
import { cn } from '@/lib/utils';
import { LifecycleShell } from './components/LifecycleShell';

/* Same palette the sibling Lifecycle pages declare. Worth consolidating into
 * the design kit one day; not worth diverging from them today. */
const HUE = { blue: '#2F6BE4', green: '#2FA96B', violet: '#7C6FE0', teal: '#3E93A3', orange: '#F59245' } as const;

export interface SubscriptionRow {
  id: number;
  organization_id: number | null;
  account: string | null;
  clinic: string | null;
  plan: string | null;
  plan_key?: string | null;
  billing_period: string | null;
  billing_frequency?: string;
  status: string;
  mrr: number | null;
  arr?: number | null;
  mrr_formatted?: string;
  arr_formatted?: string;
  starts_at: string | null;
  ends_at: string | null;
  trial_ends_at?: string | null;
  grace_ends_at?: string | null;
  days_to_expiry: number | null;
  max_users: number | null;
  max_branches: number | null;
  max_doctors?: number | null;
  purchased_seats?: number | null;
  purchased_branches?: number | null;
  purchased_doctors?: number | null;
  used_seats?: number;
  seat_utilization_pct?: number | null;
  active_branches?: number;
  branch_utilization_pct?: number | null;
  renewal_probability?: number | null;
  renewal_state?: string;
  owner?: {
    id: number;
    name: string;
    email: string;
  } | null;
}

interface Props {
  subscriptions: {
    data: SubscriptionRow[];
    links?: PaginatorLink[];
    from?: number | null;
    to?: number | null;
    total?: number | null;
    per_page?: number;
    current_page?: number;
    last_page?: number;
  };
  filters: {
    search?: string;
    status?: string;
    plan_id?: string | number;
    window?: string;
    billing_frequency?: string;
    date_from?: string;
    date_to?: string;
    sort_by?: string;
    sort_order?: 'asc' | 'desc';
    per_page?: number;
  };
  summary: {
    total: number;
    by_status: Record<string, number>;
    active: number;
    expiring_30: number;
    expiring_60?: number;
    expiring_90: number;
    lapsed: number;
    mrr: number | null;
    arr?: number | null;
    mrr_unrecorded: number;
  };
  plans: Array<{ id: number; name: string; billing_period: string | null }>;
  statuses: Array<{ value: string; label: string }>;
  can?: {
    viewRenewals?: boolean;
    viewAccounts?: boolean;
    createDeal?: boolean;
  };
}

/**
 * The contract book.
 *
 * One row per contract, not per account — an account may hold several, and
 * every money question is asked per contract. Rebuilt on the shared page
 * furniture: `CrmPageHead`, `CrmFilterBar` and `CrmTable`, the same three
 * components CRM Deals and Marketing Contacts are made of, so a reader moving
 * between modules meets one layout rather than three.
 *
 * Read-only, deliberately. The one write here raises a renewal or expansion
 * deal in CRM, which is where commercial work belongs; nothing on this page
 * edits a subscription, because provisioning and the trial lifecycle already
 * own that and a second way to do it is how two modules start disagreeing.
 */
export default function SubscriptionsWorkspace({
  subscriptions,
  filters = {},
  summary,
  plans = [],
  statuses = [],
  can = {},
}: Props) {
  const rows = subscriptions?.data ?? [];

  const [searchQuery, setSearchQuery] = useState<string>(filters.search ?? '');
  const [dealModalRow, setDealModalRow] = useState<SubscriptionRow | null>(null);
  const [dealType, setDealType] = useState<'renewal' | 'expansion'>('renewal');
  const [dealNotes, setDealNotes] = useState<string>('');
  const [isSubmittingDeal, setIsSubmittingDeal] = useState<boolean>(false);

  const activeWindow = filters.window || 'all';
  const activeSort = filters.sort_by ?? 'ends_at';
  const activeDir = (filters.sort_order ?? 'asc') as 'asc' | 'desc';

  const isFiltered =
    Boolean(filters.search) ||
    activeWindow !== 'all' ||
    Boolean(filters.status) ||
    Boolean(filters.plan_id) ||
    Boolean(filters.billing_frequency);

  const apply = (changes: Record<string, string | number | undefined>) => {
    const updated: Record<string, string | number | undefined> = { ...filters, ...changes };

    Object.keys(updated).forEach((key) => {
      if (updated[key] === '' || updated[key] === undefined || updated[key] === null) delete updated[key];
    });

    router.get(route('company.customer-success.subscriptions.index'), updated, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const onSearchChange = (value: string) => {
    setSearchQuery(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => apply({ search: value || undefined, page: undefined }), 300);
  };

  const clearAll = () => {
    setSearchQuery('');
    router.get(route('company.customer-success.subscriptions.index'), {}, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const handleSort = (column: string) => {
    if (activeSort !== column) return apply({ sort_by: column, sort_order: 'asc' });
    if (activeDir === 'asc') return apply({ sort_by: column, sort_order: 'desc' });

    return apply({ sort_by: undefined, sort_order: undefined });
  };

  const sortHeader = (column: string, label: string) => {
    const active = activeSort === column;
    const Icon = ! active ? ChevronsUpDown : activeDir === 'asc' ? ArrowUp : ArrowDown;

    return (
      <button
        type="button"
        onClick={() => handleSort(column)}
        aria-label={`Sort by ${label}`}
        className={cn(
          'inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-[11.5px] transition-colors',
          active
            ? 'font-semibold text-ovs-crm-text-primary'
            : 'text-ovs-crm-text-muted hover:text-ovs-crm-text-primary',
        )}
      >
        {label}
        <Icon className="h-3 w-3" aria-hidden="true" />
      </button>
    );
  };

  const handleCreateDeal = () => {
    if (! dealModalRow?.organization_id) return;

    setIsSubmittingDeal(true);
    router.post(
      route('company.customer-success.accounts.create-deal', dealModalRow.organization_id),
      { deal_type: dealType, notes: dealNotes || undefined },
      {
        preserveScroll: true,
        onSuccess: () => {
          setDealModalRow(null);
          setDealNotes('');
          setIsSubmittingDeal(false);
        },
        onError: () => setIsSubmittingDeal(false),
      },
    );
  };

  /** How close the term is, said as a state rather than a raw number of days. */
  const expiryPill = (row: SubscriptionRow) => {
    const days = row.days_to_expiry;

    if (days === null || days === undefined) return <span className="text-ovs-crm-text-muted">Open-ended</span>;
    if (days < 0) return <CrmStatusPill tone="bad">{Math.abs(days)}d overdue</CrmStatusPill>;
    if (days <= 30) return <CrmStatusPill tone="warn">{days}d left</CrmStatusPill>;
    if (days <= 90) return <CrmStatusPill tone="info">{days}d left</CrmStatusPill>;

    return <span className="tabular-nums text-ovs-crm-text-secondary">{days}d left</span>;
  };

  const columns: CrmColumn<SubscriptionRow>[] = [
    {
      key: 'account',
      header: sortHeader('account', 'Account'),
      mobileLabel: 'Account',
      primary: true,
      width: '24%',
      cell: (row) => (
        <span className="block min-w-0">
          <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">
            {row.account ?? 'Unknown account'}
          </span>
          <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
            SUB-{String(row.id).padStart(4, '0')}
            {row.clinic ? ` · ${row.clinic}` : ''}
          </span>
        </span>
      ),
    },
    {
      key: 'plan',
      header: sortHeader('plan', 'Plan'),
      mobileLabel: 'Plan',
      width: '150px',
      cell: (row) => (
        <span className="block">
          <span className="block truncate text-[12.5px] text-ovs-crm-text-primary">{row.plan ?? 'Custom'}</span>
          <span className="block text-[11.5px] text-ovs-crm-text-muted">
            {humanize(row.billing_period ?? '') || 'Monthly'}
          </span>
        </span>
      ),
    },
    {
      key: 'status',
      header: sortHeader('status', 'Status'),
      mobileLabel: 'Status',
      width: '120px',
      cell: (row) => <CrmStatusPill tone={statusTone(row.status)}>{humanize(row.status)}</CrmStatusPill>,
    },
    {
      key: 'value',
      header: sortHeader('mrr', 'Value'),
      mobileLabel: 'Value',
      width: '150px',
      cell: (row) =>
        row.mrr === null ? (
          /*
           * Null, not zero. Two subscriptions predate the MRR column and are
           * genuinely unknown; printing ₹0 would put them in every average.
           */
          <span className="text-[12.5px] text-ovs-crm-text-muted">Not recorded</span>
        ) : (
          <span className="block">
            <span className="block text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
              {inr(row.mrr * 12)}
            </span>
            <span className="block text-[11.5px] tabular-nums text-ovs-crm-text-muted">{inr(row.mrr)}/mo</span>
          </span>
        ),
    },
    {
      key: 'capacity',
      header: 'Capacity',
      mobileLabel: 'Capacity',
      width: '140px',
      hideOnMobile: true,
      cell: (row) => (
        <span className="block">
          <span className="block text-[12.5px] tabular-nums text-ovs-crm-text-primary">
            {row.used_seats ?? 0} / {row.purchased_seats ?? row.max_users ?? '—'} seats
          </span>
          {row.seat_utilization_pct !== null && row.seat_utilization_pct !== undefined && (
            <CrmStatusPill tone={utilizationTone(row.seat_utilization_pct)}>
              {row.seat_utilization_pct}%
            </CrmStatusPill>
          )}
        </span>
      ),
    },
    {
      key: 'term',
      header: sortHeader('ends_at', 'Term'),
      mobileLabel: 'Term',
      width: '150px',
      cell: (row) => (
        <span className="block">
          <span className="block text-[12.5px] tabular-nums text-ovs-crm-text-primary">{row.ends_at ?? '—'}</span>
          <span className="block text-[11.5px]">{expiryPill(row)}</span>
        </span>
      ),
    },
    {
      key: 'owner',
      header: 'Owner',
      mobileLabel: 'Owner',
      width: '140px',
      hideOnMobile: true,
      cell: (row) =>
        row.owner ? (
          <span className="block truncate text-[12.5px] text-ovs-crm-text-secondary">{row.owner.name}</span>
        ) : (
          <CrmStatusPill tone="warn">Unassigned</CrmStatusPill>
        ),
    },
  ];

  const windowSegments = [
    { key: 'all', label: 'All', count: summary.total },
    { key: 'active', label: 'Active', count: summary.active },
    { key: 'expiring_30', label: 'Expiring 30d', count: summary.expiring_30 },
    { key: 'expiring_60', label: 'Expiring 60d', count: summary.expiring_60 ?? 0 },
    { key: 'expiring_90', label: 'Expiring 90d', count: summary.expiring_90 },
    { key: 'expired', label: 'Lapsed', count: summary.lapsed },
    { key: 'cancelled', label: 'Cancelled', count: summary.by_status?.cancelled ?? 0 },
  ];

  const filterDefs: CrmFilterDef[] = [
    {
      key: 'status',
      label: 'Status',
      value: String(filters.status ?? ''),
      emptyValue: '',
      options: [
        { value: '', label: 'Any status' },
        ...statuses.map((status) => ({ value: status.value, label: status.label })),
      ],
    },
    {
      key: 'plan_id',
      label: 'Plan',
      value: filters.plan_id !== undefined && filters.plan_id !== null ? String(filters.plan_id) : '',
      emptyValue: '',
      options: [
        { value: '', label: 'Any plan' },
        ...plans.map((plan) => ({ value: String(plan.id), label: plan.name })),
      ],
    },
    {
      key: 'billing_frequency',
      label: 'Billing',
      value: String(filters.billing_frequency ?? ''),
      emptyValue: '',
      advanced: true,
      options: [
        { value: '', label: 'Any cycle' },
        { value: 'monthly', label: 'Monthly' },
        { value: 'yearly', label: 'Annual' },
      ],
    },
  ];

  return (
    <LifecycleShell title="Subscriptions">
      <Head title="Subscriptions | Customer Success" />

      <CrmPageHead
        eyebrow="Customer success"
        title="Subscriptions"
        description="The contract, plan and entitlement ledger for every customer account."
        stats={
          <>
            <CrmPageStat icon={CreditCard} value={summary.total} caption="Contracts" />
            <CrmPageStat icon={TrendingUp} value={summary.active} caption="Active" />
            <CrmPageStat icon={CalendarClock} value={summary.expiring_30} caption="Expiring in 30 days" />
            <CrmPageStat
              icon={Landmark}
              value={summary.mrr === null ? 'Not recorded' : inr(summary.mrr)}
              caption="Recorded MRR"
            />
          </>
        }
        actions={
          can.viewRenewals ? (
            <CrmButton tone="secondary" size="sm" icon={RefreshCw} href={route('company.customer-success.renewals.index')}>
              Renewals queue
            </CrmButton>
          ) : undefined
        }
      />

      <div className="space-y-4 pb-12">
        {summary.mrr_unrecorded > 0 && (
          <CrmCard className="flex items-start gap-3 px-5 py-4" frostHue={HUE.orange}>
            <CrmCardIcon icon={AlertTriangle} hue={HUE.orange} />
            <div>
              <p className="text-[13px] font-semibold text-ovs-crm-text-primary">
                {summary.mrr_unrecorded} active contract{summary.mrr_unrecorded === 1 ? ' carries' : 's carry'} no recorded MRR
              </p>
              <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
                They are excluded from every figure on this page rather than counted as zero, so the run-rate above is
                the part of the book that can actually be measured.
              </p>
            </div>
          </CrmCard>
        )}

        <CrmFilterBar
          searchValue={searchQuery}
          onSearchChange={onSearchChange}
          searchPlaceholder="Search account or contract code…"
          segments={windowSegments}
          segmentValue={activeWindow}
          onSegmentChange={(key) => apply({ window: key === 'all' ? undefined : key })}
          inlineSegments
          filters={filterDefs}
          onFilterChange={(key, value) => apply({ [key]: value || undefined })}
          onClearAll={clearAll}
          resultCount={subscriptions?.total ?? rows.length}
          resultNoun="contract"
        />

        <CrmSection
          flush
          title={windowSegments.find((segment) => segment.key === activeWindow)?.label ?? 'All'}
          description={
            (subscriptions?.total ?? 0) > 0
              ? `${subscriptions?.from ?? 0}–${subscriptions?.to ?? 0} of ${subscriptions?.total ?? 0}`
              : undefined
          }
        >
          <CrmTable<SubscriptionRow>
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            onRowClick={(row) => router.visit(route('company.customer-success.subscriptions.show', row.id))}
            isFiltered={isFiltered}
            rowActions={(row) => (
              <>
                <CrmButton
                  size="sm"
                  tone="ghost"
                  icon={ExternalLink}
                  href={route('company.customer-success.subscriptions.show', row.id)}
                  aria-label={`Open contract SUB-${row.id}`}
                >
                  Open
                </CrmButton>
                {can.createDeal && row.organization_id && (
                  <CrmButton
                    size="sm"
                    tone="ghost"
                    icon={RefreshCw}
                    onClick={(event) => {
                      event.stopPropagation();
                      setDealType('renewal');
                      setDealModalRow(row);
                    }}
                    aria-label={`Raise a deal for ${row.account ?? 'this account'}`}
                  >
                    Deal
                  </CrmButton>
                )}
              </>
            )}
            empty={{
              icon: CreditCard,
              title: 'No contracts yet',
              description: 'A subscription appears here once a won deal is provisioned into a customer tenant.',
            }}
            emptyFiltered={{
              title: 'Nothing matches this cut',
              description: 'No contract satisfies every filter currently applied.',
              action: (
                <CrmButton icon={X} onClick={clearAll}>
                  Clear filters
                </CrmButton>
              ),
            }}
            footer={
              (subscriptions?.last_page ?? 1) > 1 ? (
                <CrmPagination
                  links={subscriptions?.links ?? []}
                  from={subscriptions?.from}
                  to={subscriptions?.to}
                  total={subscriptions?.total}
                />
              ) : undefined
            }
          />
        </CrmSection>
      </div>

      <CrmModal
        open={dealModalRow !== null}
        onClose={() => setDealModalRow(null)}
        title={`Raise a deal — ${dealModalRow?.account ?? ''}`}
        description="The commercial work happens in CRM. This opens the deal there and links it back to this account."
        footer={
          <div className="flex justify-end gap-2">
            <CrmButton tone="secondary" size="sm" onClick={() => setDealModalRow(null)}>
              Cancel
            </CrmButton>
            <CrmButton tone="primary" size="sm" onClick={handleCreateDeal} disabled={isSubmittingDeal}>
              {isSubmittingDeal ? 'Creating…' : 'Create deal in CRM'}
            </CrmButton>
          </div>
        }
      >
        <div className="space-y-4">
          <CrmFormField label="Deal type">
            <CrmSelect value={dealType} onChange={(e) => setDealType(e.target.value as 'renewal' | 'expansion')}>
              <option value="renewal">Renewal — keep the contract</option>
              <option value="expansion">Expansion — sell more capacity</option>
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Notes" hint="Optional. Carried onto the deal in CRM.">
            <CrmTextarea
              value={dealNotes}
              onChange={(e) => setDealNotes(e.target.value)}
              rows={3}
              placeholder="What the customer has said so far…"
            />
          </CrmFormField>
        </div>
      </CrmModal>
    </LifecycleShell>
  );
}
