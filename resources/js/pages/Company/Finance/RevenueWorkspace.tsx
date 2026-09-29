import React from 'react';
import { TrendingUp, DollarSign, Building2, Activity, AlertCircle } from 'lucide-react';
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { FinanceShell } from './components/FinanceShell';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmCard,
  CrmDataState,
} from '@/pages/Company/design';

/**
 * Finance → Revenue
 *
 * Two figures that are not the same thing, shown side by side: MRR is the
 * contracted run rate, billing is cash that actually arrived.
 *
 * Both come from recorded history. MRR is the value snapshotted on each
 * subscription when it was sold — never recomputed from today's plan price,
 * because raising a price must not rewrite what an earlier month earned. The
 * trend reads monthly snapshots; a month with no snapshot is a gap in the line,
 * not a zero.
 *
 * What this replaces: the page read a `mrr` column that did not exist, so
 * `sum('mrr')` returned 0 in silence and every figure here was zero. The chart
 * repeated today's MRR across six months and called it a trend, and a
 * hard-coded `growth_rate: 0.0` sat beside it as though it had been measured.
 */

interface TrendPoint {
  period: string;
  month: string;
  /** Null where no snapshot was taken that month. */
  mrr: number | null;
  billing: number;
}

interface RevenueWorkspaceProps {
  kpi: {
    mrr: number;
    arr: number;
    arpu: number;
    active_subscriptions: number;
    active_clinics: number;
    unrecorded_subscriptions: number;
    growth_rate: number | null;
    has_history: boolean;
    history: Array<{ period: string; label: string; mrr: number; active_subscriptions: number; complete: boolean }>;
  };
  monthly_trend: TrendPoint[];
}

const money = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

export default function RevenueWorkspace({ kpi, monthly_trend }: RevenueWorkspaceProps) {
  const snapshotMonths = monthly_trend.filter((point) => point.mrr !== null).length;

  return (
    <FinanceShell title="Revenue">
      <CrmPageHead
        eyebrow="Finance"
        title="Revenue"
        stats={
          <>
            <CrmPageStat
              icon={TrendingUp}
              value={kpi.mrr > 0 ? money(kpi.mrr) : '—'}
              chip={kpi.growth_rate !== null ? `${kpi.growth_rate > 0 ? '+' : ''}${kpi.growth_rate}%` : undefined}
              caption="Monthly recurring"
            />
            <CrmPageStat icon={DollarSign} value={kpi.arr > 0 ? money(kpi.arr) : '—'} caption="Annual recurring" />
            <CrmPageStat icon={Building2} value={kpi.active_clinics} caption="Paying accounts" />
            <CrmPageStat icon={Activity} value={kpi.arpu > 0 ? money(kpi.arpu) : '—'} caption="Average per account" />
          </>
        }
      />

      {kpi.unrecorded_subscriptions > 0 && (
        <CrmCard className="mb-4 flex items-start gap-3 px-5 py-4">
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden />
          <div>
            <p className="text-[13px] font-medium text-ovs-crm-text-primary">
              {kpi.unrecorded_subscriptions} of {kpi.active_subscriptions} active subscriptions have no recurring value
              on record
            </p>
            {/*
              Excluded rather than counted as zero. These predate the column;
              deriving them from today's price list would be inventing what an
              earlier deal was worth.
            */}
            <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
              They are excluded from the figures above, so MRR and ARR are a floor rather than the full picture.
            </p>
          </div>
        </CrmCard>
      )}

      <CrmSection
        title="Six months"
        description="Recurring run rate against cash collected. They are different measures and are not expected to match."
      >
        {snapshotMonths < 2 ? (
          <CrmDataState
            kind="empty"
            icon={TrendingUp}
            title="Not enough history yet"
            description={
              snapshotMonths === 0
                ? 'No monthly MRR has been recorded. The first snapshot is taken at the start of next month, and a trend needs two.'
                : 'One month has been recorded. A trend needs at least two, so the next snapshot completes it.'
            }
          />
        ) : (
          <CrmCard className="px-5 py-5">
            <div className="h-[280px] w-full">
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={monthly_trend} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                  <defs>
                    <linearGradient id="colorMrr" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#8B93C4" stopOpacity={0.35} />
                      <stop offset="95%" stopColor="#8B93C4" stopOpacity={0} />
                    </linearGradient>
                    <linearGradient id="colorBilling" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor="#10B981" stopOpacity={0.25} />
                      <stop offset="95%" stopColor="#10B981" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#E2E8F0" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
                  <YAxis tick={{ fontSize: 11 }} tickLine={false} axisLine={false} width={70} />
                  <Tooltip
                    formatter={(value, name) => [
                      value === null || value === undefined ? 'Not recorded' : money(Number(value)),
                      name === 'mrr' ? 'MRR' : 'Collected',
                    ]}
                  />
                  {/*
                    `connectNulls` stays off on purpose. A month with no
                    snapshot breaks the line, which is the honest rendering of a
                    gap — joining across it would draw a trend through data
                    nobody recorded.
                  */}
                  <Area
                    type="monotone"
                    dataKey="mrr"
                    stroke="#8B93C4"
                    strokeWidth={2.5}
                    fillOpacity={1}
                    fill="url(#colorMrr)"
                    connectNulls={false}
                  />
                  <Area
                    type="monotone"
                    dataKey="billing"
                    stroke="#10B981"
                    strokeWidth={2}
                    strokeDasharray="4 4"
                    fillOpacity={1}
                    fill="url(#colorBilling)"
                  />
                </AreaChart>
              </ResponsiveContainer>
            </div>

            <div className="mt-4 flex flex-wrap gap-x-6 gap-y-2 border-t border-ovs-crm-line pt-3">
              <span className="inline-flex items-center gap-2 text-[12px] text-ovs-crm-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: '#8B93C4' }} aria-hidden />
                Recurring run rate
              </span>
              <span className="inline-flex items-center gap-2 text-[12px] text-ovs-crm-text-secondary">
                <span className="h-2 w-2 rounded-full" style={{ background: '#10B981' }} aria-hidden />
                Cash collected
              </span>
            </div>
          </CrmCard>
        )}
      </CrmSection>

      <CrmSection title="Recorded months" description="One row per monthly snapshot. Nothing here is recalculated.">
        {kpi.history.length === 0 ? (
          <CrmCard className="px-5 py-6">
            <p className="text-[13px] text-ovs-crm-text-muted">
              No snapshots yet. One is recorded at the start of each month.
            </p>
          </CrmCard>
        ) : (
          <div className="space-y-2">
            {[...kpi.history].reverse().map((row) => (
              <CrmCard key={row.period} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3">
                <span className="min-w-[110px] text-[13px] font-medium text-ovs-crm-text-primary">{row.label}</span>
                <span className="min-w-[160px] flex-1 text-[12px] text-ovs-crm-text-muted">
                  {row.active_subscriptions} active {row.active_subscriptions === 1 ? 'subscription' : 'subscriptions'}
                  {!row.complete && <span className="ml-2">· incomplete month</span>}
                </span>
                <span className="text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                  {money(row.mrr)}
                </span>
              </CrmCard>
            ))}
          </div>
        )}
      </CrmSection>
    </FinanceShell>
  );
}
