import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Calculator, FileText, Lock, Users } from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmSection,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmTextarea,
  CrmStatusPill,
  CrmDataState,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * One payroll run.
 *
 * Everything on this page is a snapshot. Calculating copies each person's
 * compensation into their entry, so a later raise cannot change what this run
 * reports. Once the run is locked or paid, none of it can be recalculated.
 */

interface Stamp {
  at: string;
  by: string | null;
}

interface Entry {
  id: number;
  employee_code: string;
  employee_name: string;
  base_salary: number;
  gross_amount: number;
  deduction_amount: number;
  net_amount: number;
}

interface Period {
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
  payment_reference: string | null;
  payment_notes: string | null;
  trail: {
    created: Stamp | null;
    calculated: Stamp | null;
    submitted: Stamp | null;
    approved: Stamp | null;
    locked: Stamp | null;
    paid: Stamp | null;
  };
}

interface Props {
  period: Period;
  entries: Entry[];
  allowedNext: Array<{ value: string; label: string }>;
  can: { manage: boolean; approve: boolean };
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

const TRAIL_STEPS: Array<{ key: keyof Period['trail']; label: string }> = [
  { key: 'created', label: 'Created' },
  { key: 'calculated', label: 'Calculated' },
  { key: 'submitted', label: 'Sent for approval' },
  { key: 'approved', label: 'Approved' },
  { key: 'locked', label: 'Locked' },
  { key: 'paid', label: 'Paid' },
];

export default function PayrollPeriodDetail({ period, entries, allowedNext, can }: Props) {
  const [payment, setPayment] = useState({ open: false, reference: '', notes: '' });

  const move = (status: string, body: Record<string, unknown> = {}) =>
    router.post(`/company/people/payroll/${period.id}/transition`, { status, ...body }, { preserveScroll: true });

  const submitPayment = (e: React.FormEvent) => {
    e.preventDefault();
    move('paid', { payment_reference: payment.reference || null, payment_notes: payment.notes || null });
    setPayment({ open: false, reference: '', notes: '' });
  };

  /*
   * Approving is a separate authority from running payroll, so the button is
   * gated separately too — the same split the server enforces.
   */
  const canMove = (status: string) => (status === 'approved' ? can.approve : can.manage);

  return (
    <PeopleShell title={period.label}>
      <CrmPageHead
        eyebrow={`Payroll · ${period.code}`}
        title={period.label}
        stats={
          <>
            <CrmPageStat icon={Users} value={period.entry_count} caption="People" />
            <CrmPageStat icon={FileText} value={money(period.total_gross)} caption="Gross" />
            <CrmPageStat icon={Calculator} value={money(period.total_deductions)} caption="Deductions" />
            <CrmPageStat icon={Lock} value={money(period.total_net)} caption="Net payable" />
          </>
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <CrmButton size="sm" icon={ArrowLeft} href="/company/people/payroll">
              All runs
            </CrmButton>

            <CrmStatusPill tone={STATUS_TONE[period.status] ?? 'neutral'}>{period.status_label}</CrmStatusPill>

            {can.manage && period.is_mutable && (
              <CrmButton icon={Calculator} onClick={() => router.post(`/company/people/payroll/${period.id}/calculate`, {}, { preserveScroll: true })}>
                {period.entry_count > 0 ? 'Recalculate' : 'Calculate'}
              </CrmButton>
            )}

            {allowedNext.filter((next) => canMove(next.value)).map((next) => (
              <CrmButton
                key={next.value}
                tone={next.value === 'approved' || next.value === 'paid' ? 'primary' : undefined}
                onClick={() => (next.value === 'paid' ? setPayment({ ...payment, open: true }) : move(next.value))}
              >
                {next.value === 'paid' ? 'Record payment' : next.label}
              </CrmButton>
            ))}
          </div>
        }
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_300px]">
        <CrmSection title="Employee payroll" description="Each row is a snapshot taken when the run was calculated.">
          {entries.length === 0 ? (
            <CrmDataState
              kind="empty"
              icon={Users}
              title="Nothing calculated yet"
              description="Calculating reads the compensation in force on the period end date. Anyone without one is reported rather than guessed at."
            />
          ) : (
            <div className="space-y-2">
              {entries.map((entry) => (
                <Link key={entry.id} href={`/company/people/payroll/${period.id}/payslip/${entry.id}`} className="block">
                  <CrmCard className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition hover:border-ovs-crm-accent/40">
                    <span className="min-w-[160px] flex-1">
                      <span className="block text-[13px] font-medium text-ovs-crm-text-primary">{entry.employee_name}</span>
                      <span className="mt-0.5 block text-[11.5px] tabular-nums text-ovs-crm-text-muted">{entry.employee_code}</span>
                    </span>

                    <span className="text-right text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                      {money(entry.gross_amount)}
                      <span className="ml-1 text-[11px] text-ovs-crm-text-muted">gross</span>
                    </span>

                    <span className="text-right text-[12.5px] tabular-nums text-ovs-crm-text-secondary">
                      −{money(entry.deduction_amount)}
                    </span>

                    <span className="min-w-[100px] text-right text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                      {money(entry.net_amount)}
                    </span>
                  </CrmCard>
                </Link>
              ))}
            </div>
          )}
        </CrmSection>

        <div className="space-y-4">
          <CrmSection title="Trail" description="Who moved this run, and when.">
            <CrmCard className="px-5 py-4">
              <ol className="space-y-3">
                {TRAIL_STEPS.map((step) => {
                  const stamp = period.trail[step.key];

                  return (
                    <li key={step.key} className="flex items-baseline justify-between gap-3">
                      <span className={stamp ? 'text-[12.5px] text-ovs-crm-text-primary' : 'text-[12.5px] text-ovs-crm-text-muted'}>
                        {step.label}
                      </span>
                      <span className="text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                        {stamp ? (
                          <>
                            {stamp.at}
                            {stamp.by && <span className="block">{stamp.by}</span>}
                          </>
                        ) : (
                          '—'
                        )}
                      </span>
                    </li>
                  );
                })}
              </ol>

              {period.payment_reference && (
                <p className="mt-4 border-t border-ovs-crm-line pt-3 text-[11.5px] text-ovs-crm-text-muted">
                  Payment reference <span className="text-ovs-crm-text-secondary">{period.payment_reference}</span>
                </p>
              )}
              {period.payment_notes && (
                <p className="mt-1 text-[11.5px] text-ovs-crm-text-muted">{period.payment_notes}</p>
              )}
            </CrmCard>
          </CrmSection>

          {!period.is_mutable && (
            <CrmCard className="flex items-start gap-3 px-5 py-4">
              <Lock className="mt-0.5 h-4 w-4 shrink-0 text-ovs-crm-text-muted" aria-hidden />
              <p className="text-[12px] text-ovs-crm-text-secondary">
                This run is {period.status_label.toLowerCase()}. Its figures can no longer be recalculated — a correction
                belongs in a later run, not in this one.
              </p>
            </CrmCard>
          )}
        </div>
      </div>

      <CrmModal
        open={payment.open}
        onClose={() => setPayment({ ...payment, open: false })}
        title="Record payment"
        description="Marks the run paid and stores the reference against it. No expense is posted to Finance yet."
        footer={
          <>
            <CrmButton onClick={() => setPayment({ ...payment, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="payroll-payment-form">
              Mark paid
            </CrmButton>
          </>
        }
      >
        <form id="payroll-payment-form" onSubmit={submitPayment} className="flex flex-col gap-3.5">
          <CrmFormField label="Payment reference" hint="Bank reference, batch id, or however this was settled.">
            <CrmInput value={payment.reference} onChange={(e) => setPayment({ ...payment, reference: e.target.value })} />
          </CrmFormField>

          <CrmFormField label="Notes">
            <CrmTextarea rows={3} value={payment.notes} onChange={(e) => setPayment({ ...payment, notes: e.target.value })} />
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
