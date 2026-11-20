import { useState } from 'react';
import { Wallet } from 'lucide-react';
import { router } from '@inertiajs/react';
import { BillDetailDialog } from '@/clinic/billing/BillDetailDialog';
import { BillingTabs, clean, FilterBar, Figure, Pager, when, type BillingTab, type PagedList } from '@/clinic/billing/BillingFrame';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { StatusBadge } from '@/clinic/ui/badges';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { DatePicker } from '@/clinic/ui/DatePicker';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { EmptyState } from '@/clinic/ui/states';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';

interface ReversalRow {
  key: string;
  type: 'refund' | 'adjustment';
  number: string | null;
  bill_id: number;
  bill_number: string;
  patient: string;
  uhid: string | null;
  branch: string | null;
  amount: number;
  method: string | null;
  reason: string | null;
  recorded_by: string | null;
  recorded_at: string;
}

interface Props {
  reversals: PagedList<ReversalRow>;
  totals: { count: number; refunded: number; adjusted: number };
  filters: { q: string; type: 'refund' | 'adjustment' | null; branch: number | null; from: string | null; to: string | null };
  branches: { value: string; label: string }[];
  tabs: BillingTab[];
  links: { bill: string };
}

const TYPES = [
  { value: 'refund', label: 'Refunds' },
  { value: 'adjustment', label: 'Adjustments' },
];

/**
 * Refunds & Adjustments: every refund and balance adjustment recorded, newest
 * first. Both are recorded the moment they are made (there is no request or
 * approval stage behind either); a refund records money returned, it does
 * not prove a transfer. New ones are made from a bill.
 */
export default function BillingReversals({ reversals, totals, filters, branches, tabs, links }: Props) {
  const [draft, setDraft] = useState(filters);
  const [open, setOpen] = useState<number | null>(null);
  const empty = { q: '', type: null, branch: null, from: null, to: null };
  const apply = (next: Props['filters'] = draft) => router.get(window.location.pathname, clean(next), { preserveScroll: true, preserveState: true });
  const dirty = Boolean(filters.q || filters.type || filters.branch || filters.from || filters.to);

  return (
    <ClinicPortalShell title="Refunds & Adjustments">
      <PageHeader icon={Wallet} title="Billing" description="Money returned to patients, and balances conceded or written off, with who recorded each and why." />
      <BillingTabs tabs={tabs} current="reversals" />

      <FilterBar onApply={() => apply()} onClear={() => { setDraft(empty); apply(empty); }} dirty={dirty}>
        <Field label="Search" htmlFor="reversals-q" className="min-w-[220px] flex-1">
          <Input id="reversals-q" type="search" placeholder="Bill number, patient, UHID or mobile" value={draft.q} onChange={(event) => setDraft({ ...draft, q: event.target.value })} />
        </Field>
        <Field label="Type" htmlFor="reversals-type">
          <Select id="reversals-type" value={draft.type ?? ''} onChange={(event) => setDraft({ ...draft, type: (event.target.value || null) as Props['filters']['type'] })} options={TYPES} placeholder="Both" />
        </Field>
        {branches.length > 1 && (
          <Field label="Branch" htmlFor="reversals-branch">
            <Select id="reversals-branch" value={draft.branch ? String(draft.branch) : ''} onChange={(event) => setDraft({ ...draft, branch: event.target.value ? Number(event.target.value) : null })} options={branches} placeholder="All branches" />
          </Field>
        )}
        <Field label="Recorded from" htmlFor="reversals-from">
          <DatePicker id="reversals-from" value={draft.from ?? ''} onChange={(value) => setDraft({ ...draft, from: value || null })} max={draft.to ?? undefined} />
        </Field>
        <Field label="To" htmlFor="reversals-to">
          <DatePicker id="reversals-to" value={draft.to ?? ''} onChange={(value) => setDraft({ ...draft, to: value || null })} min={draft.from ?? undefined} />
        </Field>
      </FilterBar>

      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Figure label="Refunded" value={totals.refunded} tone="slate" note="Money recorded as returned" />
        <Figure label="Adjusted" value={totals.adjusted} tone="slate" note="Balances conceded or written off" />
        <Figure label="Records" value={totals.count} money={false} tone="brand" />
      </div>

      <Card>
        {reversals.data.length === 0 ? (
          <EmptyState filtered={dirty} onClearFilters={() => { setDraft(empty); apply(empty); }} title={dirty ? 'Nothing matches these filters' : 'No refunds or adjustments yet'} description={dirty ? 'Try another search, type or date.' : 'Refunds and adjustments are made from a bill, and are listed here once recorded.'} />
        ) : (
          <ul className="divide-y divide-cx-line" aria-label="Refunds and adjustments">
            {reversals.data.map((row) => (
              <li key={row.key}>
                <button type="button" onClick={() => setOpen(row.bill_id)} className="grid w-full grid-cols-1 gap-x-4 gap-y-1 px-5 py-3.5 text-left hover:bg-cx-nav-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal sm:grid-cols-[auto_1.2fr_1.2fr_auto] sm:items-center">
                  <StatusBadge status={row.type === 'refund' ? 'info' : 'pending'} label={row.type === 'refund' ? 'Refund' : 'Adjustment'} className="justify-self-start" />
                  <span className="min-w-0">
                    <span className="block truncate text-[14px] font-semibold text-cx-nav-ink">{row.patient || 'Patient'}</span>
                    <span className="block truncate text-[12.5px] text-cx-nav-muted">{[row.number, `Bill ${row.bill_number}`, row.branch].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span className="min-w-0 text-[12.5px] text-cx-text-3">
                    <span className="block truncate">{row.reason ?? 'No reason given'}</span>
                    <span className="block truncate">
                      {[row.recorded_by ? `Recorded by ${row.recorded_by}` : null, when(row.recorded_at), row.method].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                  <span className="text-right text-[14px] font-semibold tabular-nums text-cx-text">{formatMoney(row.amount)}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>
      <Pager page={reversals} query={{ ...filters }} />
      <p className="mt-3 text-[12px] text-cx-text-3">
        {formatNumber(totals.count)} recorded{filters.from || filters.to ? ', by the day each was recorded, at the clinic’s time' : ''}. To refund or adjust, open the bill.
      </p>

      {open !== null && <BillDetailDialog billId={open} url={links.bill} onClose={() => setOpen(null)} />}
    </ClinicPortalShell>
  );
}
