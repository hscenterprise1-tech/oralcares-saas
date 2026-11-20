import { useCallback, useEffect, useState } from 'react';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { ClinicHttpError, ERROR_COPY, requestJson } from '@/clinic/http';
import { DsButton, DsDialog, InlineNotice } from '@/clinic/ds';
import { formatMoney } from '@/clinic/ui/visuals';

/** `DayClosing::summary`: the ledger's day by method, and its closing if it has one. */
type Closing = { id: number; date: string; expected_cash: number; counted_cash: number; difference: number; notes: string | null; by: string | null; closed_at: string | null };
type Summary = {
  date: string;
  methods: { method: string; label: string; collected: number; returned: number; net: number; count: number }[];
  totals: { collected: number; returned: number; net: number; payments: number };
  expected_cash: number;
  closing: Closing | null;
  after_closing: number;
  recent: Closing[];
};

const isoDay = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const dayName = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
/** The last week, today first: a day missed can still be closed. */
const DAYS = Array.from({ length: 7 }, (_, i) => isoDay(new Date(Date.now() - i * 86_400_000)));

/**
 * Close the business day at the desk: what the ledger says came in by each method, the cash counted against what it expects,
 * and the reason when they differ. It records the count; it moves no money and changes no bill.
 */
export function DayClosingDialog({ url, currency, onClose }: { url: string; currency: string; onClose: () => void }) {
  const money = (value: number) => formatMoney(value, currency);
  const [date, setDate] = useState(DAYS[0]);
  const [summary, setSummary] = useState<Summary | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [counted, setCounted] = useState('');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const load = useCallback(() => {
    setSummary(null);
    setFailure(null);
    requestJson<Summary>('GET', url, { query: { date } })
      .then((data) => {
        setSummary(data);
        setCounted('');
        setNotes('');
        setProblem(null);
      })
      .catch((error) => setFailure(error instanceof ClinicHttpError ? ERROR_COPY[error.kind] : ERROR_COPY.server));
  }, [url, date]);
  useEffect(load, [load]);

  const value = counted.trim() === '' ? null : Number(counted);
  const difference = summary && value !== null && !Number.isNaN(value) ? Math.round((value - summary.expected_cash) * 100) / 100 : null;
  const reasonMissing = difference !== null && difference !== 0 && notes.trim() === '';

  const close = () => {
    if (!summary || value === null || Number.isNaN(value) || value < 0) {
      setProblem('Enter the cash counted.');

      return;
    }
    if (reasonMissing) {
      setProblem('Say why the cash counted does not match.');

      return;
    }
    setBusy(true);
    setProblem(null);
    requestJson<{ summary: Summary }>('POST', url, { body: { date, counted_cash: value, notes: notes.trim() || null } })
      .then((data) => setSummary(data.summary))
      .catch((error) => {
        const body = error instanceof ClinicHttpError ? (error.body as { message?: string } | null) : null;
        setProblem(body?.message ?? (error instanceof ClinicHttpError ? ERROR_COPY[error.kind] : ERROR_COPY.server));
      })
      .finally(() => setBusy(false));
  };

  return (
    <DsDialog
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title="Close the day"
      description="The ledger’s figures for the day, and the cash you counted."
      footer={
        <>
          <DsButton variant="secondary" onClick={onClose} disabled={busy}>
            {summary?.closing ? 'Done' : 'Cancel'}
          </DsButton>
          {summary && !summary.closing && (
            <DsButton variant="primary" onClick={close} loading={busy}>
              Close the day
            </DsButton>
          )}
        </>
      }
    >
      <div className="space-y-4 text-[13.5px] text-[#173B60]">
        <label className="block text-[12px] font-semibold uppercase tracking-wide text-[#64748B]">
          Day
          <select value={date} onChange={(event) => setDate(event.target.value)} disabled={busy} className="mt-1 block h-10 w-full rounded-xl border border-[#D5DDE7] bg-white px-3 text-[14px] normal-case tracking-normal text-[#173B60]">
            {DAYS.map((day, index) => (
              <option key={day} value={day}>{index === 0 ? `Today · ${dayName(day)}` : dayName(day)}</option>
            ))}
          </select>
        </label>

        {failure && <InlineNotice tone="rose">{failure}</InlineNotice>}
        {!failure && summary === null && <p className="text-[#64748B]">Loading the day…</p>}

        {summary && (
          <>
            {summary.methods.length === 0 ? (
              <p className="rounded-xl bg-[#F6F8FC] px-3.5 py-3 text-[#64748B]">Nothing was collected on this day.</p>
            ) : (
              <table className="w-full text-left">
                <thead>
                  <tr className="text-[11.5px] uppercase tracking-wide text-[#64748B]">
                    <th className="py-1.5 font-semibold">Method</th>
                    <th className="py-1.5 text-right font-semibold">In</th>
                    <th className="py-1.5 text-right font-semibold">Returned</th>
                    <th className="py-1.5 text-right font-semibold">Net</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#EEF2F7]">
                  {summary.methods.map((row) => (
                    <tr key={row.method}>
                      <td className="py-2 font-semibold">{row.label} <span className="font-normal text-[#64748B]">· {row.count}</span></td>
                      <td className="py-2 text-right tabular-nums">{money(row.collected)}</td>
                      <td className="py-2 text-right tabular-nums">{row.returned > 0 ? money(row.returned) : '—'}</td>
                      <td className="py-2 text-right font-semibold tabular-nums">{money(row.net)}</td>
                    </tr>
                  ))}
                  <tr>
                    <td className="py-2 font-bold">Day’s total</td>
                    <td className="py-2 text-right font-bold tabular-nums">{money(summary.totals.collected)}</td>
                    <td className="py-2 text-right font-bold tabular-nums">{summary.totals.returned > 0 ? money(summary.totals.returned) : '—'}</td>
                    <td className="py-2 text-right font-bold tabular-nums">{money(summary.totals.net)}</td>
                  </tr>
                </tbody>
              </table>
            )}

            {summary.closing ? (
              <div className="space-y-2">
                <div className="flex items-start gap-2 rounded-xl bg-[#E2F5EC] px-3.5 py-3 text-[#1E6F51]">
                  <CircleCheck className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                  <p>
                    Closed{summary.closing.by ? ` by ${summary.closing.by}` : ''}. Cash expected {money(summary.closing.expected_cash)}, counted {money(summary.closing.counted_cash)}
                    {summary.closing.difference !== 0 ? `, ${summary.closing.difference > 0 ? 'over' : 'short'} by ${money(Math.abs(summary.closing.difference))}` : ', no difference'}.
                    {summary.closing.notes ? ` “${summary.closing.notes}”` : ''}
                  </p>
                </div>
                {summary.after_closing > 0 && (
                  <div className="flex items-start gap-2 rounded-xl bg-[#FFF3DC] px-3.5 py-3 text-[#9A4A06]">
                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <p>{summary.after_closing} {summary.after_closing === 1 ? 'payment was' : 'payments were'} recorded for this day after it was closed, so this closing does not cover {summary.after_closing === 1 ? 'it' : 'them'}.</p>
                  </div>
                )}
              </div>
            ) : (
              <div className="space-y-3">
                <p className="rounded-xl bg-[#F6F8FC] px-3.5 py-3">
                  Cash the ledger expects in the drawer: <strong className="tabular-nums">{money(summary.expected_cash)}</strong>
                </p>
                <label className="block text-[12px] font-semibold uppercase tracking-wide text-[#64748B]">
                  Cash counted
                  <input inputMode="decimal" value={counted} onChange={(event) => setCounted(event.target.value)} disabled={busy} className="mt-1 block h-11 w-full rounded-xl border border-[#D5DDE7] bg-white px-3 text-[15px] normal-case tracking-normal text-[#173B60]" />
                </label>
                {difference !== null && (
                  <p className={difference === 0 ? 'font-semibold text-[#1E6F51]' : 'font-semibold text-[#B42318]'}>
                    {difference === 0 ? 'The cash matches.' : `${difference > 0 ? 'Over' : 'Short'} by ${money(Math.abs(difference))}`}
                  </p>
                )}
                {difference !== null && difference !== 0 && (
                  <label className="block text-[12px] font-semibold uppercase tracking-wide text-[#64748B]">
                    Why does it differ?
                    <textarea rows={2} maxLength={500} value={notes} onChange={(event) => setNotes(event.target.value)} disabled={busy} className="mt-1 block w-full rounded-xl border border-[#D5DDE7] bg-white px-3 py-2 text-[14px] normal-case tracking-normal text-[#173B60]" />
                  </label>
                )}
                {problem && <InlineNotice tone="rose">{problem}</InlineNotice>}
              </div>
            )}
          </>
        )}
      </div>
    </DsDialog>
  );
}
