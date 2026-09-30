import React, { useMemo, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import {
  Building2,
  TrendingUp,
  Clock,
  ShieldAlert,
  Calendar,
  Download,
  ArrowUp,
  ArrowDown,
  ChevronsUpDown,
  ExternalLink,
  X,
} from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmStatusPill,
  CrmButton,
  CrmSelect,
  CrmFilterBar,
  CrmPagination,
  CrmTable,
  healthTone,
  churnTone,
  lifecycleStageTone,
  utilizationTone,
  humanize,
  type CrmColumn,
} from '@/pages/Company/design';
import { cn } from '@/lib/utils';
import { CustomerSuccessShell } from '../components/CustomerSuccessShell';

export interface AccountRecord {
  id: number;
  name: string;
  legal_name?: string;
  contact_person?: string;
  email?: string;
  mobile?: string;
  city?: string;
  state?: string;
  clinic_type?: string;
  assigned_user?: {
    id: number;
    name: string;
    email: string;
  } | null;
  health_score: number;
  health_dimensions?: Record<string, { score: number; max: number; label: string }>;
  churn_risk: string;
  renewal_probability: number;
  stage: string;
  has_subscription: boolean;
  active_subscription_id?: number | null;
  plan_name: string;
  mrr: string;
  mrr_raw?: number | null;
  arr: string;
  arr_raw?: number;
  renewal_date: string;
  days_to_expiry?: number | null;
  purchased_seats: number;
  used_seats: number;
  seat_utilization_pct?: number | null;
  purchased_branches: number;
  active_branches: number;
  branch_utilization_pct?: number | null;
  expansion_opportunity: boolean;
  onboarding_status: string;
  onboarding_current_step: string;
  last_activity_at?: string | null;
  created_at?: string;
}

export interface AccountsIndexProps {
  accounts: AccountRecord[];
  /*
   * Two urls, not a page count. Working out how many pages there are means
   * counting the whole filtered set, which at this size cost more than every
   * other query on the request put together.
   */
  paginator?: {
    from?: number | null;
    to?: number | null;
    prev_url?: string | null;
    next_url?: string | null;
    current_page?: number;
    per_page?: number;
  };
  metrics?: {
    total_accounts: number;
    at_risk_count: number;
    renewing_30_count: number;
    onboarding_count: number;
    expansion_count: number;
  };
  filters: {
    search?: string;
    view?: string;
    filter?: string;
    stage?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
    per_page?: number;
  };
  can?: {
    viewAccount?: boolean;
    manageAccount?: boolean;
    export?: boolean;
  };
  /** Company staff a selection may be reassigned to. Empty for a reader who may not. */
  assignableOwners?: Array<{ id: number; name: string }>;
}

/**
 * The account book.
 *
 * Built on `CrmTable` — the component CRM Deals and Marketing Contacts use —
 * rather than on the low-level table primitives. That is not a cosmetic
 * preference: `CrmTable` renders a desktop table *and* a stacked card list from
 * one column definition, so this page has a real phone layout instead of a wide
 * grid pushed sideways. The hand-built markup it replaces had neither, which is
 * why this screen did not match the rest of the product on a tablet.
 *
 * Selection, empty states, loading and the filtered-empty case come from that
 * component too, so they behave here exactly as they do in CRM.
 */
