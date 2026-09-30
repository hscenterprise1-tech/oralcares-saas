import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Archive, CalendarRange, Coins, Plus, Wallet } from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmStatusPill,
  CrmDataState,
  CrmPagination,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * Payroll runs.
 *
 * What this replaces: a single table where somebody typed a gross figure and a
 * deductions figure, and the row was written `paid` in the same request. A run
 * here is a period that has to be calculated, submitted, approved and locked
 * before it can be paid, and its figures stop being editable once it is.
 */

interface PeriodRow {
  id: number;
  code: string;
  label: string;
  period_start: string | null;
  period_end: string | null;
  status: string;
  status_label: string;
  is_mutable: boolean;
  total_gross: number;
  total_deductions: number;
  total_net: number;
  entry_count: number;
}

interface Paginated<T> {
  data: T[];
  links?: Array<{ url: string | null; label: string; active: boolean }>;
  from?: number | null;
  to?: number | null;
  total?: number | null;
}

interface Props {
  periods: Paginated<PeriodRow>;
  can: { manage: boolean; approve: boolean };
  legacy: { count: number; total_net: number };
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  draft: 'neutral',
  calculated: 'info',
  pending_approval: 'warn',
  approved: 'info',
  locked: 'good',
  paid: 'good',
};

const money = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

const window_ = (row: PeriodRow) => {
  if (!row.period_start || !row.period_end) {
    return '—';
  }

  const fmt = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });

  return `${fmt(row.period_start)} – ${fmt(row.period_end)}`;
};

export default function PayrollWorkspace({ periods, can, legacy }: Props) {
  const [form, setForm] = useState({ open: false, label: '', start: '', end: '' });

  const rows = periods.data ?? [];
  const open = rows.find((row) => row.status !== 'paid');

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/company/people/payroll',
      { label: form.label, period_start: form.start, period_end: form.end },
      { preserveScroll: true, onSuccess: () => setForm({ open: false, label: '', start: '', end: '' }) },
    );
  };

  return (
    <PeopleShell title="Payroll">
      <CrmPageHead
        eyebrow="People"
        title="Payroll"
        stats={
          <>
            <CrmPageStat icon={CalendarRange} value={periods.total ?? rows.length} caption="Runs" />
            <CrmPageStat icon={Wallet} value={open ? open.status_label : 'None open'} caption="Current run" />
            <CrmPageStat icon={Coins} value={open ? money(open.total_net) : '—'} caption="Net this run" />
          </>
        }
        actions={
          can.manage ? (
            <CrmButton tone="primary" icon={Plus} onClick={() => setForm({ ...form, open: true })}>
              New run
            </CrmButton>
          ) : undefined
        }
      />

      {rows.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Wallet}
          title="No payroll runs yet"
          description="A run covers one pay window. Create it, calculate it from each person's compensation, then send it for approval."
        />
      ) : (
        <div className="space-y-2">
          {rows.map((row) => (
            <Link key={row.id} href={`/company/people/payroll/${row.id}`} className="block">
              <CrmCard className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:border-ovs-crm-accent/40">
                <span className="min-w-[200px] flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{row.label}</span>
                    <CrmStatusPill tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status_label}</CrmStatusPill>
                  </span>
                  <span className="mt-0.5 block text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {row.code} · {window_(row)}
                  </span>
                </span>

                <span className="text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                  {row.entry_count} {row.entry_count === 1 ? 'person' : 'people'}
                </span>

                <span className="min-w-[110px] text-right">
                  <span className="block text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                    {money(row.total_net)}
                  </span>
                  <span className="block text-[11px] tabular-nums text-ovs-crm-text-muted">net</span>
                </span>
              </CrmCard>
            </Link>
          ))}

          <CrmPagination links={periods.links} from={periods.from} to={periods.to} total={periods.total} className="pt-1" />
        </div>
      )}

      {legacy.count > 0 && (
        <CrmCard className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
          <Archive className="h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden />
          <span className="min-w-[200px] flex-1">
            <span className="block text-[13px] font-medium text-ovs-crm-text-primary">
              {legacy.count} records from before this system
            </span>
            {/*
              Read-only on purpose. These rows carry no period, no components
              and no approval, because none of that existed when they were
              written. Recalculating them would rewrite history.
            */}
            <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
              Kept exactly as written, with no period or approval trail. They are not recalculated.
            </span>
          </span>
          <span className="text-[13px] font-semibold tabular-nums text-ovs-crm-text-secondary">
            {money(legacy.total_net)}
          </span>
        </CrmCard>
      )}

      <CrmModal
        open={form.open}
        onClose={() => setForm({ ...form, open: false })}
        title="New payroll run"
        description="One run per pay window. Figures come from each person's compensation when you calculate it."
        footer={
          <>
            <CrmButton onClick={() => setForm({ ...form, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="payroll-run-form">
              Create run
            </CrmButton>
          </>
        }
      >
        <form id="payroll-run-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <CrmFormField label="Label" required hint="How people will refer to it, e.g. September 2026.">
            <CrmInput required value={form.label} onChange={(e) => setForm({ ...form, label: e.target.value })} />
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Period start" required>
              <CrmInput type="date" required value={form.start} onChange={(e) => setForm({ ...form, start: e.target.value })} />
            </CrmFormField>

            <CrmFormField label="Period end" required>
              <CrmInput type="date" required value={form.end} onChange={(e) => setForm({ ...form, end: e.target.value })} />
            </CrmFormField>
          </div>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
