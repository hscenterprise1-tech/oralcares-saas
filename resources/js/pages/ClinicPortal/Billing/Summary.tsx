import { useState } from 'react';
import { Wallet } from 'lucide-react';
import { Link, router } from '@inertiajs/react';
import { BillingTabs, clean, day, FilterBar, Figure, type BillingTab } from '@/clinic/billing/BillingFrame';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Card, CardHeader } from '@/clinic/ui/Card';
import { Field, Select } from '@/clinic/ui/controls';
import { DatePicker } from '@/clinic/ui/DatePicker';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { formatMoney, formatNumber, humanize } from '@/clinic/ui/visuals';

interface Props {
  summary: {
    figures: { bills: number; billed: number; discounts: number; collected: number; refunded: number; net_collected: number; adjusted: number; outstanding: number };
    by_method: { method: string; count: number; total: number }[];
    by_branch: { id: number; name: string; billed: number; collected: number; refunded: number; outstanding: number }[];
    expenses: { spent: number; paid: number; to_pay: number } | null;
    legacy_invoices: number;
  };
  filters: { branch: number | null; from: string; to: string };
  branches: { value: string; label: string }[];
  tabs: BillingTab[];
  links: { bills: string; reversals: string };
}

/**
 * Financial Summaries for a period: billed, collected, refunded, adjusted,
 * outstanding now, and spending, from the clinic's own billing records. Each
 * figure says what it counts and by which date. It is a summary of patient
 * billing and expenses, not accounting: there is no profit, cash flow,
 * ledger or tax return here.
 */
