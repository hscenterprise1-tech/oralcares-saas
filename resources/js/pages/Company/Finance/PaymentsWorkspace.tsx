import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { CreditCard, CheckCircle2, Calendar, Search } from 'lucide-react';
import { FinanceShell } from './components/FinanceShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmInput,
  CrmSelect,
  CrmStatusPill,
  CrmPagination,
} from '@/pages/Company/design';

/**
 * Finance → Payments
 *
 * Payment transactions log, rebuilt on the shared CRM design system.
 */

interface PaymentItem {
  id: number;
  transaction_reference: string;
  amount: number;
  payment_method: string;
  status: string;
  paid_at: string;
  clinic?: { id: number; name: string };
  commercialInvoice?: { id: number; invoice_number: string };
}

interface PaymentsWorkspaceProps {
  payments: {
    data: PaymentItem[];
    current_page: number;
    last_page: number;
    total: number;
    from?: number;
    to?: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
  };
  filters: {
    search?: string;
    payment_method?: string;
    month?: string;
  };
  kpi: {
    total_collected: number;
    this_month: number;
    transactions_count: number;
  };
}

const formatCurrency = (val: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(val);

const shortDate = (iso: string | null | undefined) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' });
};

export default function PaymentsWorkspace({ payments, filters = {}, kpi }: PaymentsWorkspaceProps) {
  const [search, setSearch] = useState(filters.search || '');

  const apply = (changes: Record<string, string | undefined>) => {
    const next: Record<string, string | undefined> = { ...filters, ...changes };
    for (const key of Object.keys(next)) {
      if (!next[key] || next[key] === 'all') delete next[key];
    }
    delete (next as any).page;
    router.get('/company/finance/payments', next as any, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const searchTimer = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const onSearchChange = (value: string) => {
    setSearch(value);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => apply({ search: value }), 300);
  };
  React.useEffect(() => () => clearTimeout(searchTimer.current), []);

  return (
    <FinanceShell title="Payments">
      <CrmPageHead
        eyebrow="Finance"
        title="Payments"
        stats={
          <>
            <CrmPageStat
              icon={CheckCircle2}
              value={formatCurrency(kpi.total_collected)}
              caption="Total collections"
            />
            <CrmPageStat
              icon={Calendar}
              value={formatCurrency(kpi.this_month)}
              caption="This month"
            />
            <CrmPageStat
              icon={CreditCard}
              value={kpi.transactions_count}
              caption="Transactions logged"
            />
          </>
        }
      />

      <CrmSection
        title="Transaction log"
        description={`${payments.total?.toLocaleString('en-IN') ?? 0} payment${(payments.total ?? 0) === 1 ? '' : 's'}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
              <Search
                className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
                aria-hidden="true"
              />
              <CrmInput
                type="search"
                value={search}
                onChange={(e) => onSearchChange(e.target.value)}
                placeholder="Search reference or clinic"
                aria-label="Search payments"
                className="w-full pl-9 sm:w-64"
              />
            </div>
            <CrmSelect
              value={filters.payment_method ?? 'all'}
              onChange={(e) => apply({ payment_method: e.target.value })}
              aria-label="Payment method"
              className="w-auto"
            >
              <option value="all">All methods</option>
              <option value="razorpay">Razorpay / UPI</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="cheque">Cheque</option>
              <option value="stripe">Stripe</option>
            </CrmSelect>
            {/*
              A month filter that knows its year. The KPI beside it used to be
              `whereMonth('paid_at', now()->month)` with no year predicate, so
              it summed every September that had ever happened.
            */}
            <CrmInput
              type="month"
              value={filters.month ?? ''}
              onChange={(e) => apply({ month: e.target.value || undefined })}
              aria-label="Filter by month"
              className="w-auto text-[12px]"
            />
          </div>
        }
      >
        {payments.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || filters.payment_method || filters.month
                ? 'Nothing matches these filters.'
                : 'No payments recorded yet. Transactions will appear here once customers make payments.'}
            </p>
          </div>
        ) : (
          <div className="-mx-2.5">
            {payments.data.map((pay) => (
              <div
                key={pay.id}
                className="group flex cursor-default items-center gap-3 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
              >
                {/* Reference & Clinic */}
                <div className="min-w-0 flex-[1.5]">
                  <p className="truncate text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {pay.transaction_reference}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    {pay.clinic?.name || 'Commercial Client'}
                  </p>
                </div>

                {/* Invoice link */}
                <div className="hidden min-w-0 flex-1 lg:block">
                  <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-secondary">
                    {pay.commercialInvoice?.invoice_number || 'Direct Deposit'}
                  </p>
                  <p className="truncate text-[11.5px] uppercase text-ovs-crm-text-muted">
                    {pay.payment_method}
                  </p>
                </div>

                {/* Amount */}
                <div className="hidden min-w-0 w-28 shrink-0 text-right sm:block">
                  <p className="text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {formatCurrency(pay.amount)}
                  </p>
                </div>

                {/* Date */}
                <span className="hidden w-24 shrink-0 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted md:block">
                  {shortDate(pay.paid_at)}
                </span>

                {/* Status */}
                <span className="hidden w-24 shrink-0 justify-end md:flex">
                  <CrmStatusPill tone="green">Completed</CrmStatusPill>
                </span>
              </div>
            ))}
          </div>
        )}

        {payments.links && (
          <CrmPagination
            links={payments.links}
            from={payments.from ?? 0}
            to={payments.to ?? 0}
            total={payments.total}
            className="mt-4"
          />
        )}
      </CrmSection>
    </FinanceShell>
  );
}
