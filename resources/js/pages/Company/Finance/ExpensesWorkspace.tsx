import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Receipt, AlertCircle, DollarSign, Search, Plus } from 'lucide-react';
import { FinanceShell } from './components/FinanceShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmFilterBar,
  CrmInput,
  CrmSelect,
  CrmButton,
  CrmModal,
  CrmStatusPill,
  CrmPagination,
  CrmDatePicker,
} from '@/pages/Company/design';

/**
 * Finance → Expenses
 *
 * Operating expenses ledger rebuilt on the shared CRM design system.
 */

interface ExpenseItem {
  id: number;
  expense_number: string;
  description: string;
  category: string;
  amount: number;
  expense_date: string;
  status: string;
  notes?: string;
  submitter?: {
    id: number;
    name: string;
    email: string;
  };
  approver?: { id: number; name: string } | null;
  payer?: { id: number; name: string } | null;
  approved_at?: string | null;
  paid_at?: string | null;
  payment_reference?: string | null;
}

interface ExpensesWorkspaceProps {
  expenses: {
    data: ExpenseItem[];
    current_page: number;
    last_page: number;
    total: number;
    from?: number;
    to?: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
  };
  filters: {
    search?: string;
    category?: string;
    status?: string;
  };
  kpi: {
    total_expenses: number;
    pending_approval: number;
    approved_unpaid: number;
    paid: number;
    by_category: Record<string, number>;
  };
  can: { manage: boolean; approve: boolean };
}

/*
 * `pending` is legacy — one row in this database carries it, written before the
 * workflow existed. Nothing produces it any more; it reads as Submitted and can
 * still be approved, exactly as it stands.
 */
const STATUS_LABEL: Record<string, string> = {
  pending: 'Submitted',
  submitted: 'Submitted',
  approved: 'Approved',
  paid: 'Paid',
};

const STATUS_TONE: Record<string, 'green' | 'yellow' | 'blue' | 'neutral'> = {
  pending: 'yellow',
  submitted: 'yellow',
  approved: 'blue',
  paid: 'green',
};

const CATEGORY_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'software', label: 'Software' },
  { key: 'infrastructure', label: 'Cloud Infra' },
  { key: 'marketing', label: 'Marketing' },
  { key: 'office', label: 'Office' },
  { key: 'travel', label: 'Travel' },
] as const;

