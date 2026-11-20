import { useState } from 'react';
import { Wallet } from 'lucide-react';
import { Link, router } from '@inertiajs/react';
import { BillDetailDialog } from '@/clinic/billing/BillDetailDialog';
import { BillingTabs, clean, day, FilterBar, Figure, Pager, when, type BillingTab, type PagedList } from '@/clinic/billing/BillingFrame';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { StatusBadge } from '@/clinic/ui/badges';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { DatePicker } from '@/clinic/ui/DatePicker';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { PillTabs } from '@/clinic/ui/Tabs';
import { EmptyState } from '@/clinic/ui/states';
import { formatMoney, formatNumber } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

interface BillRow {
  id: number;
  number: string;
  patient: string;
  uhid: string | null;
  branch: string | null;
  status: string;
  total: number;
  paid: number;
  refunded: number;
  adjusted: number;
  outstanding: number;
  issued_at: string | null;
}

interface CollectionRow {
  id: number;
  bill_id: number;
  bill_number: string;
  receipt: string;
  patient: string;
  uhid: string | null;
  branch: string | null;
  method: string | null;
  reference: string | null;
  amount: number;
  paid_at: string | null;
}

type Option = { value: string; label: string };

interface Props {
  view: 'bills' | 'collections';
  bills: PagedList<BillRow> | null;
  collections: PagedList<CollectionRow> | null;
  totals: { count: number; billed?: number; collected: number; refunded?: number; adjusted?: number; outstanding?: number };
  ready: { id: number; number: string | null; patient: string | null; uhid: string | null; doctor: string | null; url: string }[];
  filters: { q: string; status: string | null; branch: number | null; from: string | null; to: string | null; method: string | null };
  statuses: Option[];
  methods: Option[];
  branches: Option[];
  tabs: BillingTab[];
  links: { bill: string };
}

/**
 * Bills & Collections: every bill, or every payment received, filtered and
 * paged on the server, with totals that are the sums of the rows the filters
 * select. A bill opens in full with only the actions the server offers.
 */
