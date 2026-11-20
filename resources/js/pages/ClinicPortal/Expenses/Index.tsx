import { useState, type FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Plus, Repeat, Wallet } from 'lucide-react';
import { BillingTabs, clean, day, FilterBar, Figure, Pager, when, type BillingTab, type PagedList } from '@/clinic/billing/BillingFrame';
import { useOwnerModeStepUp } from '@/clinic/ownerModeStepUp';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { StatusBadge } from '@/clinic/ui/badges';
import { Button, buttonClasses } from '@/clinic/ui/Button';
import { Card, CardHeader } from '@/clinic/ui/Card';
import { Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { DatePicker } from '@/clinic/ui/DatePicker';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { formatMoney, formatNumber, humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

type Status = 'pending' | 'approved' | 'paid' | 'rejected';

interface ExpenseRow {
  id: number;
  title: string;
  amount: number;
  spent_on: string;
  category: string | null;
  vendor: string | null;
  branch: string | null;
  status: Status;
  method: string | null;
  reference: string | null;
  notes: string | null;
  recurring: boolean;
  rejection_reason: string | null;
  recorded_by: string | null;
  recorded_at: string | null;
  decided_by: string | null;
  decided_at: string | null;
  paid_at: string | null;
  own: boolean;
  receipt: string | null;
  can: { decide: boolean; decide_direct: boolean; pay: boolean; pay_direct: boolean };
}

interface Named {
  id: number;
  name: string;
}

interface Filters {
  from: string | null;
  to: string | null;
  period: 'all' | null;
  status: Status | null;
  category_id: number | null;
  vendor_id: number | null;
  branch_id: number | null;
  q: string;
}

interface Props {
  filters: Filters;
  summary: { spent: number; paid: number; unpaid: number; pending: { amount: number; count: number }; by_category: { name: string; total: number }[] };
  expenses: PagedList<ExpenseRow>;
  options: { categories: Named[]; vendors: Named[]; branches: Named[]; departments: Named[]; methods: string[]; frequencies: string[] };
  recurring: { id: number; title: string; amount: number; frequency: string; next_due_on: string; category: string | null; vendor: string | null; active: boolean }[];
  can: { approve: boolean; record_clinic_wide: boolean };
  tabs: BillingTab[];
  links: { store: string; approve: string; reject: string; pay: string; recurring_active: string };
}

const STATUSES: { value: Status; label: string }[] = [
  { value: 'pending', label: 'Waiting for a decision' },
  { value: 'approved', label: 'Approved, not paid' },
  { value: 'paid', label: 'Paid' },
  { value: 'rejected', label: 'Rejected' },
];

const STATUS_LABEL: Record<Status, string> = { pending: 'Waiting for decision', approved: 'Approved · to pay', paid: 'Paid', rejected: 'Rejected' };

const EMPTY: Filters = { from: null, to: null, period: null, status: null, category_id: null, vendor_id: null, branch_id: null, q: '' };

const options = (rows: Named[]) => rows.map((row) => ({ value: String(row.id), label: row.name }));
const numberOrNull = (value: string) => (value ? Number(value) : null);

/**
 * Expenses: what the clinic spends, from recording to a decision to payment.
 * Waiting-for-a-decision and approved-but-unpaid are kept apart. Every
 * decision and payment is the server's to allow, row by row: never one's own
 * (except the owner's explicit direct path), never paying what was not
 * approved, and in Owner Mode for the owner. "All dates" shows every record
 * however old, which is what a dashboard count of open expenses opens.
 */
export default function ExpensesIndex({ filters, summary, expenses, options: choices, recurring, can, tabs, links }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [draft, setDraft] = useState(filters);
  const [open, setOpen] = useState<ExpenseRow | null>(null);
  const [recording, setRecording] = useState(false);
  const allDates = filters.period === 'all';
  const dirty = Boolean(filters.status || filters.category_id || filters.vendor_id || filters.branch_id || filters.q || allDates || filters.from || filters.to);
  const apply = (next: Filters = draft) =>
    router.get(window.location.pathname, clean({ ...next, from: next.period === 'all' ? null : next.from, to: next.period === 'all' ? null : next.to }), { preserveScroll: true, preserveState: true });
  const quick = (status: Status) => {
    const next = { ...filters, status, period: 'all' as const };
    setDraft(next);
    apply(next);
  };
  const range = allDates ? 'all dates' : `${day(filters.from)} – ${day(filters.to)}`;

  return (
    <ClinicPortalShell title="Expenses">
      <PageHeader
        icon={Wallet}
        title="Billing"
        description="What the clinic spends: recorded, decided, then paid."
        actions={
          <Button tone="primary" icon={Plus} onClick={() => setRecording(true)}>
            Record expense
          </Button>
        }
      />
      <BillingTabs tabs={tabs} current="expenses" />

      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button type="button" onClick={() => quick('pending')} className="rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal">
          <Figure label="Waiting for a decision" value={summary.pending.amount} tone="amber" note={`${formatNumber(summary.pending.count)} in ${range} · show all dates`} />
        </button>
        <button type="button" onClick={() => quick('approved')} className="rounded-2xl text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-nav-teal">
          <Figure label="Approved, still to pay" value={summary.unpaid} tone="red" note={`In ${range} · show all dates`} />
        </button>
        <Figure label="Paid" value={summary.paid} tone="green" note={`Dated ${range}`} />
        <Figure label="Spent" value={summary.spent} tone="brand" note="Approved or paid, by the expense date" />
      </div>

      <FilterBar onApply={() => apply()} onClear={() => { setDraft(EMPTY); apply(EMPTY); }} dirty={dirty}>
        <Field label="Search" htmlFor="expenses-q" className="min-w-[200px] flex-1">
          <Input id="expenses-q" type="search" placeholder="Title or reference" value={draft.q} onChange={(event) => setDraft({ ...draft, q: event.target.value })} />
        </Field>
        <Field label="Status" htmlFor="expenses-status">
          <Select id="expenses-status" value={draft.status ?? ''} onChange={(event) => setDraft({ ...draft, status: (event.target.value || null) as Status | null })} options={STATUSES} placeholder="Any status" />
        </Field>
        <Field label="Category" htmlFor="expenses-category">
          <Select id="expenses-category" value={draft.category_id ? String(draft.category_id) : ''} onChange={(event) => setDraft({ ...draft, category_id: numberOrNull(event.target.value) })} options={options(choices.categories)} placeholder="Any category" />
        </Field>
        {choices.vendors.length > 0 && (
          <Field label="Vendor" htmlFor="expenses-vendor">
            <Select id="expenses-vendor" value={draft.vendor_id ? String(draft.vendor_id) : ''} onChange={(event) => setDraft({ ...draft, vendor_id: numberOrNull(event.target.value) })} options={options(choices.vendors)} placeholder="Any vendor" />
          </Field>
        )}
        {choices.branches.length > 1 && (
          <Field label="Branch" htmlFor="expenses-branch">
            <Select id="expenses-branch" value={draft.branch_id ? String(draft.branch_id) : ''} onChange={(event) => setDraft({ ...draft, branch_id: numberOrNull(event.target.value) })} options={options(choices.branches)} placeholder="All branches" />
          </Field>
        )}
        <Field label="Dates" htmlFor="expenses-period">
          <Select
            id="expenses-period"
            value={draft.period === 'all' ? 'all' : 'range'}
            onChange={(event) => setDraft({ ...draft, period: event.target.value === 'all' ? 'all' : null })}
            options={[
              { value: 'range', label: 'Between dates' },
              { value: 'all', label: 'All dates' },
            ]}
          />
        </Field>
        {draft.period !== 'all' && (
          <>
            <Field label="From" htmlFor="expenses-from">
              <DatePicker id="expenses-from" value={draft.from ?? ''} onChange={(value) => setDraft({ ...draft, from: value || null })} max={draft.to ?? undefined} />
            </Field>
            <Field label="To" htmlFor="expenses-to">
              <DatePicker id="expenses-to" value={draft.to ?? ''} onChange={(value) => setDraft({ ...draft, to: value || null })} min={draft.from ?? undefined} />
            </Field>
          </>
        )}
      </FilterBar>

      {errors.expense && (
        <Notice tone="danger" title="Not done" className="mb-4">
          {errors.expense}
        </Notice>
      )}

      <Card>
        {expenses.data.length === 0 ? (
          <EmptyState
            filtered={dirty}
            onClearFilters={() => { setDraft(EMPTY); apply(EMPTY); }}
            title={dirty ? 'No expenses match these filters' : 'No expenses this month'}
            description={dirty ? 'Try another search or status, or all dates.' : 'Record rent, supplies, repairs and other spending here.'}
          />
        ) : (
          <ul className="divide-y divide-cx-line" aria-label="Expenses">
            {expenses.data.map((expense) => (
              <li key={expense.id}>
                <button type="button" onClick={() => setOpen(expense)} className="grid w-full grid-cols-1 gap-x-4 gap-y-1 px-5 py-3.5 text-left hover:bg-cx-nav-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal sm:grid-cols-[1.4fr_1fr_auto_auto] sm:items-center">
                  <span className="min-w-0">
                    <span className="block truncate text-[14px] font-semibold text-cx-nav-ink">
                      {expense.title}
                      {expense.recurring && <Repeat className="ml-1.5 inline h-3.5 w-3.5 text-cx-nav-muted" aria-label="Recurring" />}
                    </span>
                    <span className="block truncate text-[12.5px] text-cx-nav-muted">{[expense.category, expense.vendor, expense.branch ?? 'Whole clinic'].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span className="text-[12.5px] text-cx-text-3">
                    {day(expense.spent_on)}
                    {expense.recorded_by ? ` · by ${expense.recorded_by}` : ''}
                  </span>
                  <span className="text-right text-[14px] font-semibold tabular-nums text-cx-text">{formatMoney(expense.amount)}</span>
                  <StatusBadge status={expense.status === 'approved' ? 'unpaid' : expense.status} label={STATUS_LABEL[expense.status]} className="justify-self-start sm:justify-self-end" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Pager page={expenses} query={{ ...filters }} />

      <div className="mt-6 grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Spent by category" description={`Approved or paid, ${range}`} />
          {summary.by_category.length === 0 ? (
            <p className="px-5 pb-5 pt-3 text-[13px] text-cx-text-3">Nothing approved or paid in this range.</p>
          ) : (
            <ul className="divide-y divide-cx-line px-5 pb-3 pt-2 text-[13.5px]">
              {summary.by_category.map((row) => (
                <li key={row.name} className="flex justify-between py-2">
                  <span>{row.name}</span>
                  <span className="font-semibold tabular-nums">{formatMoney(row.total)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <RecurringCard recurring={recurring} toggleUrl={links.recurring_active} />
      </div>

      {open && <ExpenseDialog expense={open} links={links} methods={choices.methods} onClose={() => setOpen(null)} />}
      {recording && <RecordDialog url={links.store} options={choices} canRecordClinicWide={can.record_clinic_wide} onClose={() => setRecording(false)} />}
    </ClinicPortalShell>
  );
}

type Decision = 'approve' | 'reject' | 'pay' | null;

/**
 * One expense in full, with only the actions the server offers on it. The
 * owner deciding or paying their own expense goes through the explicit,
 * re-verified direct path, named as such, never silently. Paying never
 * approves: only an approved expense can be paid.
 */
function ExpenseDialog({ expense, links, methods, onClose }: { expense: ExpenseRow; links: Props['links']; methods: string[]; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const stepUp = useOwnerModeStepUp();
  const [decision, setDecision] = useState<Decision>(null);
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState(expense.method ?? '');
  const [reference, setReference] = useState('');
  const mayDecide = expense.can.decide || expense.can.decide_direct;
  const mayPay = expense.can.pay || expense.can.pay_direct;
  const direct = (decision === 'pay' ? expense.can.pay_direct : expense.can.decide_direct) ? { sole_owner_direct: 1 } : {};
  const url = (template: string) => template.replace('__ID__', String(expense.id));

  const send = (event: FormEvent) => {
    event.preventDefault();
    const target = decision === 'approve' ? links.approve : decision === 'reject' ? links.reject : links.pay;
    const data = decision === 'reject' ? { reason: reason.trim(), ...direct } : decision === 'pay' ? { method, reference: reference.trim() || null, ...direct } : { ...direct };
    stepUp.submit({ method: 'post', url: url(target), data, onSuccess: onClose });
  };

  const facts: [string, string | null][] = [
    ['Amount', formatMoney(expense.amount)],
    ['Expense date', day(expense.spent_on)],
    ['Category', expense.category],
    ['Vendor', expense.vendor],
    ['Branch', expense.branch ?? 'Whole clinic'],
    ['Reference', expense.reference],
    ['Recorded', [expense.recorded_by, when(expense.recorded_at)].filter(Boolean).join(' · ') || null],
    [expense.status === 'rejected' ? 'Rejected' : 'Decided', expense.decided_at ? [expense.decided_by, when(expense.decided_at)].filter(Boolean).join(' · ') : null],
    ['Paid', expense.paid_at ? [when(expense.paid_at), expense.method ? humanize(expense.method) : null].filter(Boolean).join(' · ') : null],
  ];

  return (
    <Modal open onOpenChange={(next) => !next && onClose()} title={expense.title} description={STATUS_LABEL[expense.status]} size="md">
      <dl className="grid grid-cols-1 gap-x-6 gap-y-3 text-[13.5px] sm:grid-cols-2">
        {facts
          .filter(([, value]) => value)
          .map(([label, value]) => (
            <div key={label}>
              <dt className="text-[12px] font-semibold text-cx-text-3">{label}</dt>
              <dd className="mt-0.5 text-cx-text">{value}</dd>
            </div>
          ))}
      </dl>
      {expense.notes && <p className="mt-4 rounded-xl bg-cx-nav-bg/60 px-3 py-2 text-[13px] text-cx-text-2">{expense.notes}</p>}
      {expense.rejection_reason && (
        <Notice tone="warning" title="Why it was rejected" className="mt-4">
          {expense.rejection_reason}
        </Notice>
      )}
      {expense.own && expense.status === 'pending' && !expense.can.decide_direct && (
        <p className="mt-4 text-[12.5px] text-cx-text-3">You recorded this expense, so someone else decides it.</p>
      )}

      {decision === null ? (
        <div className="mt-5 flex flex-wrap gap-2">
          {mayDecide && (
            <>
              <Button tone="primary" onClick={() => setDecision('approve')}>
                {expense.can.decide_direct ? 'Approve my own expense' : 'Approve'}
              </Button>
              <Button tone="danger" onClick={() => setDecision('reject')}>
                Reject
              </Button>
            </>
          )}
          {mayPay && (
            <Button tone="primary" onClick={() => setDecision('pay')}>
              {expense.can.pay_direct ? 'Mark my own expense paid' : 'Mark paid'}
            </Button>
          )}
          {expense.receipt && (
            <a href={expense.receipt} className={buttonClasses('secondary', 'md')}>
              Download receipt
            </a>
          )}
        </div>
      ) : (
        <form onSubmit={send} className="mt-5 space-y-3 rounded-2xl border border-cx-line p-4">
          <p className="text-[13.5px] font-semibold text-cx-nav-ink">
            {decision === 'approve' && `Approve ${formatMoney(expense.amount)}? It will then wait to be paid.`}
            {decision === 'reject' && 'Reject this expense? It will not be paid.'}
            {decision === 'pay' && `Record that ${formatMoney(expense.amount)} was paid. This records the payment; it does not send money.`}
          </p>
          {Object.keys(direct).length > 0 && (
            <p className="text-[12.5px] text-cx-text-3">This is your own expense. As the sole owner you may decide it yourself; it is recorded as your direct decision, and Owner Mode is checked again.</p>
          )}
          {decision === 'reject' && (
            <Field label="Reason" htmlFor="expense-reason" required error={errors.reason}>
              <Textarea id="expense-reason" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} required />
            </Field>
          )}
          {decision === 'pay' && (
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label="Paid by" htmlFor="expense-method" required error={errors.method}>
                <Select id="expense-method" value={method} onChange={(event) => setMethod(event.target.value)} options={methods.map((value) => ({ value, label: humanize(value) }))} placeholder="Choose" required />
              </Field>
              <Field label="Reference" htmlFor="expense-reference" hint="Cheque, UPI or bank reference">
                <Input id="expense-reference" value={reference} onChange={(event) => setReference(event.target.value)} maxLength={100} />
              </Field>
            </div>
          )}
          {errors.expense && <p className="text-[13px] font-medium text-rose-700">{errors.expense}</p>}
          <div className="flex flex-wrap gap-2">
            <Button type="submit" tone={decision === 'reject' ? 'danger' : 'primary'} disabled={stepUp.busy || (decision === 'reject' && !reason.trim()) || (decision === 'pay' && !method)}>
              {decision === 'approve' ? 'Approve' : decision === 'reject' ? 'Reject' : 'Record payment'}
            </Button>
            <Button type="button" tone="ghost" onClick={() => setDecision(null)} disabled={stepUp.busy}>
              Back
            </Button>
          </div>
        </form>
      )}
      {stepUp.dialog}
    </Modal>
  );
}

/** Record a new expense. It starts waiting for a decision; the server decides who may approve it. */
function RecordDialog({ url, options: choices, canRecordClinicWide, onClose }: { url: string; options: Props['options']; canRecordClinicWide: boolean; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const today = new Date();
  const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  const [form, setForm] = useState({
    title: '',
    amount: '',
    spent_on: todayIso,
    category_id: '',
    vendor_id: '',
    branch_id: !canRecordClinicWide && choices.branches.length > 0 ? String(choices.branches[0].id) : '',
    reference: '',
    notes: '',
  });
  const [receipt, setReceipt] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (field: keyof typeof form) => (value: string) => setForm({ ...form, [field]: value });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    router.post(url, { ...form, vendor_id: form.vendor_id || null, branch_id: form.branch_id || null, receipt }, {
      forceFormData: true,
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: (page) => {
        if (!(page.props.errors as Record<string, string> | undefined)?.expense) {
          onClose();
        }
      },
    });
  };

  return (
    <Modal open onOpenChange={(next) => !next && onClose()} title="Record expense" description="It will wait for a decision before it can be paid." size="lg">
      <form id="record-expense" onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
        <Field label="What was it for" htmlFor="new-title" required error={errors.title} className="sm:col-span-2">
          <Input id="new-title" value={form.title} onChange={(event) => set('title')(event.target.value)} maxLength={160} required />
        </Field>
        <Field label="Amount (₹)" htmlFor="new-amount" required error={errors.amount}>
          <Input id="new-amount" type="number" inputMode="decimal" min="0.01" step="0.01" value={form.amount} onChange={(event) => set('amount')(event.target.value)} required />
        </Field>
        <Field label="Expense date" htmlFor="new-date" required error={errors.spent_on}>
          <DatePicker id="new-date" value={form.spent_on} onChange={set('spent_on')} max={todayIso} />
        </Field>
        <Field label="Category" htmlFor="new-category" required error={errors.category_id}>
          <Select id="new-category" value={form.category_id} onChange={(event) => set('category_id')(event.target.value)} options={options(choices.categories)} placeholder="Choose" required />
        </Field>
        <Field label="Vendor" htmlFor="new-vendor" error={errors.vendor_id}>
          <Select id="new-vendor" value={form.vendor_id} onChange={(event) => set('vendor_id')(event.target.value)} options={options(choices.vendors)} placeholder="None" />
        </Field>
        {(choices.branches.length > 1 || canRecordClinicWide) && (
          <Field label="Branch" htmlFor="new-branch" error={errors.branch_id}>
            <Select
              id="new-branch"
              value={form.branch_id}
              onChange={(event) => set('branch_id')(event.target.value)}
              options={options(choices.branches)}
              placeholder={canRecordClinicWide ? 'Whole clinic' : undefined}
              required={!canRecordClinicWide}
            />
          </Field>
        )}
        <Field label="Reference" htmlFor="new-reference" error={errors.reference}>
          <Input id="new-reference" value={form.reference} onChange={(event) => set('reference')(event.target.value)} maxLength={100} />
        </Field>
        <Field label="Notes" htmlFor="new-notes" error={errors.notes} className="sm:col-span-2">
          <Textarea id="new-notes" value={form.notes} onChange={(event) => set('notes')(event.target.value)} maxLength={1000} />
        </Field>
        <Field label="Receipt" htmlFor="new-receipt" hint="PDF or image, up to 10 MB" error={errors.receipt} className="sm:col-span-2">
          <input id="new-receipt" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" onChange={(event) => setReceipt(event.target.files?.[0] ?? null)} className="block w-full text-[13px] file:mr-3 file:rounded-full file:border-0 file:bg-cx-nav-bg file:px-4 file:py-2 file:text-[13px] file:font-semibold file:text-cx-nav-ink" />
        </Field>
        {errors.expense && <p className="text-[13px] font-medium text-rose-700 sm:col-span-2">{errors.expense}</p>}
      </form>
      <div className="mt-5 flex flex-wrap justify-end gap-2">
        <Button type="button" tone="ghost" onClick={onClose} disabled={busy}>
          Cancel
        </Button>
        <Button type="submit" form="record-expense" tone="primary" disabled={busy}>
          Record expense
        </Button>
      </div>
    </Modal>
  );
}

/** Expenses that repeat: what is due next, and pause or resume. Each is recorded for a decision when it falls due. */
function RecurringCard({ recurring, toggleUrl }: { recurring: Props['recurring']; toggleUrl: string }) {
  const stepUp = useOwnerModeStepUp();

  return (
    <Card>
      <CardHeader title="Recurring expenses" description="Recorded for a decision each time they fall due" />
      {recurring.length === 0 ? (
        <p className="px-5 pb-5 pt-3 text-[13px] text-cx-text-3">No recurring expenses set up.</p>
      ) : (
        <ul className="divide-y divide-cx-line px-5 pb-3 pt-2">
          {recurring.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-[13.5px]">
              <span className={cn('min-w-0', !row.active && 'text-cx-text-3')}>
                <span className="block font-medium">{row.title}</span>
                <span className="block text-[12px] text-cx-text-3">
                  {formatMoney(row.amount)} · {humanize(row.frequency)} · {row.active ? `next ${day(row.next_due_on)}` : 'paused'}
                </span>
              </span>
              <Button size="sm" tone="ghost" disabled={stepUp.busy} onClick={() => stepUp.submit({ method: 'post', url: toggleUrl.replace('__ID__', String(row.id)), data: { active: !row.active } })}>
                {row.active ? 'Pause' : 'Resume'}
              </Button>
            </li>
          ))}
        </ul>
      )}
      {stepUp.dialog}
    </Card>
  );
}
