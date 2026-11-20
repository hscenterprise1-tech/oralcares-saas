import { useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Printer, Receipt, ReceiptText, Wallet } from 'lucide-react';
import { cn } from '@/lib/utils';
import { requestKey } from '@/clinic/billing/BillingFrame';
import { PaymentForm, paymentFor, paymentPayloadOf, paymentProblem, type PaymentMethodOption, type PaymentValue } from '@/clinic/billing/PaymentForm';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Field, Input } from '@/clinic/ui/controls';
import { formatMoney } from '@/clinic/ui/visuals';
import { IconChip, Panel, rise } from '@/pages/ClinicPortal/CommandCenter/sections';

/*
 * Checkout: one signed consultation turned into its bill, in the Command
 * Center's language. The lines and their amounts are the server's
 * (`EncounterCheckout`): the consultation fee when it was not charged at
 * check-in, the procedures done, and treatment or stage charges at the amount
 * agreed when the treatment started. Nothing already billed, and no session a
 * package paid for, is offered. Billing and the payment taken with it are one
 * request: a refused payment leaves no bill. Pay later raises the bill only.
 */

type Line = { key: string; description: string; quantity: number; unit_price: number; kind: 'consultation' | 'procedure' | 'agreed' };
type BillRow = { id: number; number: string | null; status: string; issued_at: string | null; total: number; outstanding: number; pay_url: string | null; print_url: string };
type EncounterBill = BillRow & {
  paid: number;
  items: { description: string | null; quantity: number; amount: number }[];
  payments: { receipt: string | null; amount: number; method: string | null; print_url: string }[];
};
interface CheckoutProps {
  encounter: { id: number; number: string | null; signed: boolean; doctor: string | null; follow_up_on: string | null };
  patient: { id: number; name: string; uhid: string | null; url: string };
  lines: Line[];
  package_covered: boolean;
  bills: EncounterBill[];
  open_bills: BillRow[];
  payment_methods: PaymentMethodOption[];
  links: { index: string | null; bill: string; book_follow_up: string | null };
  can: { bill: boolean; discount: boolean; collect: boolean };
}

const FOCUS = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/35 focus-visible:ring-offset-1';
const PRIMARY = cn('inline-flex h-10 items-center justify-center gap-2 rounded-full bg-[#1769AA] px-5 text-[13px] font-semibold text-white shadow-[0_1px_2px_rgba(23,59,96,0.18)] transition-colors hover:bg-[#135A93] disabled:opacity-60', FOCUS);
const QUIET = cn('inline-flex h-9 items-center gap-1.5 rounded-full border border-[#DCE6F3] bg-white px-3.5 text-[12.5px] font-semibold text-cx-nav-ink transition-colors hover:border-[#B9C6D8] hover:bg-[#F8FAFC]', FOCUS);
const KIND: Record<Line['kind'], { label: string; tone: string }> = {
  consultation: { label: 'Consultation', tone: 'bg-cx-blue-bg text-cx-blue-ink' },
  procedure: { label: 'Procedure done', tone: 'bg-cx-teal-bg text-cx-teal-ink' },
  agreed: { label: 'Agreed treatment charge', tone: 'bg-cx-violet-bg text-cx-violet-ink' },
};

function money(value: number): string {
  return formatMoney(value, 'INR');
}