export default function AccountsIndex({
  accounts = [],
  paginator,
  metrics = {
    total_accounts: 0,
    at_risk_count: 0,
    renewing_30_count: 0,
    onboarding_count: 0,
    expansion_count: 0,
  },
  filters = {},
  can = {},
  assignableOwners = [],
}: AccountsIndexProps) {
  const safeFilters = filters && typeof filters === 'object' && ! Array.isArray(filters) ? filters : {};

  const [searchQuery, setSearchQuery] = useState<string>(safeFilters.search ?? '');
  const [selectedKeys, setSelectedKeys] = useState<React.Key[]>([]);
  const [bulkOwner, setBulkOwner] = useState<string>('');
  const [bulkChurn, setBulkChurn] = useState<string>('');
  const [isApplyingBulk, setIsApplyingBulk] = useState<boolean>(false);

  const accountList: AccountRecord[] = useMemo(() => {
    if (Array.isArray(accounts)) return accounts;
    if (accounts && typeof accounts === 'object') {
      const data = (accounts as { data?: AccountRecord[] }).data;
      if (Array.isArray(data)) return data;

      return Object.values(accounts) as AccountRecord[];
    }

    return [];
  }, [accounts]);

  const currentView = safeFilters.view ?? safeFilters.filter ?? 'all';
  /* Set when the Overview's stage ladder sent the reader here. */
  const currentStage = safeFilters.stage ?? '';
  const activeSort = safeFilters.sort ?? 'name';
  const activeDir = (safeFilters.direction ?? 'asc') as 'asc' | 'desc';
  const isFiltered = Boolean(safeFilters.search) || currentView !== 'all' || currentStage !== '';

  const applyFilters = (updates: Record<string, string | number | undefined>) => {
    const next: Record<string, string | number | undefined> = {
      search: searchQuery || undefined,
      view: currentView !== 'all' ? currentView : undefined,
      stage: currentStage || undefined,
      sort: activeSort !== 'name' ? activeSort : undefined,
      direction: activeDir !== 'asc' ? activeDir : undefined,
      per_page: safeFilters.per_page,
      ...updates,
    };

    Object.keys(next).forEach((key) => {
      if (next[key] === '' || next[key] === undefined || next[key] === null) delete next[key];
    });

    router.get(route('company.customer-success.accounts.index'), next, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  /*
   * Debounced, the way Marketing Contacts does it: typing narrows the list
   * without a round trip per keystroke, and the request that lands is the one
   * for what is actually in the box.
   */
  const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  const onSearchChange = (value: string) => {
    setSearchQuery(value);
    if (searchTimer.current) clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => applyFilters({ search: value || undefined, page: 1 }), 300);
  };

  /** Third click clears the sort rather than cycling forever between two directions. */
  const handleSort = (column: string) => {
    if (activeSort !== column) return applyFilters({ sort: column, direction: 'asc', page: 1 });
    if (activeDir === 'asc') return applyFilters({ sort: column, direction: 'desc', page: 1 });

    return applyFilters({ sort: undefined, direction: undefined, page: 1 });
  };

  const clearAll = () => {
    setSearchQuery('');
    applyFilters({ search: undefined, view: undefined, stage: undefined, sort: undefined, direction: undefined, page: 1 });
  };

  const applyBulk = () => {
    if (selectedKeys.length === 0 || (! bulkOwner && ! bulkChurn)) return;

    setIsApplyingBulk(true);
    router.post(
      route('company.customer-success.accounts.bulk'),
      {
        ids: selectedKeys.map(Number),
        ...(bulkOwner ? { assigned_user_id: Number(bulkOwner) } : {}),
        ...(bulkChurn ? { churn_risk: bulkChurn } : {}),
      },
      {
        preserveScroll: true,
        onFinish: () => {
          setIsApplyingBulk(false);
          setSelectedKeys([]);
          setBulkOwner('');
          setBulkChurn('');
        },
      },
    );
  };

  /** A sortable heading. The arrow says which way, not merely that it can move. */
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

  const columns: CrmColumn<AccountRecord>[] = [
    {
      key: 'account',
      header: sortHeader('name', 'Account'),
      mobileLabel: 'Account',
      primary: true,
      width: '24%',
      cell: (row) => (
        <span className="block min-w-0">
          <span className="block truncate text-[13px] font-semibold text-ovs-crm-text-primary">{row.name}</span>
          <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
            {[row.contact_person, row.city].filter(Boolean).join(' · ') || '—'}
          </span>
        </span>
      ),
    },
    {
      key: 'health',
      header: sortHeader('health_score', 'Health'),
      mobileLabel: 'Health',
      width: '160px',
      cell: (row) => (
        <span className="flex flex-wrap items-center gap-1.5">
          <CrmStatusPill tone={healthTone(row.health_score)}>{row.health_score}/100</CrmStatusPill>
          <CrmStatusPill tone={churnTone(row.churn_risk)}>{humanize(row.churn_risk) || 'Unknown'}</CrmStatusPill>
        </span>
      ),
    },
    {
      key: 'stage',
      header: sortHeader('stage', 'Stage'),
      mobileLabel: 'Stage',
      width: '130px',
      cell: (row) => <CrmStatusPill tone={lifecycleStageTone(row.stage)}>{humanize(row.stage)}</CrmStatusPill>,
    },
    {
      key: 'capacity',
      header: sortHeader('seat_utilization_pct', 'Capacity'),
      mobileLabel: 'Capacity',
      width: '140px',
      hideOnMobile: true,
      cell: (row) => (
        <span className="block">
          <span className="block text-[12.5px] tabular-nums text-ovs-crm-text-primary">
            {row.used_seats} / {row.purchased_seats || '—'} seats
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
      key: 'contract',
      header: sortHeader('days_to_expiry', 'Contract'),
      mobileLabel: 'Contract',
      width: '170px',
      cell: (row) => (
        <span className="block">
          <span className="block text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">{row.arr}</span>
          <span className="block truncate text-[11.5px] text-ovs-crm-text-muted">
            {row.plan_name}
            {row.renewal_date !== '—' ? ` · ${row.renewal_date}` : ''}
          </span>
        </span>
      ),
    },
    {
      key: 'owner',
      header: sortHeader('last_activity_at', 'Owner'),
      mobileLabel: 'Owner',
      width: '150px',
      hideOnMobile: true,
      cell: (row) =>
        row.assigned_user ? (
          <span className="block truncate text-[12.5px] text-ovs-crm-text-secondary">{row.assigned_user.name}</span>
        ) : (
          <CrmStatusPill tone="warn">Unassigned</CrmStatusPill>
        ),
    },
  ];

  const headStats = [
    metrics.at_risk_count > 0 && (
      <CrmPageStat key="risk" icon={ShieldAlert} value={metrics.at_risk_count} caption="At risk" />
    ),
    metrics.renewing_30_count > 0 && (
      <CrmPageStat key="renew" icon={Calendar} value={metrics.renewing_30_count} caption="Renewing in 30 days" />
    ),
    metrics.onboarding_count > 0 && (
      <CrmPageStat key="onboarding" icon={Clock} value={metrics.onboarding_count} caption="Still onboarding" />
    ),
    metrics.expansion_count > 0 && (
      <CrmPageStat key="expansion" icon={TrendingUp} value={metrics.expansion_count} caption="Room to grow" />
    ),
    metrics.total_accounts > 0 && (
      <CrmPageStat
        key="total"
        icon={Building2}
        value={metrics.total_accounts}
        caption={`Customer${metrics.total_accounts === 1 ? '' : 's'}`}
      />
    ),
  ].filter(Boolean);

  const viewSegments = [
    { key: 'all', label: 'All' },
    { key: 'at_risk', label: 'At risk' },
    { key: 'renewing_30', label: 'Renewing 30d' },
    { key: 'renewing_60', label: 'Renewing 60d' },
    { key: 'renewing_90', label: 'Renewing 90d' },
    { key: 'onboarding', label: 'Onboarding' },
    { key: 'over_capacity', label: 'Over capacity' },
    { key: 'lapsed', label: 'Lapsed' },
  ];

  return (
    <CustomerSuccessShell title="Accounts">
      <Head title="Accounts | Customer Success" />

      <CrmPageHead
        eyebrow="Customer success"
        title="Accounts"
        description="Every customer organisation, and what each one needs from you next."
        stats={headStats.length > 0 ? <>{headStats}</> : undefined}
        actions={
          can.export ? (
            /*
             * A plain link, not a router visit: the response is a streamed CSV,
             * and Inertia would try to parse it as a page. It carries the
             * current query string, so the file is the list on screen.
             */
            <CrmButton
              tone="secondary"
              size="sm"
              icon={Download}
              href={`${route('company.customer-success.accounts.export')}${window.location.search}`}
            >
              Export CSV
            </CrmButton>
          ) : undefined
        }
      />

      <div className="space-y-4 pb-12">
        <CrmFilterBar
          searchValue={searchQuery}
          onSearchChange={onSearchChange}
          searchPlaceholder="Search account, contact or city…"
          segments={viewSegments}
          segmentValue={currentView}
          onSegmentChange={(key) => applyFilters({ view: key === 'all' ? undefined : key, page: 1 })}
          inlineSegments
          chips={
            currentStage
              ? [
                  {
                    key: 'stage',
                    group: 'Stage',
                    label: humanize(currentStage),
                    onRemove: () => applyFilters({ stage: undefined, page: 1 }),
                  },
                ]
              : undefined
          }
          onClearAll={clearAll}
          resultCount={metrics?.total_accounts ?? accountList.length}
          resultNoun="account"
        />

        {can.manageAccount && selectedKeys.length > 0 && (
          <div className="flex flex-wrap items-center gap-3 rounded-[18px] border border-ovs-crm-border bg-ovs-crm-surface-inset px-4 py-3">
            <span className="text-[13px] font-semibold text-ovs-crm-text-primary">
              {selectedKeys.length} selected
            </span>

            <CrmSelect
              value={bulkOwner}
              onChange={(e) => setBulkOwner(e.target.value)}
              aria-label="Reassign account manager"
              className="w-52"
            >
              <option value="">Reassign owner…</option>
              {assignableOwners.map((owner) => (
                <option key={owner.id} value={String(owner.id)}>
                  {owner.name}
                </option>
              ))}
            </CrmSelect>

            <CrmSelect
              value={bulkChurn}
              onChange={(e) => setBulkChurn(e.target.value)}
              aria-label="Set churn risk"
              className="w-44"
            >
              <option value="">Set churn risk…</option>
              <option value="Low">Low</option>
              <option value="Medium">Medium</option>
              <option value="High">High</option>
            </CrmSelect>

            <CrmButton
              tone="primary"
              size="sm"
              onClick={applyBulk}
              disabled={isApplyingBulk || (! bulkOwner && ! bulkChurn)}
            >
              {isApplyingBulk ? 'Applying…' : 'Apply'}
            </CrmButton>

            <CrmButton tone="ghost" size="sm" onClick={() => setSelectedKeys([])}>
              Clear selection
            </CrmButton>
          </div>
        )}

        <CrmSection
          flush
          title={viewSegments.find((segment) => segment.key === currentView)?.label ?? 'All'}
          description={
            accountList.length > 0 ? `${paginator?.from ?? 1}–${paginator?.to ?? accountList.length}` : undefined
          }
        >
          <CrmTable<AccountRecord>
            columns={columns}
            rows={accountList}
            rowKey={(row) => row.id}
            /*
             * Straight to the record, the way every other list in this product
             * behaves. This page used to open a summary modal that repeated
             * Account 360 and then offered a button to Account 360 — two clicks
             * and a second surface to keep in step with the first.
             */
            onRowClick={(row) => router.visit(`/company/customer-success/accounts/${row.id}`)}
            selectable={can.manageAccount}
            selectedKeys={selectedKeys}
            onSelectionChange={setSelectedKeys}
            isFiltered={isFiltered}
            rowActions={(row) => (
              <CrmButton
                size="sm"
                tone="ghost"
                icon={ExternalLink}
                href={`/company/customer-success/accounts/${row.id}`}
                aria-label={`Open ${row.name}`}
              >
                Open
              </CrmButton>
            )}
            empty={{
              icon: Building2,
              title: 'No customer accounts yet',
              description: 'A clinic becomes an account the moment its deal is won and its tenant is provisioned.',
              action: (
                <CrmButton tone="primary" href="/company/crm/deals">
                  Go to deals
                </CrmButton>
              ),
            }}
            emptyFiltered={{
              title: 'Nothing matches this cut',
              description: 'No account satisfies every filter currently applied.',
              action: (
                <CrmButton icon={X} onClick={clearAll}>
                  Clear filters
                </CrmButton>
              ),
            }}
            footer={
              paginator?.prev_url || paginator?.next_url ? (
                <CrmPagination
                  from={paginator?.from}
                  to={paginator?.to}
                  prevUrl={paginator?.prev_url ?? null}
                  nextUrl={paginator?.next_url ?? null}
                />
              ) : undefined
            }
          />
        </CrmSection>
      </div>
    </CustomerSuccessShell>
  );
}
