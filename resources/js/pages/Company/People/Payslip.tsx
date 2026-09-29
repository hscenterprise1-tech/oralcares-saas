import React from 'react';
import { ArrowLeft, Receipt } from 'lucide-react';
import {
  CrmPageHead,
  CrmCard,
  CrmButton,
  CrmStatusPill,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * A payslip.
 *
 * Nothing here is computed in the browser and nothing is looked up live: every
 * line was written into the run when it was calculated, component names and
 * all. Renaming or retiring a component later leaves this page reading exactly
 * as it does today.
 */

interface Line {
  code: string;
  name: string;
  amount: number;
}

interface Props {
  period: {
    id: number;
    code: string;
    label: string;
    period_start: string | null;
    period_end: string | null;
    status: string;
    status_label: string;
  };
  entry: {
    id: number;
    employee_code: string;
    employee_name: string;
    base_salary: number;
    gross_amount: number;
    deduction_amount: number;
    net_amount: number;
  };
  earnings: Line[];
  deductions: Line[];
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
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2 }).format(value ?? 0);

const day = (iso: string | null) =>
  iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

function LineList({ title, lines, total, totalLabel }: { title: string; lines: Line[]; total: number; totalLabel: string }) {
  return (
    <CrmCard className="px-5 py-4">
      <h3 className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">{title}</h3>

      <ul className="mt-3 space-y-2">
        {lines.length === 0 ? (
          <li className="text-[12.5px] text-ovs-crm-text-muted">None.</li>
        ) : (
          lines.map((line) => (
            <li key={line.code} className="flex items-baseline justify-between gap-4">
              <span className="text-[12.5px] text-ovs-crm-text-secondary">{line.name}</span>
              <span className="text-[12.5px] tabular-nums text-ovs-crm-text-primary">{money(line.amount)}</span>
            </li>
          ))
        )}
      </ul>

      <div className="mt-3 flex items-baseline justify-between gap-4 border-t border-ovs-crm-line pt-3">
        <span className="text-[12px] font-medium text-ovs-crm-text-secondary">{totalLabel}</span>
        <span className="text-[13.5px] font-semibold tabular-nums text-ovs-crm-text-primary">{money(total)}</span>
      </div>
    </CrmCard>
  );
}

export default function Payslip({ period, entry, earnings, deductions }: Props) {
  return (
    <PeopleShell title={`Payslip · ${entry.employee_name}`}>
      <CrmPageHead
        eyebrow={`Payslip · ${period.code}`}
        title={entry.employee_name}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <CrmButton size="sm" icon={ArrowLeft} href={`/company/people/payroll/${period.id}`}>
              Back to run
            </CrmButton>
            <CrmStatusPill tone={STATUS_TONE[period.status] ?? 'neutral'}>{period.status_label}</CrmStatusPill>
          </div>
        }
      />

      <CrmCard className="flex flex-wrap items-center gap-x-8 gap-y-3 px-5 py-4">
        <div>
          <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Employee</p>
          <p className="mt-0.5 text-[13px] tabular-nums text-ovs-crm-text-primary">{entry.employee_code}</p>
        </div>
        <div>
          <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Period</p>
          <p className="mt-0.5 text-[13px] text-ovs-crm-text-primary">{period.label}</p>
        </div>
        <div>
          <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">Covering</p>
          <p className="mt-0.5 text-[13px] text-ovs-crm-text-primary">
            {day(period.period_start)} – {day(period.period_end)}
          </p>
        </div>
      </CrmCard>

      <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <LineList title="Earnings" lines={earnings} total={entry.gross_amount} totalLabel="Gross" />
        <LineList title="Deductions" lines={deductions} total={entry.deduction_amount} totalLabel="Total deductions" />
      </div>

      <CrmCard className="mt-4 flex flex-wrap items-center justify-between gap-4 px-5 py-4">
        <span className="inline-flex items-center gap-2 text-[12.5px] text-ovs-crm-text-secondary">
          <Receipt className="h-4 w-4 text-ovs-crm-text-muted" aria-hidden />
          Net pay
        </span>
        <span className="text-[22px] font-bold tabular-nums tracking-[-0.02em] text-ovs-crm-text-primary">
          {money(entry.net_amount)}
        </span>
      </CrmCard>
    </PeopleShell>
  );
}
