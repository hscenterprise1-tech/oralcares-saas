import React from 'react';
import { Head, router } from '@inertiajs/react';
import {
  ArrowDownToLine,
  BarChart3,
  Briefcase,
  Building2,
  LifeBuoy,
  Receipt,
  TrendingUp,
  Users,
} from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmValue,
  CrmPageStat,
  CrmButton,
  CrmFormField,
  CrmInput,
} from '@/pages/Company/design';
import { OperationsShell } from './components/OperationsShell';

/**
 * Operations → Reports
 *
 * Company-wide, and it owns no figures. Every number is read from the module
 * that is authoritative for it, so a report can never disagree with the page it
 * came from.
 *
 * A metric this system cannot support is absent rather than approximated —
 * there is no margin, burn or runway here, because there is no cost-of-revenue
 * anywhere to derive them from. A figure with no data behind it reads "not
 * available", never zero.
 */

const HUE = {
  green: '#2FA96B',
  blue: '#2F6BE4',
  violet: '#7C6FE0',
  orange: '#F59245',
  coral: '#EF5B39',
  teal: '#3E93A3',
} as const;

interface Props {
  report: {
    window: { from: string; to: string; label: string; days: number };
    revenue: Record<string, number | null>;
    pipeline: Record<string, number>;
    customers: Record<string, number>;
    spend: Record<string, unknown>;
    people: Record<string, number>;
    support: Record<string, number | null>;
  };
  filters: { from: string; to: string };
  presets: Array<{ key: string; label: string }>;
}

const inr = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

const compact = (value: number) => {
  const abs = Math.abs(value);
  if (abs >= 10000000) return `₹${(value / 10000000).toFixed(2)}Cr`;
  if (abs >= 100000) return `₹${(value / 100000).toFixed(2)}L`;
  if (abs >= 1000) return `₹${(value / 1000).toFixed(1)}K`;

  return `₹${Math.round(value)}`;
};

/** Null is a real answer here, and it says so. */
const show = (value: number | null | undefined, format: (input: number) => string = String) =>
  value === null || value === undefined ? 'Not available' : format(value);

function Panel({
  icon,
  title,
  hue,
  rows,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  hue: string;
  rows: Array<{ label: string; value: string; muted?: boolean }>;
}) {
  return (
    <CrmCard className="p-6">
      <div className="mb-4 flex items-start gap-3">
        <h2 className="min-w-0 flex-1 text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
          {title}
        </h2>
        <CrmCardIcon icon={icon as never} hue={hue} />
      </div>

      <dl className="space-y-2.5">
        {rows.map((row) => (
          <div key={row.label} className="flex items-baseline justify-between gap-3">
            <dt className="text-[12.5px] text-ovs-crm-text-secondary">{row.label}</dt>
            <dd
              className={
                row.muted
                  ? 'text-[12.5px] tabular-nums text-ovs-crm-text-muted'
                  : 'text-[13px] font-medium tabular-nums text-ovs-crm-text-primary'
              }
            >
              {row.value}
            </dd>
          </div>
        ))}
      </dl>
    </CrmCard>
  );
}