export default function BillingSummary({ summary, filters, branches, tabs, links }: Props) {
  const [draft, setDraft] = useState(filters);
  const { figures } = summary;
  const range = `${day(filters.from)} – ${day(filters.to)}`;
  const apply = (next = draft) => router.get(window.location.pathname, clean(next), { preserveScroll: true, preserveState: true });
  const listQuery = clean({ from: filters.from, to: filters.to, branch: filters.branch });
  const withQuery = (href: string, extra: Record<string, string> = {}) => `${href}?${new URLSearchParams({ ...Object.fromEntries(Object.entries(listQuery).map(([k, v]) => [k, String(v)])), ...extra }).toString()}`;

  return (
    <ClinicPortalShell title="Financial Summaries">
      <PageHeader icon={Wallet} title="Billing" description={`What the clinic billed, collected and spent, ${range}.`} />
      <BillingTabs tabs={tabs} current="summary" />

      <FilterBar onApply={() => apply()} onClear={() => apply({ branch: null, from: '', to: '' } as never)} dirty={Boolean(filters.branch)}>
        <Field label="From" htmlFor="summary-from">
          <DatePicker id="summary-from" value={draft.from} onChange={(value) => setDraft({ ...draft, from: value })} max={draft.to} />
        </Field>
        <Field label="To" htmlFor="summary-to">
          <DatePicker id="summary-to" value={draft.to} onChange={(value) => setDraft({ ...draft, to: value })} min={draft.from} />
        </Field>
        {branches.length > 1 && (
          <Field label="Branch" htmlFor="summary-branch">
            <Select id="summary-branch" value={draft.branch ? String(draft.branch) : ''} onChange={(event) => setDraft({ ...draft, branch: event.target.value ? Number(event.target.value) : null })} options={branches} placeholder="All branches" />
          </Field>
        )}
      </FilterBar>

      <section aria-labelledby="summary-billing" className="mb-6">
        <h2 id="summary-billing" className="mb-3 text-[15px] font-semibold text-cx-nav-ink">Patient billing</h2>
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Figure period="This period" label="Billed" value={figures.billed} tone="brand" note={`${formatNumber(figures.bills)} bills issued in the period, not cancelled`} />
          <Figure period="This period" label="Collected" value={figures.collected} tone="green" note="Payments received in the period" />
          <Figure period="This period" label="Refunded" value={figures.refunded} tone="slate" note="Refunds recorded in the period" />
          <Figure period="This period" label="Net collected" value={figures.net_collected} tone="green" note="Collected less refunded" />
          <Figure period="This period" label="Discounts" value={figures.discounts} tone="slate" note="On bills issued in the period" />
          <Figure period="This period" label="Adjusted" value={figures.adjusted} tone="slate" note="Concessions and write-offs recorded in the period" />
          <Figure period="Now" label="Outstanding now" value={figures.outstanding} tone="amber" note="Still due on open bills today, whenever issued" />
        </div>
        <p className="mt-2 text-[12px] text-cx-text-3">
          Open the rows behind these: <Link className="font-medium text-cx-nav-ink underline-offset-2 hover:underline" href={withQuery(links.bills)}>bills issued</Link>,{' '}
          <Link className="font-medium text-cx-nav-ink underline-offset-2 hover:underline" href={withQuery(links.bills, { view: 'collections' })}>payments received</Link>,{' '}
          <Link className="font-medium text-cx-nav-ink underline-offset-2 hover:underline" href={withQuery(links.reversals)}>refunds and adjustments</Link>.
        </p>
      </section>

      <div className="mb-6 grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader title="Collections by method" description="Payments received in the period, before refunds" />
          {summary.by_method.length === 0 ? (
            <p className="px-5 pb-5 pt-3 text-[13px] text-cx-text-3">No payments in this period.</p>
          ) : (
            <ul className="divide-y divide-cx-line px-5 pb-3 pt-2">
              {summary.by_method.map((row) => (
                <li key={row.method} className="flex items-center justify-between py-2 text-[13.5px]">
                  <span>
                    {humanize(row.method)} <span className="text-cx-text-3">· {formatNumber(row.count)}</span>
                  </span>
                  <span className="font-semibold tabular-nums">{formatMoney(row.total)}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {summary.expenses ? (
          <Card>
            <CardHeader title="Spending" description="Expenses dated in the period; what is still to pay is as of now" />
            <ul className="divide-y divide-cx-line px-5 pb-3 pt-2 text-[13.5px]">
              <li className="flex justify-between py-2"><span>Approved or paid</span><span className="font-semibold tabular-nums">{formatMoney(summary.expenses.spent)}</span></li>
              <li className="flex justify-between py-2"><span>Of which paid</span><span className="font-semibold tabular-nums">{formatMoney(summary.expenses.paid)}</span></li>
              <li className="flex justify-between py-2"><span>Approved, still to pay (now)</span><span className="font-semibold tabular-nums">{formatMoney(summary.expenses.to_pay)}</span></li>
            </ul>
          </Card>
        ) : (
          <Card>
            <CardHeader title="Spending" />
            <p className="px-5 pb-5 pt-3 text-[13px] text-cx-text-3">Expenses are shown to those who manage them.</p>
          </Card>
        )}
      </div>

      {summary.by_branch.length > 1 && (
        <Card className="mb-6 overflow-x-auto">
          <CardHeader title="By branch" description="The same figures, each record at its own branch" />
          <table className="mt-2 w-full min-w-[560px] text-[13px]">
            <thead>
              <tr className="border-b border-cx-line text-left text-[12px] text-cx-text-3">
                <th scope="col" className="px-5 py-2 font-semibold">Branch</th>
                <th scope="col" className="px-3 py-2 text-right font-semibold">Billed</th>
                <th scope="col" className="px-3 py-2 text-right font-semibold">Collected</th>
                <th scope="col" className="px-3 py-2 text-right font-semibold">Refunded</th>
                <th scope="col" className="px-5 py-2 text-right font-semibold">Outstanding now</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-cx-line">
              {summary.by_branch.map((row) => (
                <tr key={row.id}>
                  <th scope="row" className="px-5 py-2.5 text-left font-medium text-cx-nav-ink">{row.name}</th>
                  <td className="px-3 py-2.5 text-right tabular-nums">{formatMoney(row.billed)}</td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{formatMoney(row.collected)}</td>
                  <td className="px-3 py-2.5 text-right tabular-nums">{formatMoney(row.refunded)}</td>
                  <td className="px-5 py-2.5 text-right tabular-nums">{formatMoney(row.outstanding)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      <Notice tone="info" title="What this summary is not">
        It totals the clinic’s patient bills and expenses. Profit, cash flow, bank balances, ledgers and GST returns are not calculated here.
        {summary.legacy_invoices > 0 ? ` ${formatNumber(summary.legacy_invoices)} invoices from the earlier billing system are not included, so nothing is counted twice.` : ''}
      </Notice>
    </ClinicPortalShell>
  );
}