function OpenBill({ bill, methods }: { bill: BillRow; methods: PaymentMethodOption[] }) {
  const [open, setOpen] = useState(false);
  const [payment, setPayment] = useState<PaymentValue>(() => paymentFor(methods, bill.outstanding));
  const [key, setKey] = useState(() => requestKey('pay'));
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const pay = () => {
    const wrong = paymentProblem(payment, bill.outstanding);
    if (wrong || !bill.pay_url) {
      setProblem(wrong);

      return;
    }
    setBusy(true);
    router.post(bill.pay_url, { ...paymentPayloadOf(payment), idempotency_key: key }, {
      preserveScroll: true,
      onError: (errors) => setProblem(Object.values(errors)[0] ?? 'The payment was not recorded.'),
      onSuccess: () => {
        setOpen(false);
        setKey(requestKey('pay'));
      },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <li className="py-2.5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-[13px]">
          <span className="font-semibold text-cx-nav-ink">Bill {bill.number ?? bill.id}</span>
          <span className="text-cx-text-2"> · {money(bill.outstanding)} due of {money(bill.total)}</span>
        </span>
        <span className="flex gap-2">
          <a href={bill.print_url} target="_blank" rel="noopener noreferrer" className={QUIET}>
            <Printer className="h-3.5 w-3.5" aria-hidden="true" /> Print
          </a>
          {bill.pay_url && (
            <button type="button" onClick={() => setOpen((value) => !value)} aria-expanded={open} className={QUIET}>
              Take payment
            </button>
          )}
        </span>
      </div>
      {open && (
        <div className="mt-3 space-y-3 rounded-xl bg-cx-slate-bg/60 p-3">
          <PaymentForm value={payment} onChange={(patch) => setPayment((value) => ({ ...value, ...patch }))} methods={methods} due={bill.outstanding} idPrefix={`pay-${bill.id}`} error={problem} />
          <button type="button" onClick={pay} disabled={busy} className={PRIMARY}>
            Record payment
          </button>
        </div>
      )}
    </li>
  );
}

export default function Checkout(props: CheckoutProps) {
  return (
    <ClinicPortalShell title="Checkout">
      <CheckoutPage {...props} />
    </ClinicPortalShell>
  );
}

function CheckoutPage({ encounter, patient, lines, package_covered: packageCovered, bills, open_bills: openBills, payment_methods: methods, links, can }: CheckoutProps) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [chosen, setChosen] = useState<string[]>(() => lines.map((line) => line.key));
  const [discount, setDiscount] = useState('');
  const [mode, setMode] = useState<'now' | 'later'>(can.collect && methods.length > 0 ? 'now' : 'later');
  const subtotal = useMemo(() => lines.filter((line) => chosen.includes(line.key)).reduce((sum, line) => sum + line.quantity * line.unit_price, 0), [lines, chosen]);
  const discountValue = can.discount ? Math.min(Number(discount) || 0, subtotal) : 0;
  const total = Math.round((subtotal - discountValue) * 100) / 100;
  const [payment, setPayment] = useState<PaymentValue>(() => paymentFor(methods, total));
  // The amount follows the total as lines are chosen; a part payment is typed after.
  useEffect(() => setPayment((value) => ({ ...value, amount: total > 0 ? String(total) : '' })), [total]);
  const [key, setKey] = useState(() => requestKey('checkout'));
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const toggle = (lineKey: string) => setChosen((list) => (list.includes(lineKey) ? list.filter((item) => item !== lineKey) : [...list, lineKey]));

  const submit = () => {
    if (chosen.length === 0) {
      setProblem('Choose at least one line to bill.');

      return;
    }
    const takeNow = mode === 'now' && total > 0;
    if (takeNow) {
      const wrong = paymentProblem(payment, total);
      if (wrong) {
        setProblem(wrong);

        return;
      }
    }
    setProblem(null);
    setBusy(true);
    router.post(
      links.bill,
      {
        // The server's own amounts, unchanged: the checkout never re-prices a line.
        prices: Object.fromEntries(lines.filter((line) => chosen.includes(line.key)).map((line) => [line.key, line.unit_price])),
        discount: discountValue > 0 ? discountValue : null,
        payment: takeNow ? paymentPayloadOf(payment) : null,
        idempotency_key: key,
      },
      {
        preserveScroll: true,
        onError: (failure) => setProblem(Object.values(failure)[0] ?? 'The bill was not raised.'),
        onSuccess: () => setKey(requestKey('checkout')),
        onFinish: () => setBusy(false),
      },
    );
  };

  return (
    <div className="mx-auto w-full max-w-[1280px] space-y-5">
      <header className={cn('relative isolate overflow-hidden rounded-2xl border border-white/80 px-6 py-5 shadow-cx-card sm:px-8', rise(0).className)}>
        <div className="absolute inset-0 -z-10 bg-[linear-gradient(118deg,#E2F6F5_0%,#E8F0FF_50%,#EFE9FE_100%)]" aria-hidden="true" />
        <span className="absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-[#2CC6B4] via-[#4F7FEA] to-[#8B6CF0]" aria-hidden="true" />
        {links.index && (
          <Link href={links.index} className={cn('mb-2 inline-flex items-center gap-1 text-[12.5px] font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
            <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" /> Billing
          </Link>
        )}
        <h1 className="text-[24px] font-semibold leading-tight tracking-tight text-cx-nav-ink [overflow-wrap:anywhere]">Checkout · {patient.name}</h1>
        <p className="mt-1 text-[13.5px] text-cx-text-2">
          {[patient.uhid, encounter.number ? `Consultation ${encounter.number}` : null, encounter.doctor].filter(Boolean).join(' · ')}{' '}
          <Link href={patient.url} className={cn('font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
            Patient record
          </Link>
        </p>
      </header>

      {(errors?.checkout || errors?.payment) && (
        <p role="alert" className="rounded-xl bg-cx-rose-bg px-4 py-3 text-[13px] font-medium text-cx-rose-ink">
          {errors.checkout ?? errors.payment}
        </p>
      )}

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
        <Panel id="to-bill" title="To bill" caption="What this consultation still owes, at the clinic's own amounts" icon={ReceiptText} tone="blue">
          {!encounter.signed ? (
            <p className="rounded-xl bg-cx-amber-bg px-4 py-3 text-[13px] text-cx-amber-ink">The doctor has not signed this consultation yet. It is billed once it is signed.</p>
          ) : lines.length === 0 ? (
            <p className="rounded-xl bg-cx-slate-bg px-4 py-6 text-center text-[13px] text-cx-text-2">
              Nothing is left to bill for this consultation.{packageCovered ? ' The session was paid from the patient’s package.' : ''}
            </p>
          ) : (
            <div className="space-y-4">
              <ul className="divide-y divide-cx-line">
                {lines.map((line) => (
                  <li key={line.key}>
                    <label className="flex cursor-pointer items-start gap-3 py-2.5">
                      <input type="checkbox" checked={chosen.includes(line.key)} onChange={() => toggle(line.key)} className="mt-1 h-4 w-4 rounded border-cx-line text-[#1769AA]" />
                      <span className="min-w-0 flex-1">
                        <span className="block text-[13.5px] font-semibold text-cx-nav-ink [overflow-wrap:anywhere]">{line.description}</span>
                        <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[12px] text-cx-text-2">
                          <span className={cn('rounded-full px-2 py-0.5 font-semibold', KIND[line.kind].tone)}>{KIND[line.kind].label}</span>
                          {line.quantity > 1 && <span>{line.quantity} × {money(line.unit_price)}</span>}
                        </span>
                      </span>
                      <span className="shrink-0 text-[14px] font-semibold tabular-nums text-cx-nav-ink">{money(line.quantity * line.unit_price)}</span>
                    </label>
                  </li>
                ))}
              </ul>
              {packageCovered && <p className="text-[12.5px] text-cx-text-2">The session itself was paid from the patient’s package and is not charged again.</p>}
              {can.discount && (
                <Field label="Discount" htmlFor="checkout-discount" hint="Off the whole bill">
                  <Input id="checkout-discount" inputMode="decimal" value={discount} onChange={(event) => setDiscount(event.target.value.replace(/[^0-9.]/g, ''))} />
                </Field>
              )}
              <div className="flex items-center justify-between rounded-xl bg-gradient-to-br from-[#EEF5FF] to-[#E2ECFD] px-4 py-3">
                <span className="text-[13px] font-semibold text-cx-blue-ink">Total to bill</span>
                <span className="text-[20px] font-semibold tabular-nums text-cx-nav-ink">{money(total)}</span>
              </div>

              {can.bill && (
                <div className="space-y-3">
                  {total > 0 && (
                    <div role="radiogroup" aria-label="When is it paid" className="inline-flex rounded-full bg-cx-slate-bg p-1">
                      {can.collect && methods.length > 0 && (
                        <button type="button" role="radio" aria-checked={mode === 'now'} onClick={() => setMode('now')} className={cn('h-8 rounded-full px-4 text-[12.5px] font-semibold', FOCUS, mode === 'now' ? 'bg-white text-cx-blue-ink shadow-xs' : 'text-cx-text-2')}>
                          Take payment now
                        </button>
                      )}
                      <button type="button" role="radio" aria-checked={mode === 'later'} onClick={() => setMode('later')} className={cn('h-8 rounded-full px-4 text-[12.5px] font-semibold', FOCUS, mode === 'later' ? 'bg-white text-cx-blue-ink shadow-xs' : 'text-cx-text-2')}>
                        Pay later
                      </button>
                    </div>
                  )}
                  {mode === 'now' && total > 0 && (
                    <PaymentForm
                      value={payment}
                      onChange={(patch) => setPayment((value) => ({ ...value, ...patch }))}
                      methods={methods}
                      due={total}
                      idPrefix="checkout-pay"
                    />
                  )}
                  {problem && (
                    <p role="alert" className="text-[12.5px] font-medium text-cx-rose-ink">
                      {problem}
                    </p>
                  )}
                  <button type="button" onClick={submit} disabled={busy || chosen.length === 0} className={PRIMARY}>
                    <Wallet className="h-4 w-4" strokeWidth={1.75} aria-hidden="true" />
                    {mode === 'now' && total > 0 ? 'Bill and take payment' : total > 0 ? 'Raise bill, pay later' : 'Raise bill'}
                  </button>
                </div>
              )}
            </div>
          )}
        </Panel>

        <div className="min-w-0 space-y-5">
          <Panel id="billed" title="Billed for this consultation" icon={Receipt} tone="mint">
            {bills.length === 0 ? (
              <p className="text-[13px] text-cx-text-2">No bill raised yet.</p>
            ) : (
              <ul className="space-y-3">
                {bills.map((bill) => (
                  <li key={bill.id} className="rounded-xl bg-cx-slate-bg/60 p-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="text-[13px] font-semibold text-cx-nav-ink">Bill {bill.number ?? bill.id}</span>
                      <a href={bill.print_url} target="_blank" rel="noopener noreferrer" className={QUIET}>
                        <Printer className="h-3.5 w-3.5" aria-hidden="true" /> Print bill
                      </a>
                    </div>
                    <p className="mt-1 text-[12.5px] text-cx-text-2">
                      {money(bill.total)} · paid {money(bill.paid)}
                      {bill.outstanding > 0 ? ` · ${money(bill.outstanding)} due` : ''}
                    </p>
                    {bill.payments.length > 0 && (
                      <ul className="mt-2 space-y-1">
                        {bill.payments.map((payment, index) => (
                          <li key={payment.receipt ?? index} className="flex flex-wrap items-center justify-between gap-2 text-[12.5px]">
                            <span className="text-cx-text-2">{[payment.receipt, payment.method].filter(Boolean).join(' · ')}</span>
                            <span className="flex items-center gap-2">
                              <span className="font-semibold tabular-nums text-cx-green-ink">{money(payment.amount)}</span>
                              <a href={payment.print_url} target="_blank" rel="noopener noreferrer" className={cn('font-medium text-cx-primary hover:text-cx-primary-strong', FOCUS)}>
                                Receipt
                              </a>
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                    {bill.outstanding > 0 && bill.pay_url && (
                      <ul>
                        <OpenBill bill={bill} methods={methods} />
                      </ul>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Panel>

          {openBills.length > 0 && (
            <Panel id="open-bills" title="Other open bills" caption="This patient, at this branch" icon={Wallet} tone="amber">
              <ul className="divide-y divide-cx-line">
                {openBills.map((bill) => (
                  <OpenBill key={bill.id} bill={bill} methods={methods} />
                ))}
              </ul>
            </Panel>
          )}

          {links.book_follow_up && encounter.follow_up_on && (
            <div className="flex items-center gap-3 rounded-2xl bg-white p-4 shadow-cx-card">
              <IconChip icon={Receipt} tone="teal" size="sm" variant="soft" />
              <p className="min-w-0 flex-1 text-[13px] text-cx-text-2">Follow-up advised on {encounter.follow_up_on}.</p>
              <Link href={links.book_follow_up} className={QUIET}>
                Book
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
