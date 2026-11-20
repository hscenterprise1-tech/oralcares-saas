import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Banknote, ChevronDown, FileText, HandCoins, Plus, Trash2 } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import { PersonAvatar } from '@/clinic/ui/PersonAvatar';
import { EmptyState } from '@/clinic/ui/states';
import { PillTabs } from '@/clinic/ui/Tabs';
import { formatMoney, humanize } from '@/clinic/ui/visuals';
import { cn } from '@/lib/utils';

interface Line {
  id: number;
  kind: string;
  description: string | null;
  amount: number;
  automatic: boolean;
}

interface Entry {
  id: number;
  employee: string | null;
  designation: string | null;
  salary_type: string;
  units: number | null;
  gross: number;
  deductions: number;
  net: number;
  status: string;
  payslip_number: string | null;
  paid_at: string | null;
  payment_method: string | null;
  lines: Line[];
  advances: { id: number; outstanding: number; given_on: string }[];
  payslip_url: string;
}

interface Salary {
  type: string;
  base_amount: number;
  commission_percent: number | null;
  per_session_rate: number | null;
  effective_from: string;
}

interface Person {
  id: number;
  name: string;
  designation: string | null;
  salary: Salary | null;
  open_advances: number;
}

/** `PayrollController::index`: the server is the contract. */
interface Props {
  periods: { id: number; label: string; status: 'draft' | 'finalised' }[];
  period: { id: number; label: string; status: 'draft' | 'finalised'; entries: Entry[] } | null;
  employees: Person[];
  next_month: string;
  links: { index: string };
  can: { manage: boolean };
  options: { types: string[]; methods: string[]; line_kinds: string[] };
}

