import React from 'react';
import { Head, router } from '@inertiajs/react';
import { CalendarCheck, CalendarPlus, Check, Inbox, Plane, X } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmValue,
  CrmPageStat,
  CrmStatusPill,
  CrmButton,
  CrmModal,
  CrmFormField,
  CrmInput,
  CrmSelect,
  CrmTextarea,
  CrmDataState,
  CrmPagination,
  type CrmStatusTone,
} from '@/pages/Company/design';
import { PeopleShell } from './components/PeopleShell';

/**
 * People → Leave
 *
 * The queue first, then the register. `can_decide` on every row is resolved on
 * the server — from a real reporting line or the HR permission, and never for
 * your own request — so a button can never offer what the route would refuse.
 */

const HUE = {
  violet: '#7C6FE0',
  green: '#2FA96B',
  orange: '#F59245',
  blue: '#2F6BE4',
} as const;

interface LeaveRow {
  id: number;
  employee_id: number;
  employee_name: string | null;
  employee_code: string | null;
  department: string | null;
  leave_type: string | null;
  leave_type_colour: string | null;
  is_paid: boolean | null;
  start_date: string | null;
  end_date: string | null;
  total_days: number;
  total_part_day_hours: number;
  reason: string | null;
  status: string;
  status_label: string;
  decided_by: string | null;
  decided_via: string | null;
  decided_at: string | null;
  decision_note: string | null;
  can_decide: boolean;
}

interface Balance {
  leave_type_id: number;
  code: string;
  name: string;
  colour: string | null;
  is_paid: boolean;
  entitled: number;
  used: number;
  available: number;
  has_balance: boolean;
}

interface Props {
  requests: {
    data: LeaveRow[];
    links?: Array<{ url: string | null; label: string; active: boolean }>;
    from?: number | null;
    to?: number | null;
    total?: number | null;
  };
  awaitingMe: LeaveRow[];
  filters: { status?: string; employee_id?: string; year?: string };
  year: number;
  types: Array<{ id: number; code: string; name: string; colour: string | null; is_paid: boolean; allows_half_day: boolean }>;
  employees: Array<{ id: number; name: string; employee_code: string }>;
  myBalances: Balance[];
  me: { employee_id: number; name: string } | null;
  totals: { pending: number; awaiting_me: number };
  can: { viewAll: boolean; manage: boolean; approve: boolean };
}

const STATUS_TONE: Record<string, CrmStatusTone> = {
  pending: 'warn',
  approved: 'good',
  rejected: 'bad',
  cancelled: 'neutral',
};

const range = (start: string, end: string) => {
  if (!start || !end) {
    return '—';
  }

  const fmt = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });

  return start === end ? fmt(start) : `${fmt(start)} – ${fmt(end)}`;
};