export default function BillingIndex({ view, bills, collections, totals, ready, filters, statuses, methods, branches, tabs, links }: Props) {
  const [draft, setDraft] = useState(filters);
  const [open, setOpen] = useState<number | null>(null);
  const query = { view: view === 'collections' ? 'collections' : null, q: filters.q, status: filters.status, branch: filters.branch, from: filters.from, to: filters.to, method: filters.method };
  const apply = (next = draft) => router.get(window.location.pathname, clean({ view: query.view, ...next }), { preserveScroll: true, preserveState: true });
  const dirty = Boolean(filters.q || filters.status || filters.branch || filters.from || filters.to || filters.method);
  const switchView = (to: 'bills' | 'collections') => router.get(window.location.pathname, clean({ ...filters, view: to === 'collections' ? 'collections' : null }), { preserveScroll: true });

  return (
    <ClinicPortalShell title="Billing">
      <PageHeader icon={Wallet} title="Billing" description="Bills raised at the clinic, what has been collected, and what is still due." />
      <BillingTabs tabs={tabs} current="bills" />

      <PillTabs label="Show" className="mb-4" value={view} onChange={switchView} options={[{ key: 'bills', label: 'Bills' }, { key: 'collections', label: 'Collections' }]} />

      <FilterBar onApply={() => apply()} onClear={() => { const empty = { q: '', status: null, branch: null, from: null, to: null, method: null }; setDraft(empty); apply(empty); }} dirty={dirty}>
        <Field label="Search" htmlFor="billing-q" className="min-w-[220px] flex-1">
          <Input id="billing-q" type="search" placeholder={view === 'bills' ? 'Bill number, patient, UHID or mobile' : 'Receipt, bill, patient, UHID or mobile'} value={draft.q} onChange={(event) => setDraft({ ...draft, q: event.target.value })} />
        </Field>
        {view === 'bills' ? (
          <Field label="Status" htmlFor="billing-status">
            <Select id="billing-status" value={draft.status ?? ''} onChange={(event) => setDraft({ ...draft, status: event.target.value || null })} options={statuses} placeholder="Any status" />
          </Field>
        ) : (
          <Field label="Method" htmlFor="billing-method">
            <Select id="billing-method" value={draft.method ?? ''} onChange={(event) => setDraft({ ...draft, method: event.target.value || null })} options={methods} placeholder="Any method" />
          </Field>
        )}
        {branches.length > 1 && (
          <Field label="Branch" htmlFor="billing-branch">
            <Select id="billing-branch" value={draft.branch ? String(draft.branch) : ''} onChange={(event) => setDraft({ ...draft, branch: event.target.value ? Number(event.target.value) : null })} options={branches} placeholder="All branches" />
          </Field>
        )}
        <Field label={view === 'bills' ? 'Issued from' : 'Paid from'} htmlFor="billing-from">
          <DatePicker id="billing-from" value={draft.from ?? ''} onChange={(value) => setDraft({ ...draft, from: value || null })} max={draft.to ?? undefined} />
        </Field>
        <Field label="To" htmlFor="billing-to">
          <DatePicker id="billing-to" value={draft.to ?? ''} onChange={(value) => setDraft({ ...draft, to: value || null })} min={draft.from ?? undefined} />
        </Field>
      </FilterBar>

      {view === 'bills' ? (
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
          <Figure label="Billed" value={totals.billed ?? 0} tone="brand" note={`${formatNumber(totals.count)} bills (cancelled bills carry no amount)`} />
          <Figure label="Collected" value={totals.collected} tone="green" />
          <Figure label="Refunded" value={totals.refunded ?? 0} tone="slate" />
          <Figure label="Adjusted" value={totals.adjusted ?? 0} tone="slate" />
          <Figure label="Outstanding" value={totals.outstanding ?? 0} tone="amber" />
        </div>
      ) : (
        <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
          <Figure label="Collected" value={totals.collected} tone="green" note={`${formatNumber(totals.count)} payments, before refunds`} />
        </div>
      )}

      {view === 'bills' && ready.length > 0 && (
        <Card className="mb-4 p-4">
          <h2 className="text-[14px] font-semibold text-cx-text">
            {formatNumber(ready.length)} signed {ready.length === 1 ? 'consultation is' : 'consultations are'} ready to bill
          </h2>
          <ul className="mt-2 divide-y divide-cx-line" aria-label="Consultations ready to bill">
            {ready.map((row) => (
              <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <span className="min-w-0 text-[13px]">
                  <span className="font-semibold text-cx-text">{row.patient ?? 'Patient'}</span>
                  <span className="text-cx-text-2">{[row.uhid, row.doctor, row.number].filter(Boolean).map((part) => ` · ${part}`).join('')}</span>
                </span>
                <Link href={row.url} className="inline-flex h-9 items-center rounded-full bg-[#1769AA] px-4 text-[12.5px] font-semibold text-white hover:bg-[#135A93] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/40">
                  Checkout
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Card>
        {view === 'bills' && bills ? (
          bills.data.length === 0 ? (
            <EmptyState filtered={dirty} onClearFilters={() => apply({ q: '', status: null, branch: null, from: null, to: null, method: null })} title={dirty ? 'No bills match these filters' : 'No bills yet'} description={dirty ? 'Try another search, status or date.' : 'Bills raised at the desk or from a consultation appear here.'} />
          ) : (
            <ul className="divide-y divide-cx-line" aria-label="Bills">
              {bills.data.map((bill) => (
                <li key={bill.id}>
                  <button type="button" onClick={() => setOpen(bill.id)} className="grid w-full grid-cols-1 gap-x-4 gap-y-1 px-5 py-3.5 text-left hover:bg-cx-nav-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal sm:grid-cols-[1.2fr_1fr_auto_auto] sm:items-center">
                    <span className="min-w-0">
                      <span className="block truncate text-[14px] font-semibold text-cx-nav-ink">{bill.patient || 'Patient'}</span>
                      <span className="block truncate text-[12.5px] text-cx-nav-muted">
                        {[bill.number, bill.uhid, bill.branch].filter(Boolean).join(' · ')}
                      </span>
                    </span>
                    <span className="text-[12.5px] text-cx-text-3">{when(bill.issued_at)}</span>
                    <span className="text-right">
                      <span className="block text-[14px] font-semibold tabular-nums text-cx-text">{formatMoney(bill.total)}</span>
                      {bill.outstanding > 0 && <span className="block text-[12px] font-medium tabular-nums text-amber-700">{formatMoney(bill.outstanding)} due</span>}
                    </span>
                    <StatusBadge status={bill.status} className="justify-self-start sm:justify-self-end" />
                  </button>
                </li>
              ))}
            </ul>
          )
        ) : collections && collections.data.length === 0 ? (
          <EmptyState filtered={dirty} onClearFilters={() => apply({ q: '', status: null, branch: null, from: null, to: null, method: null })} title={dirty ? 'No payments match these filters' : 'No payments yet'} description={dirty ? 'Try another search, method or date.' : 'Payments recorded against bills appear here with their receipts.'} />
        ) : (
          <ul className="divide-y divide-cx-line" aria-label="Payments">
            {collections?.data.map((payment) => (
              <li key={payment.id}>
                <button type="button" onClick={() => setOpen(payment.bill_id)} className="grid w-full grid-cols-1 gap-x-4 gap-y-1 px-5 py-3.5 text-left hover:bg-cx-nav-bg/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cx-nav-teal sm:grid-cols-[1.2fr_1fr_auto] sm:items-center">
                  <span className="min-w-0">
                    <span className="block truncate text-[14px] font-semibold text-cx-nav-ink">{payment.patient || 'Patient'}</span>
                    <span className="block truncate text-[12.5px] text-cx-nav-muted">{[payment.receipt, `Bill ${payment.bill_number}`, payment.branch].filter(Boolean).join(' · ')}</span>
                  </span>
                  <span className="text-[12.5px] text-cx-text-3">
                    {when(payment.paid_at)}
                    {payment.method ? ` · ${payment.method}` : ''}
                  </span>
                  <span className="text-right text-[14px] font-semibold tabular-nums text-cx-text">{formatMoney(payment.amount)}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {view === 'bills' && bills && <Pager page={bills} query={query} />}
      {view === 'collections' && collections && <Pager page={collections} query={query} />}
      {filters.from || filters.to ? (
        <p className="mt-3 text-[12px] text-cx-text-3">
          {view === 'bills' ? 'Bills by the day they were issued' : 'Payments by the day they were received'}
          {filters.from ? ` from ${day(filters.from)}` : ''}
          {filters.to ? ` to ${day(filters.to)}` : ''}, at the clinic’s time.
        </p>
      ) : null}
      <p className="mt-3 text-[12px] text-cx-text-3">
        Looking for refunds or adjustments? See <Link className="font-medium text-cx-nav-ink underline-offset-2 hover:underline" href={tabs.find((tab) => tab.key === 'reversals')?.href ?? window.location.pathname}>Refunds &amp; Adjustments</Link>.
      </p>

      {open !== null && <BillDetailDialog billId={open} url={links.bill} onClose={() => setOpen(null)} />}
    </ClinicPortalShell>
  );
}
