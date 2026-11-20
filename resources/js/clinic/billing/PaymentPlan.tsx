import { useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { Button } from '../ui/Button';
import { Field, Input, Select } from '../ui/controls';
import { Notice } from '../ui/Notice';
import { formatMoney } from '../ui/visuals';

/** One instalment of a bill's payment plan, with where it stands today (`BillInstalments::forBill`). */
export interface InstalmentRow {
  id: number;
  sequence: number;
  due_on: string;
  amount: number;
  paid: number;
  remaining: number;
  state: 'paid' | 'overdue' | 'partial' | 'upcoming';
}

const STATE: Record<InstalmentRow['state'], { label: string; className: string }> = {
  paid: { label: 'Paid', className: 'bg-cx-green-bg text-cx-green-ink' },
  overdue: { label: 'Overdue', className: 'bg-cx-rose-bg text-cx-rose-ink' },
  partial: { label: 'Part paid', className: 'bg-cx-amber-bg text-cx-amber-ink' },
  upcoming: { label: 'Due', className: 'bg-cx-slate-bg text-cx-slate-ink' },
};

/** A due date as a day (it carries no time: `when()` would show a time of day). */
const day = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
const isoDay = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

/** The balance split into `count` instalments, the last taking what rounding leaves, dated `every` days apart (30 = monthly) from `first`. */
export function splitPlan(outstanding: number, count: number, first: string, every: number): { due_on: string; amount: number }[] {
  const paise = Math.round(outstanding * 100);
  const base = Math.floor(paise / count);
  const start = new Date(`${first}T00:00:00`);

  return Array.from({ length: count }, (_, index) => {
    const due = new Date(start);
    if (every === 30) {
      due.setMonth(start.getMonth() + index);
    } else {
      due.setDate(start.getDate() + index * every);
    }

    return { due_on: isoDay(due), amount: (index === count - 1 ? paise - base * (count - 1) : base) / 100 };
  });
}

/**
 * A bill's payment plan: the instalments the patient agreed to and where each stands, and (when the server offers it) a way to
 * make or remake the plan by splitting the balance. Payments are still taken on the bill; they fill the instalments in order.
 */
export function PaymentPlan({ rows, outstanding, url, onChanged }: { rows: InstalmentRow[]; outstanding: number; url: string | null; onChanged: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [count, setCount] = useState('3');
  const [every, setEvery] = useState('30');
  const [first, setFirst] = useState(() => isoDay(new Date(Date.now() + 7 * 86_400_000)));
  const preview = useMemo(() => splitPlan(outstanding, Number(count), first, Number(every)), [outstanding, count, first, every]);

  if (rows.length === 0 && !url) {
    return null;
  }

  const send = (method: 'post' | 'delete') => {
    if (!url) {
      return;
    }
    router[method](url, method === 'post' ? ({ instalments: preview } as never) : {}, {
      preserveScroll: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onSuccess: () => {
        setEditing(false);
        onChanged();
      },
    });
  };

  return (
    <section aria-label="Payment plan">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h3 className="flex items-center gap-1.5 text-[12.5px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">
          <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" /> Payment plan
        </h3>
        {url && !editing && (
          <Button size="sm" onClick={() => setEditing(true)}>
            {rows.length > 0 ? 'Change plan' : 'Split into instalments'}
          </Button>
        )}
      </div>

      {rows.length > 0 && (
        <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-3 py-2 text-[13px]">
              <span className="flex items-center gap-2">
                <span className="font-semibold text-cx-nav-ink">#{row.sequence}</span>
                <span className="text-cx-text-3">{day(row.due_on)}</span>
              </span>
              <span className="flex items-center gap-3">
                <span className={`rounded-full px-2 py-0.5 text-[11.5px] font-semibold ${STATE[row.state].className}`}>{STATE[row.state].label}</span>
                <span className="tabular-nums">{row.state === 'paid' ? formatMoney(row.amount) : `${formatMoney(row.remaining)} of ${formatMoney(row.amount)}`}</span>
              </span>
            </li>
          ))}
        </ul>
      )}

      {editing && (
        <div className="mt-3 space-y-3 rounded-xl border border-cx-line bg-[#F8FAFD] p-3">
          {errors.instalments && <Notice tone="danger">{errors.instalments}</Notice>}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <Field label="Instalments" htmlFor="plan-count">
              <Select id="plan-count" value={count} onChange={(event) => setCount(event.target.value)} options={Array.from({ length: 11 }, (_, i) => ({ value: String(i + 2), label: String(i + 2) }))} />
            </Field>
            <Field label="First due" htmlFor="plan-first">
              <Input id="plan-first" type="date" value={first} min={isoDay(new Date())} onChange={(event) => setFirst(event.target.value)} />
            </Field>
            <Field label="Then every" htmlFor="plan-every">
              <Select id="plan-every" value={every} onChange={(event) => setEvery(event.target.value)} options={[{ value: '7', label: 'Week' }, { value: '14', label: '2 weeks' }, { value: '30', label: 'Month' }]} />
            </Field>
          </div>
          <ul className="space-y-1 text-[12.5px] text-cx-text-2">
            {preview.map((row, index) => (
              <li key={row.due_on} className="flex justify-between"><span>#{index + 1} · {day(row.due_on)}</span><span className="tabular-nums">{formatMoney(row.amount)}</span></li>
            ))}
          </ul>
          <div className="flex flex-wrap justify-end gap-2">
            {rows.length > 0 && (
              <Button size="sm" tone="danger" onClick={() => send('delete')} disabled={busy}>
                Remove plan
              </Button>
            )}
            <Button size="sm" onClick={() => setEditing(false)} disabled={busy}>
              Cancel
            </Button>
            <Button size="sm" tone="primary" onClick={() => send('post')} loading={busy} disabled={!first}>
              Save plan
            </Button>
          </div>
        </div>
      )}
    </section>
  );
}