const SURFACE = 'rounded-2xl border border-cx-line/80 bg-white shadow-cx-card';
const TYPE_LABEL: Record<string, string> = { monthly: 'Monthly salary', daily: 'Per day', hourly: 'Per hour', per_session: 'Per session' };
const UNIT_LABEL: Record<string, string> = { daily: 'Days worked', hourly: 'Hours worked', per_session: 'Sessions' };
/** Today as the person sees it (not UTC, which is a day behind in the early hours). */
const today = () => {
  const now = new Date();

  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

export default function PayrollIndex(props: Props) {
  return (
    <ClinicPortalShell title="Payroll">
      <PayrollPage {...props} />
    </ClinicPortalShell>
  );
}

/** Who is paid how, each month's pay with its lines, advances, and printable payslips. Finalising a month numbers the payslips and locks them. */
function PayrollPage({ periods, period, employees, next_month, links, can, options }: Props) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [tab, setTab] = useState<'pay' | 'salaries'>('pay');
  const [opening, setOpening] = useState(false);
  const [salaryFor, setSalaryFor] = useState<Person | null>(null);
  const [advanceFor, setAdvanceFor] = useState<Person | null>(null);
  const [finalising, setFinalising] = useState(false);
  const [busy, setBusy] = useState(false);
  const totals = (period?.entries ?? []).reduce((t, e) => ({ gross: t.gross + e.gross, net: t.net + e.net }), { gross: 0, net: 0 });
  const unpaid = (period?.entries ?? []).filter((e) => e.status !== 'paid').length;

  return (
    <div className="w-full pb-12">
      <PageHeader
        icon={Banknote}
        title="Payroll"
        description={period ? `${period.label} · ${period.status === 'draft' ? 'Draft' : 'Finalised'} · ${formatMoney(totals.net)} to pay` : 'Open a month to work out pay'}
        actions={
          <>
            {periods.length > 0 && (
              <div className="w-40">
                <Select aria-label="Month" value={String(period?.id ?? '')} onChange={(e) => router.get(links.index, { period: e.target.value }, { preserveScroll: true })} options={periods.map((p) => ({ value: String(p.id), label: p.label }))} />
              </div>
            )}
            {can.manage && <Button onClick={() => setOpening(true)}><Plus className="h-4 w-4" aria-hidden="true" /> Open a month</Button>}
            {can.manage && period?.status === 'draft' && period.entries.length > 0 && <Button tone="primary" onClick={() => setFinalising(true)}>Finalise {period.label}</Button>}
          </>
        }
      />
      {errors.payroll && <Notice tone="danger" className="mb-4">{errors.payroll}</Notice>}

      <PillTabs label="Payroll" className="mb-4" value={tab} onChange={setTab} options={[{ key: 'pay', label: 'Monthly pay', count: period?.entries.length ?? 0 }, { key: 'salaries', label: 'Salaries & advances', count: employees.length }]} />

      {tab === 'pay' && (
        !period ? (
          <EmptyState title="No month opened yet" description="Open a month to work out each person's pay from their salary, leave and sessions." action={can.manage ? <Button tone="primary" onClick={() => setOpening(true)}>Open {next_month}</Button> : undefined} />
        ) : period.entries.length === 0 ? (
          <EmptyState title="Nobody on this month" description="Set a salary for your staff first, then open the month again." />
        ) : (
          <>
            {period.status === 'finalised' && unpaid > 0 && <Notice tone="info" className="mb-4">{unpaid} {unpaid === 1 ? 'payslip is' : 'payslips are'} not marked paid yet.</Notice>}
            <ul className="space-y-3">
              {period.entries.map((entry) => <EntryCard key={entry.id} entry={entry} periodStatus={period.status} base={links.index} can={can.manage} options={options} />)}
            </ul>
          </>
        )
      )}

      {tab === 'salaries' && (
        employees.length === 0 ? (
          <EmptyState title="No active staff" description="Add staff first." />
        ) : (
          <ul className={cn(SURFACE, 'divide-y divide-cx-line/60')}>
            {employees.map((person) => (
              <li key={person.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                <PersonAvatar name={person.name} />
                <div className="min-w-0 flex-1 basis-48">
                  <p className="truncate text-[14px] font-semibold text-cx-nav-ink">{person.name}</p>
                  <p className="text-[12.5px] text-cx-text-3">
                    {person.salary ? `${TYPE_LABEL[person.salary.type] ?? humanize(person.salary.type)} · ${formatMoney(person.salary.base_amount)} from ${person.salary.effective_from}` : 'No salary set'}
                  </p>
                </div>
                {person.open_advances > 0 && <span className="rounded-full bg-cx-amber-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-amber-ink">Advance due {formatMoney(person.open_advances)}</span>}
                {can.manage && (
                  <div className="flex gap-2">
                    <Button size="sm" onClick={() => setAdvanceFor(person)}><HandCoins className="h-4 w-4" aria-hidden="true" /> Advance</Button>
                    <Button size="sm" onClick={() => setSalaryFor(person)}>{person.salary ? 'Change salary' : 'Set salary'}</Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )
      )}

      {opening && <OpenDialog base={links.index} month={next_month} onClose={() => { setOpening(false); setTab('pay'); }} />}
      {salaryFor && <SalaryDialog person={salaryFor} base={links.index} types={options.types} onClose={() => setSalaryFor(null)} />}
      {advanceFor && <AdvanceDialog person={advanceFor} base={links.index} methods={options.methods} onClose={() => setAdvanceFor(null)} />}
      {finalising && period && (
        <Modal
          open
          onOpenChange={(open) => !open && setFinalising(false)}
          size="sm"
          title={`Finalise ${period.label}?`}
          description="Payslips are numbered and can no longer change."
          footer={
            <>
              <Button onClick={() => setFinalising(false)} disabled={busy}>Cancel</Button>
              <Button tone="primary" loading={busy} onClick={() => router.post(`${links.index}/periods/${period.id}/finalise`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setFinalising(false); } })}>Finalise</Button>
            </>
          }
        >
          <p className="text-[13px] text-cx-text-2">{period.entries.length} payslips, {formatMoney(totals.net)} in total.</p>
        </Modal>
      )}
    </div>
  );
}

function EntryCard({ entry, periodStatus, base, can, options }: { entry: Entry; periodStatus: string; base: string; can: boolean; options: Props['options'] }) {
  const [open, setOpen] = useState(false);
  const [paying, setPaying] = useState(false);
  const [units, setUnits] = useState(String(entry.units ?? ''));
  const [line, setLine] = useState({ kind: options.line_kinds[0] ?? 'incentive', description: '', amount: '', salary_advance_id: '' });
  const [busy, setBusy] = useState(false);
  const draft = periodStatus === 'draft';
  const send = (method: 'post' | 'put' | 'delete', url: string, data: Record<string, unknown> = {}, extra: Record<string, unknown> = {}) =>
    router[method](url, data as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), ...extra });

  return (
    <li className={SURFACE}>
      <button type="button" aria-expanded={open} onClick={() => setOpen(!open)} className="flex w-full flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#1769AA]/35">
        <PersonAvatar name={entry.employee ?? '?'} />
        <div className="min-w-0 flex-1 basis-48">
          <p className="truncate text-[14px] font-semibold text-cx-nav-ink">{entry.employee}</p>
          <p className="text-[12.5px] text-cx-text-3">{TYPE_LABEL[entry.salary_type] ?? humanize(entry.salary_type)}{entry.payslip_number ? ` · ${entry.payslip_number}` : ''}</p>
        </div>
        <div className="text-right">
          <p className="text-[15px] font-semibold tabular-nums text-cx-nav-ink">{formatMoney(entry.net)}</p>
          <p className="text-[11.5px] text-cx-text-3">{formatMoney(entry.gross)} − {formatMoney(entry.deductions)}</p>
        </div>
        <span className={cn('rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold', entry.status === 'paid' ? 'bg-cx-green-bg text-cx-green-ink' : 'bg-cx-slate-bg text-cx-slate-ink')}>{entry.status === 'paid' ? 'Paid' : humanize(entry.status)}</span>
        <ChevronDown className={cn('h-4 w-4 text-cx-text-3 transition-transform', open && 'rotate-180')} aria-hidden="true" />
      </button>

      {open && (
        <div className="space-y-4 border-t border-cx-line/70 px-5 py-4">
          <ul className="divide-y divide-cx-line/60 text-[13.5px]">
            {entry.lines.map((l) => (
              <li key={l.id} className="flex items-center justify-between gap-3 py-2">
                <span className="text-cx-text-2">{humanize(l.kind)}{l.description ? ` · ${l.description}` : ''}{l.automatic ? ' (worked out)' : ''}</span>
                <span className="flex items-center gap-2">
                  <span className={cn('tabular-nums', ['deduction', 'advance_recovery', 'leave_deduction'].includes(l.kind) ? 'text-cx-rose-ink' : 'text-cx-nav-ink')}>{formatMoney(l.amount)}</span>
                  {can && draft && !l.automatic && (
                    <button type="button" aria-label="Remove line" onClick={() => send('delete', `${base}/entries/${entry.id}/lines/${l.id}`)} className="grid h-8 w-8 place-items-center rounded-lg text-cx-text-2 hover:bg-cx-rose-bg hover:text-cx-rose-ink"><Trash2 className="h-4 w-4" aria-hidden="true" /></button>
                  )}
                </span>
              </li>
            ))}
          </ul>

          {can && draft && (
            <>
              {UNIT_LABEL[entry.salary_type] && (
                <div className="flex flex-wrap items-end gap-3">
                  <Field label={UNIT_LABEL[entry.salary_type]} htmlFor={`un-${entry.id}`} className="w-40"><Input id={`un-${entry.id}`} type="number" min={0} step="0.5" value={units} onChange={(e) => setUnits(e.target.value)} /></Field>
                  <Button onClick={() => send('put', `${base}/entries/${entry.id}/units`, { units: Number(units) })} disabled={busy || units === ''}>Update</Button>
                </div>
              )}
              <div className="grid gap-3 border-t border-cx-line/60 pt-4 sm:grid-cols-2 xl:grid-cols-5">
                <Select aria-label="Kind of line" value={line.kind} onChange={(e) => setLine({ ...line, kind: e.target.value })} options={options.line_kinds.map((k) => ({ value: k, label: humanize(k) }))} />
                <Input aria-label="Description" placeholder="Description" value={line.description} onChange={(e) => setLine({ ...line, description: e.target.value })} />
                <Input aria-label="Amount" type="number" min={0} placeholder="Amount" value={line.amount} onChange={(e) => setLine({ ...line, amount: e.target.value })} />
                {line.kind === 'advance_recovery' && entry.advances.length > 0 ? (
                  <Select aria-label="Advance" value={line.salary_advance_id} onChange={(e) => setLine({ ...line, salary_advance_id: e.target.value })} options={[{ value: '', label: 'Which advance' }, ...entry.advances.map((a) => ({ value: String(a.id), label: `${a.given_on} · ${formatMoney(a.outstanding)} due` }))]} />
                ) : <span className="hidden xl:block" />}
                <Button onClick={() => send('post', `${base}/entries/${entry.id}/lines`, { kind: line.kind, description: line.description || null, amount: Number(line.amount), salary_advance_id: line.salary_advance_id ? Number(line.salary_advance_id) : null }, { onSuccess: () => setLine({ ...line, description: '', amount: '' }) })} disabled={busy || Number(line.amount) <= 0}>Add line</Button>
              </div>
              <Button size="sm" onClick={() => send('post', `${base}/entries/${entry.id}/recalculate`)} disabled={busy}>Work out again from records</Button>
            </>
          )}

          <div className="flex flex-wrap items-center gap-2 border-t border-cx-line/60 pt-4">
            {entry.payslip_number && <a href={entry.payslip_url} target="_blank" rel="noreferrer" className="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-[#1769AA] hover:bg-cx-slate-bg"><FileText className="h-4 w-4" aria-hidden="true" /> Payslip</a>}
            {entry.status === 'paid' && <span className="text-[12.5px] text-cx-text-3">Paid by {humanize(entry.payment_method)}{entry.paid_at ? ` on ${new Date(entry.paid_at).toLocaleDateString('en-IN')}` : ''}</span>}
            {can && !draft && entry.status !== 'paid' && <Button tone="primary" size="sm" onClick={() => setPaying(true)}>Mark paid</Button>}
          </div>
        </div>
      )}

      {paying && <PayDialog entry={entry} base={base} methods={options.methods} onClose={() => setPaying(false)} />}
    </li>
  );
}

function PayDialog({ entry, base, methods, onClose }: { entry: Entry; base: string; methods: string[]; onClose: () => void }) {
  const [method, setMethod] = useState(methods[0] ?? 'cash');
  const [reference, setReference] = useState('');
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title={`Mark ${entry.employee}'s pay as paid`}
      description={formatMoney(entry.net)}
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} onClick={() => router.post(`${base}/entries/${entry.id}/pay`, { method, reference: reference || null } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}>Mark paid</Button>
        </>
      }
    >
      <div className="space-y-4">
        <Field label="Paid by" htmlFor="py-method"><Select id="py-method" value={method} onChange={(e) => setMethod(e.target.value)} options={methods.map((m) => ({ value: m, label: humanize(m) }))} /></Field>
        <Field label="Reference" htmlFor="py-ref" hint="Cheque or transaction number, if any."><Input id="py-ref" value={reference} maxLength={100} onChange={(e) => setReference(e.target.value)} /></Field>
      </div>
    </Modal>
  );
}

