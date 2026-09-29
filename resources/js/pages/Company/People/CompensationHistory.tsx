import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { ArrowLeft, History, Plus, Wallet } from 'lucide-react';
import {
  CrmPageHead,
  CrmPageStat,
  CrmCard,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmStatusPill,
  CrmDataState,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * What one person earns, and what they used to earn.
 *
 * Recording a new arrangement closes the previous one by date rather than
 * editing it. That is the whole reason a run calculated last month still
 * reports last month's figures after somebody's pay changes.
 *
 * No rate here is built in. Every component and every value on it is the
 * business's to configure — this screen has no opinion about what a deduction
 * ought to be.
 */

interface ComponentOption {
  id: number;
  code: string;
  name: string;
  type: string;
  calculation_type: string;
}

interface CompensationLine {
  id: number;
  component_name: string | null;
  component_code: string | null;
  type: string | null;
  calculation_type: string | null;
  value: number;
}

interface Arrangement {
  id: number;
  base_salary: number;
  effective_from: string | null;
  effective_to: string | null;
  is_current: boolean;
  notes: string | null;
  created_by: string | null;
  lines: CompensationLine[];
}

interface Props {
  employee: { id: number; employee_code: string; name: string; designation: string | null };
  history: Arrangement[];
  components: ComponentOption[];
  can: { manage: boolean };
}

const money = (value: number) =>
  new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);

const day = (iso: string | null) =>
  iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : null;

const lineValue = (line: CompensationLine) =>
  line.calculation_type === 'percentage_of_base' ? `${line.value}% of base` : money(line.value);

export default function CompensationHistory({ employee, history, components, can }: Props) {
  const [form, setForm] = useState<{
    open: boolean;
    base: string;
    from: string;
    notes: string;
    lines: Array<{ componentId: string; value: string }>;
  }>({ open: false, base: '', from: '', notes: '', lines: [] });

  const current = history.find((row) => row.is_current);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      `/company/people/employees/${employee.id}/compensation`,
      {
        base_salary: Number(form.base),
        effective_from: form.from,
        notes: form.notes || null,
        lines: form.lines
          .filter((line) => line.componentId && line.value !== '')
          .map((line) => ({ component_id: Number(line.componentId), value: Number(line.value) })),
      },
      { preserveScroll: true, onSuccess: () => setForm({ open: false, base: '', from: '', notes: '', lines: [] }) },
    );
  };

  return (
    <PeopleShell title={`Compensation · ${employee.name}`}>
      <CrmPageHead
        eyebrow={`Compensation · ${employee.employee_code}`}
        title={employee.name}
        stats={
          <>
            <CrmPageStat icon={Wallet} value={current ? money(current.base_salary) : '—'} caption="Base salary" />
            <CrmPageStat icon={History} value={history.length} caption="Arrangements on record" />
          </>
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <CrmButton size="sm" icon={ArrowLeft} href={`/company/people/employees/${employee.id}`}>
              Employee
            </CrmButton>
            {can.manage && (
              <CrmButton tone="primary" icon={Plus} onClick={() => setForm({ ...form, open: true })}>
                Record change
              </CrmButton>
            )}
          </div>
        }
      />

      {history.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Wallet}
          title="No compensation on record"
          description="Until an arrangement exists, payroll reports this person as an exception rather than guessing at a salary."
        />
      ) : (
        <div className="space-y-2">
          {history.map((row) => (
            <CrmCard key={row.id} className="px-5 py-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-[15px] font-semibold tabular-nums text-ovs-crm-text-primary">
                      {money(row.base_salary)}
                    </span>
                    <CrmStatusPill tone={row.is_current ? 'good' : 'neutral'}>
                      {row.is_current ? 'In force' : 'Superseded'}
                    </CrmStatusPill>
                  </div>
                  <p className="mt-0.5 text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                    {day(row.effective_from)} – {day(row.effective_to) ?? 'open'}
                    {row.created_by && <> · recorded by {row.created_by}</>}
                  </p>
                  {row.notes && <p className="mt-1 text-[12px] text-ovs-crm-text-secondary">{row.notes}</p>}
                </div>
              </div>

              {row.lines.length > 0 && (
                <ul className="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 border-t border-ovs-crm-line pt-3">
                  {row.lines.map((line) => (
                    <li key={line.id} className="text-[12px]">
                      <span className="text-ovs-crm-text-secondary">{line.component_name ?? line.component_code}</span>
                      <span className="ml-2 tabular-nums text-ovs-crm-text-primary">{lineValue(line)}</span>
                      <span className="ml-1.5 text-[11px] text-ovs-crm-text-muted">
                        {line.type === 'deduction' ? 'deduction' : 'earning'}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CrmCard>
          ))}
        </div>
      )}

      <CrmModal
        open={form.open}
        onClose={() => setForm({ ...form, open: false })}
        title="Record a compensation change"
        description="The arrangement in force is closed the day before this one starts. Nothing already calculated changes."
        footer={
          <>
            <CrmButton onClick={() => setForm({ ...form, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="compensation-form">
              Record
            </CrmButton>
          </>
        }
      >
        <form id="compensation-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Base salary" required>
              <CrmInput type="number" min={0} step="0.01" required value={form.base} onChange={(e) => setForm({ ...form, base: e.target.value })} />
            </CrmFormField>

            <CrmFormField label="Effective from" required>
              <CrmInput type="date" required value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
            </CrmFormField>
          </div>

          <CrmFormField label="Notes">
            <CrmTextarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </CrmFormField>

          <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between">
              <span className="text-[12px] font-medium text-ovs-crm-text-secondary">Components</span>
              <CrmButton
                size="sm"
                icon={Plus}
                onClick={() => setForm({ ...form, lines: [...form.lines, { componentId: '', value: '' }] })}
              >
                Add
              </CrmButton>
            </div>

            {form.lines.length === 0 ? (
              <p className="text-[11.5px] text-ovs-crm-text-muted">
                Base salary alone is a complete arrangement. Add allowances or deductions only where the business has them.
              </p>
            ) : (
              form.lines.map((line, index) => (
                <div key={index} className="grid grid-cols-[minmax(0,1fr)_120px] gap-2">
                  <CrmSelect
                    required
                    value={line.componentId}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        lines: form.lines.map((l, i) => (i === index ? { ...l, componentId: e.target.value } : l)),
                      })
                    }
                  >
                    <option value="">Choose a component…</option>
                    {components.map((component) => (
                      <option key={component.id} value={component.id}>
                        {component.name} ({component.calculation_type === 'percentage_of_base' ? '% of base' : 'fixed'})
                      </option>
                    ))}
                  </CrmSelect>

                  <CrmInput
                    type="number"
                    min={0}
                    step="0.01"
                    required
                    placeholder="Value"
                    value={line.value}
                    onChange={(e) =>
                      setForm({
                        ...form,
                        lines: form.lines.map((l, i) => (i === index ? { ...l, value: e.target.value } : l)),
                      })
                    }
                  />
                </div>
              ))
            )}
          </div>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