export default function ReportsWorkspace({ report, filters, presets }: Props) {
  const { window: period, revenue, pipeline, customers, spend, people, support } = report;

  const byCategory = (spend.by_category ?? {}) as Record<string, number>;

  const exportUrl = `/company/reports/export?from=${filters.from}&to=${filters.to}`;

  return (
    <OperationsShell title="Reports">
      <Head title="Reports | Customer Operations" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Customer Operations
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Reports
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            {period.label} · {period.days} day{period.days === 1 ? '' : 's'} · every figure read from the module that
            owns it
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={TrendingUp} value={compact(revenue.collected ?? 0)} caption="Collected" />
          <CrmPageStat icon={Receipt} value={compact((spend.expenses_paid as number) ?? 0)} caption="Spent" />
          <CrmPageStat icon={Briefcase} value={pipeline.deals_won ?? 0} caption="Deals won" />
        </div>
      </div>

      <CrmCard className="mb-4 flex flex-wrap items-end gap-3 px-5 py-3.5">
        <div className="flex flex-wrap gap-2">
          {presets.map((preset) => (
            <CrmButton
              key={preset.key}
              size="sm"
              onClick={() => router.get('/company/reports', { preset: preset.key }, { preserveState: true })}
            >
              {preset.label}
            </CrmButton>
          ))}
        </div>

        <div className="min-w-[150px]">
          <CrmFormField label="From">
            <CrmInput
              type="date"
              value={filters.from}
              onChange={(e) => router.get('/company/reports', { ...filters, from: e.target.value }, { preserveState: true })}
            />
          </CrmFormField>
        </div>

        <div className="min-w-[150px]">
          <CrmFormField label="To">
            <CrmInput
              type="date"
              value={filters.to}
              onChange={(e) => router.get('/company/reports', { ...filters, to: e.target.value }, { preserveState: true })}
            />
          </CrmFormField>
        </div>

        <CrmButton icon={ArrowDownToLine} href={exportUrl}>
          Export CSV
        </CrmButton>
      </CrmCard>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <Panel
          icon={TrendingUp}
          title="Revenue"
          hue={HUE.green}
          rows={[
            { label: 'Collected in window', value: inr(revenue.collected ?? 0) },
            { label: 'Invoiced in window', value: inr(revenue.invoiced ?? 0) },
            { label: 'Payments', value: String(revenue.payment_count ?? 0), muted: true },
            { label: 'MRR now', value: show(revenue.mrr_now, inr) },
            { label: 'ARR now', value: show(revenue.arr_now, inr) },
            {
              label: 'Growth',
              value: revenue.growth_rate === null ? 'Not enough history' : `${revenue.growth_rate}%`,
            },
            {
              label: 'Subscriptions without MRR',
              value: String(revenue.mrr_unrecorded ?? 0),
              muted: true,
            },
          ]}
        />

        <Panel
          icon={Briefcase}
          title="Pipeline"
          hue={HUE.blue}
          rows={[
            { label: 'Deals created', value: String(pipeline.deals_created ?? 0) },
            { label: 'Won', value: String(pipeline.deals_won ?? 0) },
            { label: 'Lost', value: String(pipeline.deals_lost ?? 0) },
            { label: 'Still open', value: String(pipeline.open_deals ?? 0), muted: true },
          ]}
        />

        <Panel
          icon={Building2}
          title="Customers"
          hue={HUE.teal}
          rows={[
            { label: 'Accounts', value: String(customers.accounts ?? 0) },
            { label: 'Active subscriptions', value: String(customers.active_subscriptions ?? 0) },
            { label: 'New in window', value: String(customers.new_subscriptions ?? 0) },
            { label: 'Expiring within 60 days', value: String(customers.expiring_soon ?? 0), muted: true },
          ]}
        />

        <Panel
          icon={Receipt}
          title="Spend"
          hue={HUE.violet}
          rows={[
            { label: 'Paid in window', value: inr((spend.expenses_paid as number) ?? 0) },
            { label: 'Committed, all time', value: inr((spend.expenses_committed as number) ?? 0) },
            { label: 'Awaiting approval', value: inr((spend.awaiting_approval as number) ?? 0), muted: true },
            ...Object.entries(byCategory).map(([category, total]) => ({
              label: category,
              value: inr(total),
              muted: true,
            })),
          ]}
        />

        <Panel
          icon={Users}
          title="People"
          hue={HUE.orange}
          rows={[
            { label: 'Headcount', value: String(people.headcount ?? 0) },
            { label: 'Active', value: String(people.active ?? 0) },
            { label: 'Leave requests in window', value: String(people.leave_requests ?? 0) },
            { label: 'Days approved', value: String(people.leave_days_approved ?? 0), muted: true },
            { label: 'Awaiting a decision', value: String(people.leave_pending ?? 0), muted: true },
          ]}
        />

        <Panel
          icon={LifeBuoy}
          title="Support"
          hue={HUE.coral}
          rows={[
            { label: 'Opened in window', value: String(support.opened ?? 0) },
            { label: 'Resolved in window', value: String(support.resolved ?? 0) },
            { label: 'Open now', value: String(support.open_now ?? 0) },
            {
              label: 'Median time to resolve',
              value: show(support.median_resolution_hours, (hours) => `${hours}h`),
            },
            /* Null where no SLA target exists — not "none". */
            { label: 'Past due', value: show(support.sla_breaches), muted: true },
          ]}
        />
      </div>

      <CrmCard className="mt-4 flex items-start gap-3 px-5 py-4">
        <CrmCardIcon icon={BarChart3} hue={HUE.blue} />
        <p className="text-[12px] text-ovs-crm-text-secondary">
          {/*
            Said plainly rather than left for somebody to discover: this report
            has no margin, burn or runway because nothing in this system records
            a cost of revenue to derive them from.
          */}
          Margin, burn and runway are absent on purpose — there is no cost-of-revenue recorded anywhere in this system to
          derive them from, and an approximation would be worse than their absence.
        </p>
      </CrmCard>
    </OperationsShell>
  );
}