export default function LeaveOverview({
  requests,
  awaitingMe,
  filters,
  year,
  types,
  employees,
  myBalances,
  me,
  totals,
  can,
}: Props) {
  const [form, setForm] = React.useState({
    open: false,
    employeeId: me ? String(me.employee_id) : '',
    typeId: '',
    start: '',
    end: '',
    reason: '',
    portion: 'full',
    hours: '',
  });

  const [deciding, setDeciding] = React.useState<LeaveRow | null>(null);
  const [note, setNote] = React.useState('');

  const rows = requests.data ?? [];

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    /*
     * A single-day request can carry a portion. A multi-day one is sent as full
     * days — mixing portions across a range needs a per-day editor, and
     * pretending one control covers it would silently mis-state the total.
     */
    const days =
      form.start && form.start === form.end && form.portion !== 'full'
        ? { [form.start]: { portion: form.portion, hours: form.hours ? Number(form.hours) : undefined } }
        : {};

    router.post(
      '/company/people/leave',
      {
        employee_id: Number(form.employeeId),
        leave_type_id: Number(form.typeId),
        start_date: form.start,
        end_date: form.end,
        reason: form.reason || null,
        days,
      },
      { preserveScroll: true, onSuccess: () => setForm({ ...form, open: false, start: '', end: '', reason: '' }) },
    );
  };

  const decide = (row: LeaveRow, decision: 'approved' | 'rejected') =>
    router.post(
      `/company/people/leave/${row.id}/decide`,
      { decision, note: note || null },
      { preserveScroll: true, onSuccess: () => { setDeciding(null); setNote(''); } },
    );

  const singleDay = Boolean(form.start) && form.start === form.end;

  return (
    <PeopleShell title="Leave">
      <Head title="Leave | People" />

      <div className="mb-7 flex flex-wrap items-center justify-between gap-x-12 gap-y-6">
        <div className="min-w-0 shrink-0">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">People</p>
          <h1 className="whitespace-nowrap text-[36px] font-bold leading-[1.04] tracking-[-0.035em] text-ovs-crm-text-primary sm:text-[42px]">
            Leave
          </h1>
          <p className="mt-1.5 text-[12.5px] text-ovs-crm-text-secondary">Requests, decisions and what people have left</p>
        </div>

        <div className="flex flex-wrap items-center gap-x-9 gap-y-5">
          <CrmPageStat icon={Inbox} value={totals.awaiting_me} caption="Waiting on you" />
          <CrmPageStat icon={CalendarCheck} value={totals.pending} caption="Pending in all" />
          <CrmPageStat icon={Plane} value={requests.total ?? rows.length} caption="Requests" />
        </div>
      </div>

      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <CrmSelect
            value={filters.status ?? ''}
            onChange={(e) =>
              router.get('/company/people/leave', { ...filters, status: e.target.value || undefined }, { preserveState: true, replace: true })
            }
            aria-label="Status"
            className="w-auto text-[12px]"
          >
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
            <option value="cancelled">Cancelled</option>
          </CrmSelect>

          <CrmButton size="sm" href="/company/people/leave/balances">
            Balances
          </CrmButton>
          {can.manage && (
            <CrmButton size="sm" href="/company/people/leave-types">
              Leave types
            </CrmButton>
          )}
        </div>

        {can.manage && types.length > 0 && (
          <CrmButton tone="primary" icon={CalendarPlus} onClick={() => setForm({ ...form, open: true })}>
            Request leave
          </CrmButton>
        )}
      </div>

      {types.length === 0 && (
        <CrmCard className="mb-4 flex flex-wrap items-center justify-between gap-4 px-5 py-4" step={0}>
          <div className="flex items-start gap-3">
            <CrmCardIcon icon={Plane} hue={HUE.orange} />
            <div>
              <p className="text-[13px] font-semibold text-ovs-crm-text-primary">No leave types defined</p>
              {/*
                Nothing is seeded. What leave this company offers is theirs to
                decide, not a default shipped in a migration comment.
              */}
              <p className="mt-0.5 text-[12px] text-ovs-crm-text-secondary">
                Leave cannot be requested until somebody defines the kinds this company offers.
              </p>
            </div>
          </div>
          {can.manage && <CrmButton size="sm" href="/company/people/leave-types">Define leave types</CrmButton>}
        </CrmCard>
      )}

      {awaitingMe.length > 0 && (
        <CrmCard className="mb-4 p-6" step={1} frostHue={HUE.orange}>
          <div className="mb-4 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">Waiting on you</h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                Requests you can decide — as their manager, or through HR
              </p>
            </div>
            <CrmCardIcon icon={Inbox} hue={HUE.orange} />
          </div>

          <ul className="space-y-2">
            {awaitingMe.map((row) => (
              <li
                key={row.id}
                className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[16px] border border-ovs-crm-border bg-ovs-crm-surface px-4 py-3"
              >
                <span className="min-w-[160px] flex-1">
                  <span className="block text-[13px] font-medium text-ovs-crm-text-primary">{row.employee_name}</span>
                  <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                    {row.leave_type} · {range(row.start_date ?? '', row.end_date ?? '')}
                  </span>
                </span>

                <span className="text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                  {row.total_days} {row.total_days === 1 ? 'day' : 'days'}
                  {row.total_part_day_hours > 0 && (
                    <span className="ml-1.5 text-[11.5px] font-normal text-ovs-crm-text-muted">
                      + {row.total_part_day_hours}h
                    </span>
                  )}
                </span>

                <span className="flex gap-2">
                  <CrmButton size="sm" tone="primary" icon={Check} onClick={() => decide(row, 'approved')}>
                    Approve
                  </CrmButton>
                  <CrmButton size="sm" icon={X} onClick={() => setDeciding(row)}>
                    Reject
                  </CrmButton>
                </span>
              </li>
            ))}
          </ul>
        </CrmCard>
      )}

      {me && myBalances.length > 0 && (
        <CrmCard className="mb-4 p-6" step={2}>
          <div className="mb-4 flex items-start gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-[15px] font-semibold tracking-[-0.01em] text-ovs-crm-text-primary">
                Your balance · {year}
              </h2>
              <p className="mt-0.5 text-[11.5px] text-ovs-crm-text-muted">
                Nothing accrues on its own — every figure here was recorded by somebody
              </p>
            </div>
            <CrmCardIcon icon={CalendarCheck} hue={HUE.violet} />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {myBalances.map((balance) => (
              <div key={balance.leave_type_id} className="rounded-[18px] bg-ovs-crm-surface-inset px-4 py-3">
                <span
                  className="block h-1.5 w-8 rounded-full"
                  style={{ background: balance.colour ?? HUE.violet }}
                  aria-hidden
                />
                <p className="mt-2 text-[12px] text-ovs-crm-text-secondary">{balance.name}</p>
                <CrmValue value={balance.available} size="minor" className="mt-1" />
                <p className="mt-1 text-[11px] text-ovs-crm-text-muted">
                  {balance.used} of {balance.entitled} used
                  {!balance.is_paid && <> · unpaid</>}
                </p>
              </div>
            ))}
          </div>
        </CrmCard>
      )}

      {rows.length === 0 ? (
        <CrmDataState
          kind="empty"
          icon={Plane}
          title="No leave requests"
          description="A request names a person, a type and the days off, and waits for their manager or HR to decide."
        />
      ) : (
        <div className="space-y-2">
          {rows.map((row) => (
            <CrmCard key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
              <span className="min-w-[180px] flex-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="text-[13.5px] font-medium text-ovs-crm-text-primary">{row.employee_name}</span>
                  <CrmStatusPill tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status_label}</CrmStatusPill>
                  {row.is_paid === false && <CrmStatusPill tone="neutral">Unpaid</CrmStatusPill>}
                </span>
                <span className="mt-0.5 block text-[11.5px] text-ovs-crm-text-muted">
                  {row.leave_type} · {range(row.start_date ?? '', row.end_date ?? '')}
                  {row.decided_by && <> · {row.status_label.toLowerCase()} by {row.decided_by} ({row.decided_via})</>}
                </span>
              </span>

              <span className="min-w-[92px] text-right text-[12.5px] font-medium tabular-nums text-ovs-crm-text-primary">
                {row.total_days} {row.total_days === 1 ? 'day' : 'days'}
                {row.total_part_day_hours > 0 && (
                  <span className="block text-[11px] font-normal text-ovs-crm-text-muted">
                    + {row.total_part_day_hours}h part
                  </span>
                )}
              </span>

              <span className="flex gap-2">
                {row.can_decide && (
                  <>
                    <CrmButton size="sm" tone="primary" onClick={() => decide(row, 'approved')}>
                      Approve
                    </CrmButton>
                    <CrmButton size="sm" onClick={() => setDeciding(row)}>
                      Reject
                    </CrmButton>
                  </>
                )}
                {(row.status === 'pending' || row.status === 'approved') && can.manage && (
                  <CrmButton
                    size="sm"
                    onClick={() => router.post(`/company/people/leave/${row.id}/cancel`, {}, { preserveScroll: true })}
                  >
                    Cancel
                  </CrmButton>
                )}
              </span>
            </CrmCard>
          ))}

          <CrmPagination
            links={requests.links}
            from={requests.from}
            to={requests.to}
            total={requests.total}
            className="pt-1"
          />
        </div>
      )}

      <CrmModal
        open={form.open}
        onClose={() => setForm({ ...form, open: false })}
        title="Request leave"
        description="Balances can go negative — an overdraft is shown, not blocked."
        footer={
          <>
            <CrmButton onClick={() => setForm({ ...form, open: false })}>Cancel</CrmButton>
            <CrmButton tone="primary" type="submit" form="leave-request-form">
              Submit request
            </CrmButton>
          </>
        }
      >
        <form id="leave-request-form" onSubmit={submit} className="flex flex-col gap-3.5">
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

          <CrmFormField label="Leave type" required>
            <CrmSelect required value={form.typeId} onChange={(e) => setForm({ ...form, typeId: e.target.value })}>
              <option value="">Choose a type…</option>
              {types.map((type) => (
                <option key={type.id} value={type.id}>
                  {type.name}
                  {type.is_paid ? '' : ' (unpaid)'}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <CrmFormField label="From" required>
              <CrmInput type="date" required value={form.start} onChange={(e) => setForm({ ...form, start: e.target.value, end: form.end || e.target.value })} />
            </CrmFormField>

            <CrmFormField label="To" required>
              <CrmInput type="date" required value={form.end} onChange={(e) => setForm({ ...form, end: e.target.value })} />
            </CrmFormField>
          </div>

          {singleDay && (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <CrmFormField label="Portion" hint="Half counts 0.5 of a day.">
                <CrmSelect value={form.portion} onChange={(e) => setForm({ ...form, portion: e.target.value })}>
                  <option value="full">Full day</option>
                  <option value="half">Half day</option>
                  <option value="part">Part day (hours)</option>
                </CrmSelect>
              </CrmFormField>

              {form.portion === 'part' && (
                <CrmFormField
                  label="Hours"
                  required
                  /* Hours are recorded, never converted — no standard working day exists. */
                  hint="Recorded in hours; it does not consume a day of balance."
                >
                  <CrmInput
                    type="number"
                    min={0.25}
                    max={24}
                    step="0.25"
                    required
                    value={form.hours}
                    onChange={(e) => setForm({ ...form, hours: e.target.value })}
                  />
                </CrmFormField>
              )}
            </div>
          )}

          <CrmFormField label="Reason">
            <CrmTextarea rows={2} value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
          </CrmFormField>
        </form>
      </CrmModal>

      <CrmModal
        open={deciding !== null}
        onClose={() => setDeciding(null)}
        title="Reject this request"
        description={deciding ? `${deciding.employee_name} · ${range(deciding.start_date ?? '', deciding.end_date ?? '')}` : ''}
        size="sm"
        footer={
          <>
            <CrmButton onClick={() => setDeciding(null)}>Cancel</CrmButton>
            <CrmButton tone="primary" onClick={() => deciding && decide(deciding, 'rejected')}>
              Reject
            </CrmButton>
          </>
        }
      >
        <CrmFormField label="Note" hint="Kept on the decision, and visible to the requester.">
          <CrmTextarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} />
        </CrmFormField>
      </CrmModal>
    </PeopleShell>
  );
}
