import React from 'react';
import { Head, router } from '@inertiajs/react';
import { ArrowLeft, Plus, Scale } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmDataState,
  CrmHairlineBar,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * People → Leave balances
 *
 * Every figure here was recorded by somebody. There is no accrual rate, no
 * frequency and no carry-forward cap anywhere in this system, because none has
 * been decided — so nothing grants days on its own, and a balance of zero means
 * exactly that.
 */

const HUE = { violet: '#7C6FE0', green: '#2FA96B', coral: '#EF5B39' } as const;

interface Balance {
  leave_type_id: number;
  code: string;
  name: string;
  colour: string | null;
  is_paid: boolean;
  opening_balance: number;
  accrued: number;
  carried_forward: number;
  adjusted: number;
  used: number;
  entitled: number;
  available: number;
  has_balance: boolean;
}

interface Row {
  employee_id: number;
  name: string;
  employee_code: string;
  department: string | null;
  balances: Balance[];
}

interface Props {
  year: number;
  rows: Row[];
  types: Array<{ id: number; code: string; name: string; colour: string | null }>;
  can: { manage: boolean };
}

export default function LeaveBalances({ year, rows, types, can }: Props) {
  const [entry, setEntry] = React.useState({
    open: false,
    employeeId: '',
    typeId: '',
    entryType: 'accrual',
    days: '',
    reason: '',
  });

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    router.post(
      '/company/people/leave-balances/entries',
      {
        employee_id: Number(entry.employeeId),
        leave_type_id: Number(entry.typeId),
        period_year: year,
        entry_type: entry.entryType,
        days: Number(entry.days),
        reason: entry.reason || null,
      },
      { preserveScroll: true, onSuccess: () => setEntry({ ...entry, open: false, days: '', reason: '' }) },
    );
  };

  const totalAvailable = rows.reduce(
    (sum, row) => sum + row.balances.reduce((inner, balance) => inner + balance.available, 0),
    0,
  );

  return (
    <PeopleShell title="Leave balances">
      <Head title="Leave balances | People" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            People · Leave
          </p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Balances
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">
            Calendar year {year} · every movement is a recorded entry
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Scale} value={rows.length} caption="People" />
          <CrmPageStat icon={Scale} value={Math.round(totalAvailable * 10) / 10} caption="Days available" />
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <CrmButton size="sm" icon={ArrowLeft} href="/company/people/leave">
          Leave
        </CrmButton>

        <div className="flex flex-wrap items-center gap-2">
          <CrmInput
            type="number"
            min={2000}
            max={2100}
            value={year}
            onChange={(e) =>
              router.get('/company/people/leave/balances', { year: e.target.value }, { preserveState: true, replace: true })
            }
            aria-label="Year"
            className="w-[110px] text-[12px]"
          />
          {can.manage && types.length > 0 && (
            <CrmButton tone="primary" icon={Plus} onClick={() => setEntry({ ...entry, open: true })}>
              Record entry
            </CrmButton>
          )}
        </div>
      </div>

      {rows.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Scale}
          title="Nobody to show"
          description="Balances appear for the people you are allowed to see."
        />
      ) : (
        <div className="space-y-3">
          {rows.map((row) => (
            <CrmCard key={row.employee_id} className="px-5 py-4">
              <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-[14px] font-semibold text-ovs-crm-text-primary">{row.name}</p>
                  <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                    {row.employee_code}
                    {row.department && <> · {row.department}</>}
                  </p>
                </div>
                <CrmButton size="sm" href={`/company/people/attendance/employees/${row.employee_id}`}>
                  Attendance
                </CrmButton>
              </div>

              {row.balances.length === 0 ? (
                <p className="text-[12.5px] text-ovs-crm-text-muted">No leave types are defined yet.</p>
              ) : (
                <ul className="space-y-2.5 border-t border-ovs-crm-line pt-3">
                  {row.balances.map((balance) => (
                    <li key={balance.leave_type_id} className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                      <span className="flex min-w-[150px] items-center gap-2">
                        <span
                          className="h-2 w-2 shrink-0 rounded-full"
                          style={{ background: balance.colour ?? HUE.violet }}
                          aria-hidden
                        />
                        <span className="text-[12.5px] text-ovs-crm-text-secondary">{balance.name}</span>
                        {!balance.is_paid && <CrmStatusPill tone="neutral">Unpaid</CrmStatusPill>}
                      </span>

                      <CrmHairlineBar
                        value={Math.max(0, balance.used)}
                        max={Math.max(balance.entitled, balance.used, 1)}
                        color={balance.colour ?? HUE.violet}
                        className="min-w-[100px] flex-1"
                      />

                      <span className="min-w-[190px] text-right text-[11.5px] tabular-nums text-ovs-crm-text-muted">
                        {balance.carried_forward > 0 && <>carried {balance.carried_forward} · </>}
                        {balance.accrued > 0 && <>accrued {balance.accrued} · </>}
                        used {balance.used} of {balance.entitled}
                      </span>

                      <span
                        className={
                          balance.available < 0
                            ? 'min-w-[72px] text-right text-[13px] font-semibold tabular-nums text-ovs-crm-accent-coral-text'
                            : 'min-w-[72px] text-right text-[13px] font-semibold tabular-nums text-ovs-crm-text-primary'
                        }
                      >
                        {/* Negative is shown, not prevented — no policy says it may not happen. */}
                        {balance.available} left
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
        open={entry.open}
        onClose={() => setEntry({ ...entry, open: false })}
        title={`Record a balance entry · ${year}`}
        description="Accrual and carry-forward are recorded here. Nothing grants days automatically."
        footer={
          <>
            <CrmButton onClick={() => setEntry({ ...entry, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="balance-entry-form">
              Record
            </CrmButton>
          </>
        }
      >
        <form id="balance-entry-form" onSubmit={submit} className="flex flex-col gap-3.5">
          <CrmFormField label="Employee" required>
            <CrmSelect required value={entry.employeeId} onChange={(e) => setEntry({ ...entry, employeeId: e.target.value })}>
              <option value="">Choose a person…</option>
              {rows.map((row) => (
                <option key={row.employee_id} value={row.employee_id}>
                  {row.name} ({row.employee_code})
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Leave type" required>
            <CrmSelect required value={entry.typeId} onChange={(e) => setEntry({ ...entry, typeId: e.target.value })}>
              <option value="">Choose a type…</option>
              {types.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="Kind" required>
              <CrmSelect required value={entry.entryType} onChange={(e) => setEntry({ ...entry, entryType: e.target.value })}>
                <option value="opening">Opening balance</option>
                <option value="accrual">Accrual</option>
                <option value="carry_forward">Carried forward</option>
                <option value="adjustment">Adjustment</option>
              </CrmSelect>
            </CrmFormField>

            <CrmFormField label="Days" required hint="Negative takes days away.">
              <CrmInput
                type="number"
                step="0.5"
                required
                value={entry.days}
                onChange={(e) => setEntry({ ...entry, days: e.target.value })}
              />
            </CrmFormField>
          </div>

          <CrmFormField label="Reason">
            <CrmInput value={entry.reason} onChange={(e) => setEntry({ ...entry, reason: e.target.value })} />
          </CrmFormField>
        </form>
      </CrmModal>
    </PeopleShell>
  );
}
