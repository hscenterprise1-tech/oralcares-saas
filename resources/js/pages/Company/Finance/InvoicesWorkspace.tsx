import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { FileText, CheckCircle2, AlertCircle, Clock, Search } from 'lucide-react';
import { FinanceShell } from './components/FinanceShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmFilterBar,
  CrmInput,
  CrmStatusPill,
  CrmPagination,
  inr,
} from '@/pages/Company/design';

/**
 * Finance → Invoices
 *
 * Commercial invoice ledger rebuilt on the shared CRM design system.
 * Uses Marketing-style dense operational rows instead of the OVS table
 * component, so it matches the rest of the product.
 */

interface InvoiceItem {
  id: number;
  invoice_number: string;
  final_amount: number;
  currency: string;
  status: string;
  due_date: string;
  created_at: string;
  clinic?: {
    id: number;
    name: string;
  };
}

interface InvoicesWorkspaceProps {
  invoices: {
    data: InvoiceItem[];
    current_page: number;
    last_page: number;
    total: number;
    from: number;
    to: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
  };
  filters: {
    search?: string;
    status?: string;
  };
  kpi: {
    total_invoiced: number;
    paid_amount: number;
    overdue_amount: number;
    draft_count: number;
  };
}

const STATUS_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'paid', label: 'Paid' },
  { key: 'sent', label: 'Issued' },
  { key: 'overdue', label: 'Overdue' },
  { key: 'draft', label: 'Draft' },
] as const;

const statusTone = (status: string): 'green' | 'blue' | 'red' | 'yellow' | 'neutral' => {
  switch (status) {
    case 'paid': return 'green';
    case 'sent':
    case 'issued': return 'blue';
    case 'overdue': return 'red';
    case 'draft': return 'yellow';
    default: return 'neutral';
  }
};

const statusLabel = (status: string) => {
  switch (status) {
    case 'paid': return 'Paid';
    case 'sent':
    case 'issued': return 'Issued';
    case 'overdue': return 'Overdue';
    case 'draft': return 'Draft';
    default: return status;
  }
};

const formatCurrency = (val: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(val);

const shortDate = (iso: string | null | undefined) => {
  if (!iso) return '—';
  const d = new Date(iso);
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: '2-digit' });
};

export default function InvoicesWorkspace({ invoices, filters = {}, kpi }: InvoicesWorkspaceProps) {
  const [search, setSearch] = useState(filters.search || '');

  const apply = (changes: Record<string, string | undefined>) => {
    const next: Record<string, string | undefined> = { ...filters, ...changes };
    for (const key of Object.keys(next)) {
      if (!next[key] || next[key] === 'all') delete next[key];
    }
    delete (next as any).page;
    router.get('/company/finance/invoices', next as any, {
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
    <FinanceShell title="Invoices">
      <CrmPageHead
        eyebrow="Finance"
        title="Invoices"
        stats={
          <>
            <CrmPageStat
              icon={FileText}
              value={formatCurrency(kpi.total_invoiced)}
              caption="Total invoiced"
            />
            <CrmPageStat
              icon={CheckCircle2}
              value={formatCurrency(kpi.paid_amount)}
              caption="Collected revenue"
            />
            <CrmPageStat
              icon={AlertCircle}
              value={formatCurrency(kpi.overdue_amount)}
              chip={kpi.overdue_amount > 0 ? 'Overdue' : undefined}
              chipHue="#EF5B39"
              caption="Pending collection"
            />
          </>
        }
      />

      <CrmSection
        title="Invoice ledger"
        description={`${invoices.total?.toLocaleString('en-IN') ?? 0} invoice${(invoices.total ?? 0) === 1 ? '' : 's'}`}
        actions={
          <div className="relative">
            <Search
              className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-ovs-crm-text-muted"
              aria-hidden="true"
            />
            <CrmInput
              type="search"
              value={search}
              onChange={(e) => onSearchChange(e.target.value)}
              placeholder="Search invoices"
              aria-label="Search invoices"
              className="w-full pl-9 sm:w-64"
            />
          </div>
        }
      >
        <CrmFilterBar
          className="mb-4"
          segments={STATUS_FILTERS.map((f) => ({
            key: f.key,
            label: f.label,
          }))}
          segmentValue={filters.status || 'all'}
          onSegmentChange={(key) => apply({ status: key })}
          inlineSegments
        />

        {invoices.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || filters.status
                ? 'Nothing matches these filters.'
                : 'No invoices yet. Invoices will appear here once customers are billed.'}
            </p>
          </div>
        ) : (
          <div className="-mx-2.5">
            {invoices.data.map((inv) => (
              <div
                key={inv.id}
                className="group flex cursor-pointer items-center gap-3 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
              >
                {/* Invoice number */}
                <div className="min-w-0 flex-[1.5]">
                  <p className="truncate text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {inv.invoice_number}
                  </p>
                  <p className="truncate text-[11.5px] text-ovs-crm-text-muted">
                    {inv.clinic?.name || 'Commercial Client'}
                  </p>
                </div>

                {/* Amount */}
                <div className="hidden min-w-0 flex-1 text-right sm:block">
                  <p className="text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {formatCurrency(inv.final_amount)}
                  </p>
                </div>

                {/* Due date */}
                <div className="hidden min-w-0 w-28 shrink-0 lg:block">
                  <p className="text-[11.5px] tabular-nums text-ovs-crm-text-secondary">
                    Due {shortDate(inv.due_date)}
                  </p>
                  <p className="text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    Created {shortDate(inv.created_at)}
                  </p>
                </div>

                {/* Status */}
                <span className="hidden w-24 shrink-0 justify-end md:flex">
                  <CrmStatusPill tone={statusTone(inv.status)}>{statusLabel(inv.status)}</CrmStatusPill>
                </span>
              </div>
            ))}
          </div>
        )}

        {invoices.links && (
          <CrmPagination
            links={invoices.links}
            from={invoices.from}
            to={invoices.to}
            total={invoices.total}
            className="mt-4"
          />
        )}
      </CrmSection>
    </FinanceShell>
  );
}
