import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Award, Coins, Plus } from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmStatusPill,
  CrmDataState,
  CrmPagination,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * Incentive awards.
 *
 * Deliberately not part of payroll. An award has its own date and amount and
 * carries no period, and nothing joins it to a payroll run — whether a
 * commission is eventually disbursed through a run or paid on its own is a
 * decision nobody has made. Keeping the ledger separate means that decision can
 * still go either way without rewriting awards already granted.
 */

interface IncentiveRow {
  id: number;
  employee_name: string | null;
  employee_code: string | null;
  description: string;
  amount: number;
  awarded_date: string | null;
  deal: { id: number; company_name: string } | null;
  recorded_at: string | null;
}

interface Props {
  incentives: {
    data: IncentiveRow[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    from?: number | null;
    to?: number | null;
    total?: number | null;
  };
  filters: { search?: string; employee_id?: string };
  employees: Array<{ id: number; name: string; employee_code: string }>;
  totals: { awarded: number; count: number };
  can: { manage: boolean };
}

const money = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

const day = (iso: string | null) =>
  iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

export default function IncentivesWorkspace({ incentives, filters, employees, totals, can }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [employeeId, setEmployeeId] = useState(filters.employee_id ?? '');
  const [form, setForm] = useState({ open: false, employeeId: '', description: '', amount: '', date: '', dealId: '' });

  const rows = incentives.data ?? [];

  const applyFilters = (next: { search?: string; employee_id?: string }) =>
    router.get(
      '/company/people/incentives',
      { search: next.search || undefined, employee_id: next.employee_id || undefined },
      { preserveState: true, preserveScroll: true, replace: true },
    );

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/company/people/incentives',
      {
        employee_id: Number(form.employeeId),
        description: form.description,
        amount: Number(form.amount),
        awarded_date: form.date,
        related_lead_id: form.dealId ? Number(form.dealId) : null,
      },
      {
        preserveScroll: true,
        onSuccess: () => setForm({ open: false, employeeId: '', description: '', amount: '', date: '', dealId: '' }),
      },
    );
  };

  return (
    <PeopleShell title="Incentives">
      <CrmPageHead
        eyebrow="People"
        title="Incentives"
        stats={
          <>
            <CrmPageStat icon={Award} value={totals.count} caption="Awards" />
            <CrmPageStat icon={Coins} value={money(totals.awarded)} caption="Awarded to date" />
          </>
        }
        actions={
          can.manage ? (
            <CrmButton tone="primary" icon={Plus} onClick={() => setForm({ ...form, open: true })}>
              Award incentive
            </CrmButton>
          ) : undefined
        }
      />

      <CrmCard className="mb-3 flex flex-wrap items-end gap-3 px-5 py-3.5">
        <div className="min-w-[200px] flex-1">
          <CrmFormField label="Search">
            <CrmInput
              type="search"
              placeholder="Award or person"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  applyFilters({ search, employee_id: employeeId });
                }
              }}
            />
          </CrmFormField>
        </div>

        <div className="min-w-[180px]">
          <CrmFormField label="Employee">
            <CrmSelect
              value={employeeId}
              onChange={(e) => {
                setEmployeeId(e.target.value);
                applyFilters({ search, employee_id: e.target.value });
              }}
            >
              <option value="">Everyone</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.name}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </div>

        <CrmButton onClick={() => applyFilters({ search, employee_id: employeeId })}>Apply</CrmButton>
      </CrmCard>

      {rows.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Award}
          title="No incentives recorded"
          description="An award names a person, an amount and a date, and can point at the deal it came from."
        />
      ) : (
        <div className="space-y-2">
          {rows.map((row) => (
            <CrmCard key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <span className="min-w-[200px] flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{row.description}</span>
                  {row.deal && <CrmStatusPill tone="info">{row.deal.company_name}</CrmStatusPill>}
                </span>
                <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                  {row.employee_name}
                  {row.employee_code && <span className="tabular-nums"> · {row.employee_code}</span>}
                </span>
              </span>

              <span className="text-[12.5px] tabular-nums text-ovs-crm-text-secondary">{day(row.awarded_date)}</span>

              <span className="min-w-[100px] text-right text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary">
                {money(row.amount)}
              </span>
            </CrmCard>
          ))}

          <CrmPagination
            links={incentives.links}
            from={incentives.from}
            to={incentives.to}
            total={incentives.total}
            className="pt-1"
          />
        </div>
      )}

      <CrmModal
        open={form.open}
        onClose={() => setForm({ ...form, open: false })}
        title="Award an incentive"
        description="Recorded in its own ledger. It is not added to a payroll run."
        footer={
          <>
            <CrmButton onClick={() => setForm({ ...form, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="incentive-form">
              Record award
            </CrmButton>
          </>
        }
      >
        <form id="incentive-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <CrmFormField label="Employee" required>
            <CrmSelect required value={form.employeeId} onChange={(e) => setForm({ ...form, employeeId: e.target.value })}>
              <option value="">Choose a person…</option>
              {employees.map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.name} ({employee.employee_code})
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Description" required hint="What the award is for.">
            <CrmInput required value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Amount" required>
              <CrmInput type="number" min={0} step="0.01" required value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            </CrmFormField>

            <CrmFormField label="Awarded on" required>
              <CrmInput type="date" required value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
            </CrmFormField>
          </div>

          <CrmFormField label="Related deal" hint="Optional. Only a deal you can already see will be accepted.">
            <CrmInput type="number" min={1} value={form.dealId} onChange={(e) => setForm({ ...form, dealId: e.target.value })} />
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