const categoryLabel = (cat: string) => {
  switch (cat) {
    case 'software': return 'Software / SaaS';
    case 'infrastructure': return 'Cloud Infra';
    case 'marketing': return 'Marketing';
    case 'office': return 'Office & Admin';
    case 'travel': return 'Travel & Field';
    default: return cat;
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

export default function ExpensesWorkspace({ expenses, filters = {}, kpi, can }: ExpensesWorkspaceProps) {
  const [search, setSearch] = useState(filters.search || '');
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [payFor, setPayFor] = useState<ExpenseItem | null>(null);
  const [paymentReference, setPaymentReference] = useState('');

  /*
   * Moving an expense along. Approving is a different authority from claiming,
   * so the two buttons are gated separately — the same split the server
   * enforces.
   */
  const move = (expense: ExpenseItem, status: string, body: Record<string, unknown> = {}) =>
    router.post(`/company/finance/expenses/${expense.id}/transition`, { status, ...body }, { preserveScroll: true });

  const [formData, setFormData] = useState({
    description: '',
    category: 'software',
    amount: '',
    expense_date: new Date().toISOString().split('T')[0],
    notes: '',
  });

  const apply = (changes: Record<string, string | undefined>) => {
    const next: Record<string, string | undefined> = { ...filters, ...changes };
    for (const key of Object.keys(next)) {
      if (!next[key] || next[key] === 'all') delete next[key];
    }
    delete (next as any).page;
    router.get('/company/finance/expenses', next as any, {
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

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    router.post('/company/finance/expenses', formData, {
      preserveScroll: true,
      onSuccess: () => {
        setAddModalOpen(false);
        setSubmitting(false);
        setFormData({
          description: '',
          category: 'software',
          amount: '',
          expense_date: new Date().toISOString().split('T')[0],
          notes: '',
        });
      },
      onError: () => {
        setSubmitting(false);
      },
    });
  };

  return (
    <FinanceShell title="Expenses">
      <CrmPageHead
        eyebrow="Finance"
        title="Expenses"
        actions={
          can.manage ? (
            <CrmButton tone="primary" onClick={() => setAddModalOpen(true)}>
              <Plus className="mr-1.5 h-4 w-4" aria-hidden="true" />
              Submit expense
            </CrmButton>
          ) : undefined
        }
        stats={
          <>
            <CrmPageStat
              icon={Receipt}
              value={formatCurrency(kpi.total_expenses)}
              caption="Committed operating spend"
            />
            <CrmPageStat
              icon={AlertCircle}
              value={formatCurrency(kpi.pending_approval)}
              chip={kpi.pending_approval > 0 ? 'Pending' : undefined}
              chipHue="#F59245"
              caption="Pending approval"
            />
            <CrmPageStat
              icon={DollarSign}
              value={formatCurrency(
                Number(kpi.by_category?.['software'] || 0) + Number(kpi.by_category?.['infrastructure'] || 0)
              )}
              caption="Software & Infra"
            />
          </>
        }
      />

      <CrmSection
        title="Operating expenses"
        description={`${expenses.total?.toLocaleString('en-IN') ?? 0} record${(expenses.total ?? 0) === 1 ? '' : 's'}`}
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
              placeholder="Search expenses"
              aria-label="Search expenses"
              className="w-full pl-9 sm:w-64"
            />
          </div>
        }
      >
        <CrmFilterBar
          className="mb-4"
          segments={CATEGORY_FILTERS.map((c) => ({
            key: c.key,
            label: c.label,
          }))}
          segmentValue={filters.category || 'all'}
          onSegmentChange={(key) => apply({ category: key })}
          inlineSegments
        />

        {expenses.data.length === 0 ? (
          <div className="py-16 text-center">
            <p className="text-[13px] text-ovs-crm-text-muted">
              {search || filters.category
                ? 'Nothing matches these filters.'
                : 'Nothing recorded yet. A submitted expense waits for approval before it can be paid.'}
            </p>
          </div>
        ) : (
          <div className="-mx-2.5">
            {expenses.data.map((exp) => (
              <div
                key={exp.id}
                className="group flex cursor-default items-center gap-3 rounded-[14px] px-2.5 py-2.5 transition-colors hover:bg-ovs-crm-surface-inset"
              >
                {/* Number & Description */}
                <div className="min-w-0 flex-[1.5]">
                  <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">
                    {exp.description}
                  </p>
                  <p className="truncate text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {exp.expense_number} • {categoryLabel(exp.category)}
                  </p>
                </div>

                {/* Submitter */}
                <div className="hidden min-w-0 flex-1 lg:block">
                  <p className="truncate text-[12px] text-ovs-crm-text-secondary">
                    {exp.submitter?.name || 'Finance Admin'}
                  </p>
                  {exp.notes && (
                    <p className="truncate text-[11px] text-ovs-crm-text-muted">
                      {exp.notes}
                    </p>
                  )}
                </div>

                {/* Amount */}
                <div className="hidden min-w-0 w-28 shrink-0 text-right sm:block">
                  <p className="text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {formatCurrency(exp.amount)}
                  </p>
                </div>

                {/* Date */}
                <span className="hidden w-24 shrink-0 text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted md:block">
                  {shortDate(exp.expense_date)}
                </span>

                {/*
                  Four states, not two. This rendered everything as either
                  Approved or Pending, which could not show a paid expense and
                  had no idea a submitted one existed.
                */}
                <span className="hidden w-28 shrink-0 justify-end md:flex">
                  <CrmStatusPill tone={STATUS_TONE[exp.status] ?? 'neutral'}>
                    {STATUS_LABEL[exp.status] ?? exp.status}
                  </CrmStatusPill>
                </span>

                <span className="flex shrink-0 items-center gap-1.5">
                  {can.approve && (exp.status === 'submitted' || exp.status === 'pending') && (
                    <CrmButton size="sm" onClick={() => move(exp, 'approved')}>
                      Approve
                    </CrmButton>
                  )}
                  {can.manage && exp.status === 'approved' && (
                    <CrmButton size="sm" tone="primary" onClick={() => { setPayFor(exp); setPaymentReference(''); }}>
                      Mark paid
                    </CrmButton>
                  )}
                </span>
              </div>
            ))}
          </div>
        )}

        {expenses.links && (
          <CrmPagination
            links={expenses.links}
            from={expenses.from ?? 0}
            to={expenses.to ?? 0}
            total={expenses.total}
            className="mt-4"
          />
        )}
      </CrmSection>

      {/* Log Expense Modal */}
      <CrmModal
        open={addModalOpen}
        onClose={() => setAddModalOpen(false)}
        title="Log Company Expense"
        description="Record an operational expense or vendor invoice."
        size="sm"
        footer={
          <>
            <CrmButton tone="ghost" onClick={() => setAddModalOpen(false)}>
              Cancel
            </CrmButton>
            <CrmButton tone="primary" onClick={handleSubmit} loading={submitting}>
              Save expense
            </CrmButton>
          </>
        }
      >
        <form onSubmit={handleSubmit} className="space-y-4 pt-1">
          <div>
            <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
              Description
            </label>
            <CrmInput
              required
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              placeholder="e.g. AWS Cloud Hosting — August 2026"
              className="mt-1 w-full"
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                Category
              </label>
              <CrmSelect
                value={formData.category}
                onChange={(e) => setFormData({ ...formData, category: e.target.value })}
                className="mt-1 w-full"
              >
                <option value="software">Software / SaaS</option>
                <option value="infrastructure">Cloud Infra</option>
                <option value="marketing">Marketing</option>
                <option value="office">Office & Admin</option>
                <option value="travel">Travel & Field</option>
              </CrmSelect>
            </div>

            <div>
              <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
                Amount (₹)
              </label>
              <CrmInput
                required
                type="number"
                step="0.01"
                min="0.01"
                value={formData.amount}
                onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
                placeholder="45000"
                className="mt-1 w-full tabular-nums"
              />
            </div>
          </div>

          <div>
            <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
              Expense Date
            </label>
            <CrmDatePicker
              required
              value={formData.expense_date}
              onChange={(e) => setFormData({ ...formData, expense_date: e.target.value })}
              className="mt-1 w-full"
            />
          </div>

          <div>
            <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
              Notes (Optional)
            </label>
            <CrmInput
              value={formData.notes}
              onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
              placeholder="Vendor ref, invoice no, or internal context"
              className="mt-1 w-full"
            />
          </div>
        </form>
      </CrmModal>

      {/*
        Paying is a deliberate step, not a side effect of approving. The
        reference is stored against the expense so the movement can be traced
        back to how it was settled.
      */}
      <CrmModal
        open={payFor !== null}
        onClose={() => setPayFor(null)}
        title="Record payment"
        description={payFor ? `${payFor.expense_number} — ${formatCurrency(payFor.amount)}` : ''}
        size="sm"
        footer={
          <>
            <CrmButton tone="ghost" onClick={() => setPayFor(null)}>
              Cancel
            </CrmButton>
            <CrmButton
              tone="primary"
              onClick={() => {
                if (payFor) {
                  move(payFor, 'paid', { payment_reference: paymentReference || null });
                  setPayFor(null);
                }
              }}
            >
              Mark paid
            </CrmButton>
          </>
        }
      >
        <div className="pt-1">
          <label className="block text-[11.5px] font-semibold text-ovs-crm-text-secondary">
            Payment reference
          </label>
          <CrmInput
            value={paymentReference}
            onChange={(e) => setPaymentReference(e.target.value)}
            placeholder="Bank reference, batch id, however it was settled"
            className="mt-1 w-full"
          />
          <p className="mt-2 text-[11.5px] text-ovs-crm-text-muted">
            No ledger entry is posted. This records that the money moved, and when.
          </p>
        </div>
      </CrmModal>
    </FinanceShell>
  );
}