function OpenDialog({ base, month, onClose }: { base: string; month: string; onClose: () => void }) {
  const [value, setValue] = useState(month);
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title="Open a month"
      description="Pay is worked out for every active staff member from their salary, leave and sessions. It stays a draft until you finalise it."
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={value === ''} onClick={() => router.post(`${base}/periods`, { month: value } as never, { onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}>Open month</Button>
        </>
      }
    >
      <Field label="Month" htmlFor="op-month"><Input id="op-month" type="month" value={value} onChange={(e) => setValue(e.target.value)} /></Field>
    </Modal>
  );
}

function SalaryDialog({ person, base, types, onClose }: { person: Person; base: string; types: string[]; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const s = person.salary;
  const [f, setF] = useState({ salary_type: s?.type ?? types[0] ?? 'monthly', base_amount: String(s?.base_amount ?? ''), commission_percent: String(s?.commission_percent ?? ''), per_session_rate: String(s?.per_session_rate ?? ''), effective_from: today(), notes: '' });
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="md"
      title={`${s ? 'Change' : 'Set'} salary · ${person.name}`}
      description="A change applies from the date you choose. Earlier months keep the salary they were worked out with."
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button
            tone="primary"
            loading={busy}
            disabled={f.base_amount === ''}
            onClick={() => router.put(`${base}/salaries/${person.id}`, { ...f, base_amount: Number(f.base_amount), commission_percent: f.commission_percent === '' ? null : Number(f.commission_percent), per_session_rate: f.per_session_rate === '' ? null : Number(f.per_session_rate), notes: f.notes || null } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}
          >
            Save salary
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {errors.payroll && <Notice tone="danger">{errors.payroll}</Notice>}
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Paid" htmlFor="sl-type"><Select id="sl-type" value={f.salary_type} onChange={(e) => setF({ ...f, salary_type: e.target.value })} options={types.map((t) => ({ value: t, label: TYPE_LABEL[t] ?? humanize(t) }))} /></Field>
          <Field label={f.salary_type === 'monthly' ? 'Monthly amount (₹)' : 'Rate (₹)'} htmlFor="sl-base" error={errors.base_amount}><Input id="sl-base" type="number" min={0} value={f.base_amount} onChange={(e) => setF({ ...f, base_amount: e.target.value })} /></Field>
          <Field label="Commission on their work (%)" htmlFor="sl-com" error={errors.commission_percent} hint="Optional."><Input id="sl-com" type="number" min={0} max={100} value={f.commission_percent} onChange={(e) => setF({ ...f, commission_percent: e.target.value })} /></Field>
          <Field label="Extra per session (₹)" htmlFor="sl-sess" hint="Optional."><Input id="sl-sess" type="number" min={0} value={f.per_session_rate} onChange={(e) => setF({ ...f, per_session_rate: e.target.value })} /></Field>
          <Field label="From" htmlFor="sl-from" error={errors.effective_from}><Input id="sl-from" type="date" value={f.effective_from} onChange={(e) => setF({ ...f, effective_from: e.target.value })} /></Field>
        </div>
        <Field label="Note" htmlFor="sl-notes"><Textarea id="sl-notes" value={f.notes} maxLength={500} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      </div>
    </Modal>
  );
}

function AdvanceDialog({ person, base, methods, onClose }: { person: Person; base: string; methods: string[]; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [f, setF] = useState({ amount: '', given_on: today(), payment_method: methods[0] ?? 'cash', notes: '' });
  const [busy, setBusy] = useState(false);

  return (
    <Modal
      open
      onOpenChange={(open) => !open && onClose()}
      size="sm"
      title={`Advance to ${person.name}`}
      description="Recovered from a later month's pay."
      footer={
        <>
          <Button onClick={onClose} disabled={busy}>Cancel</Button>
          <Button tone="primary" loading={busy} disabled={Number(f.amount) < 1} onClick={() => router.post(`${base}/advances`, { clinic_employee_id: person.id, ...f, amount: Number(f.amount), notes: f.notes || null } as never, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose })}>Record advance</Button>
        </>
      }
    >
      <div className="space-y-4">
        {errors.payroll && <Notice tone="danger">{errors.payroll}</Notice>}
        <Field label="Amount (₹)" htmlFor="ad-amt" error={errors.amount}><Input id="ad-amt" type="number" min={1} value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Given on" htmlFor="ad-on"><Input id="ad-on" type="date" value={f.given_on} onChange={(e) => setF({ ...f, given_on: e.target.value })} /></Field>
          <Field label="Given by" htmlFor="ad-method"><Select id="ad-method" value={f.payment_method} onChange={(e) => setF({ ...f, payment_method: e.target.value })} options={methods.map((m) => ({ value: m, label: humanize(m) }))} /></Field>
        </div>
        <Field label="Note" htmlFor="ad-notes"><Textarea id="ad-notes" value={f.notes} maxLength={500} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
      </div>
    </Modal>
  );
}
