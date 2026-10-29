import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { usePage } from '@inertiajs/react';
import { Printer, RotateCcw, Scissors, Wallet } from 'lucide-react';
import { ClinicHttpError, ERROR_COPY, requestJson } from '../http';
import { useOwnerModeStepUp } from '../ownerModeStepUp';
import { StatusBadge } from '../ui/badges';
import { Button, buttonClasses } from '../ui/Button';
import { Field, Input, Select, Textarea } from '../ui/controls';
import { Modal } from '../ui/Modal';
import { Notice } from '../ui/Notice';
import { Skeleton } from '../ui/Spinner';
import { formatMoney } from '../ui/visuals';
import { when, requestKey } from './BillingFrame';
import { PaymentForm, paymentFor, paymentPayloadOf, paymentProblem, type PaymentMethodOption, type PaymentValue } from './PaymentForm';

/** One bill in full, as `CheckoutController::bill()` sends it. */
interface BillDetail {
  id: number;
  number: string;
  status: string;
  date: string | null;
  total: number;
  /** Collected less refunded: what a refund may return, at most. */
  paid: number;
  outstanding: number;
  patient: { name: string; uhid: string | null } | null;
  doctor: string | null;
  subtotal: number;
  discount: number;
  tax: number;
  items: { description: string; quantity: number; unit_price: number; discount: number; tax: number; amount: number }[];
  payments: { id: number; receipt: string; amount: number; method: string | null; reference: string | null; date: string | null }[];
  refunds: { number: string; amount: number; reason: string | null; date: string | null }[];
  payment_methods: PaymentMethodOption[];
  actions: { pay_url: string | null; refund_url: string | null; adjust_url: string | null; print_url: string; owner_mode_needed?: boolean };
}

type Action = 'pay' | 'refund' | 'adjust' | null;

/**
 * A bill opened from a Billing list: its lines, what was collected and
 * refunded, and only the actions the server offers this member on it — at
 * the bill's own branch, and for the owner, in Owner Mode. Every action is
 * sent with a request key, so a repeat records nothing twice, and the server
 * authorizes it again; the screen never reports success before the server.
 */
export function BillDetailDialog({ billId, url, onClose }: { billId: number; url: string; onClose: () => void }) {
  const { errors } = usePage<{ errors: Record<string, string> }>().props;
  const [bill, setBill] = useState<BillDetail | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [action, setAction] = useState<Action>(null);
  const stepUp = useOwnerModeStepUp();

  const load = useCallback(() => {
    setFailure(null);
    requestJson<BillDetail>('GET', url.replace('__ID__', String(billId)))
      .then(setBill)
      .catch((error) => setFailure(error instanceof ClinicHttpError ? ERROR_COPY[error.kind] : ERROR_COPY.server));
  }, [billId, url]);

  useEffect(load, [load]);

  const done = () => {
    setAction(null);
    load();
  };

  return (
    <Modal
      open
      size="lg"
      onOpenChange={(open) => !open && !stepUp.busy && onClose()}
      title={bill ? `Bill ${bill.number}` : 'Bill'}
      description={bill?.patient ? `${bill.patient.name}${bill.patient.uhid ? ` · ${bill.patient.uhid}` : ''}${bill.date ? ` · ${when(bill.date)}` : ''}` : undefined}
    >
      {failure ? (
        <Notice tone="danger" title="This bill could not be opened">
          {failure}
        </Notice>
      ) : bill === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <Amount label="Total" value={bill.total} />
            <Amount label="Collected (net)" value={bill.paid} />
            <Amount label="Outstanding" value={bill.outstanding} strong={bill.outstanding > 0} />
            <div className="flex items-center rounded-xl bg-[#F4F7FD] px-3 py-2.5 ring-1 ring-inset ring-[#E3E8F4]">
              <StatusBadge status={bill.status} />
            </div>
          </div>

          <section aria-labelledby="bill-lines">
            <h3 id="bill-lines" className={SECTION_TITLE}>Lines</h3>
            <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
              {bill.items.map((item, index) => (
                <li key={index} className="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                  <span className="min-w-0 truncate">
                    {item.description}
                    {item.quantity > 1 ? ` × ${item.quantity}` : ''}
                  </span>
                  <span className="shrink-0 tabular-nums">{formatMoney(item.amount)}</span>
                </li>
              ))}
            </ul>
            <p className="mt-1.5 text-right text-[12px] text-cx-text-3">
              Subtotal {formatMoney(bill.subtotal)} · Discount {formatMoney(bill.discount)} · Tax {formatMoney(bill.tax)}
            </p>
          </section>

          <History title="Payments" empty="Nothing collected yet." rows={bill.payments.map((p) => ({ key: `p${p.id}`, left: `${p.receipt}${p.method ? ` · ${p.method}` : ''}${p.reference ? ` · ${p.reference}` : ''}`, when: p.date, amount: p.amount }))} />
          <History title="Refunds" empty="No refunds on this bill." rows={bill.refunds.map((r) => ({ key: r.number, left: `${r.number}${r.reason ? ` · ${r.reason}` : ''}`, when: r.date, amount: -r.amount }))} />

          {bill.actions.owner_mode_needed && (
            <Notice tone="info" title="Refunds and adjustments need Owner Mode">
              Enter Owner Mode from your account menu, then open this bill again.
            </Notice>
          )}

          {action === null ? (
            <div className="flex flex-wrap gap-2 border-t border-cx-line pt-4">
              {bill.actions.pay_url && (
                <Button tone="primary" size="sm" icon={Wallet} onClick={() => setAction('pay')}>
                  Collect payment
                </Button>
              )}
              {bill.actions.refund_url && (
                <Button size="sm" icon={RotateCcw} onClick={() => setAction('refund')}>
                  Refund
                </Button>
              )}
              {bill.actions.adjust_url && (
                <Button size="sm" icon={Scissors} onClick={() => setAction('adjust')}>
                  Adjust balance
                </Button>
              )}
              <a href={bill.actions.print_url} target="_blank" rel="noreferrer" className={buttonClasses('ghost', 'sm')}>
                <Printer className="h-4 w-4" aria-hidden="true" />
                <span>Print</span>
              </a>
            </div>
          ) : (
            <ActionForm bill={bill} action={action} errors={errors} busy={stepUp.busy} onCancel={() => setAction(null)} submit={(url, data) => stepUp.submit({ method: 'post', url, data, onSuccess: done })} />
          )}
        </div>
      )}
      {stepUp.dialog}
    </Modal>
  );
}

/** A section's title, as the approved intake writes them: small, bold, uppercase, teal. */
const SECTION_TITLE = 'mb-2 text-[12.5px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong';

function Amount({ label, value, strong }: { label: string; value: number; strong?: boolean }) {
  return (
    <div className={strong ? 'rounded-xl bg-[#FFF8EB] px-3 py-2.5 ring-1 ring-inset ring-[#F5DFB3]' : 'rounded-xl bg-[#F4F7FD] px-3 py-2.5 ring-1 ring-inset ring-[#E3E8F4]'}>
      <p className={strong ? 'text-[16px] font-bold text-[#7A4B06] tabular-nums' : 'text-[16px] font-semibold text-cx-nav-ink tabular-nums'}>{formatMoney(value)}</p>
      <p className="text-[11.5px] font-semibold text-cx-nav-muted">{label}</p>
    </div>
  );
}

function History({ title, empty, rows }: { title: string; empty: string; rows: { key: string; left: string; when: string | null; amount: number }[] }) {
  return (
    <section aria-label={title}>
      <h3 className={SECTION_TITLE}>{title}</h3>
      {rows.length === 0 ? (
        <p className="text-[12.5px] text-cx-nav-muted">{empty}</p>
      ) : (
        <ul className="divide-y divide-cx-line rounded-xl border border-cx-line">
          {rows.map((row) => (
            <li key={row.key} className="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-3 py-2 text-[13px]">
              <span className="min-w-0 truncate">{row.left}</span>
              <span className="flex shrink-0 items-center gap-3">
                <span className="text-[12px] text-cx-text-3">{when(row.when)}</span>
                <span className="tabular-nums">{formatMoney(row.amount)}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/** The form for one action, with a request key made when it opened. */
function ActionForm({ bill, action, errors, busy, onCancel, submit }: {
  bill: BillDetail;
  action: Exclude<Action, null>;
  errors: Record<string, string>;
  busy: boolean;
  onCancel: () => void;
  submit: (url: string, data: Record<string, unknown>) => void;
}) {
  const [key] = useState(() => requestKey(action));
  const [payment, setPayment] = useState<PaymentValue>(() => paymentFor(bill.payment_methods, bill.outstanding));
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [method, setMethod] = useState('');
  const ceiling = action === 'refund' ? bill.paid : bill.outstanding;
  const serverError = errors.checkout ?? errors.billing ?? errors.amount ?? errors.reason;

  const problem = (() => {
    if (action === 'pay') {
      return paymentProblem(payment, bill.outstanding);
    }
    const value = Number(amount);
    if (!(value > 0)) {
      return 'Enter an amount.';
    }
    if (value > ceiling) {
      return `It can be at most ${formatMoney(ceiling)}.`;
    }
    if (reason.trim() === '') {
      return 'Say why.';
    }

    return null;
  })();

  const send = (event: FormEvent) => {
    event.preventDefault();
    if (problem !== null) {
      return;
    }
    if (action === 'pay' && bill.actions.pay_url) {
      submit(bill.actions.pay_url, { ...paymentPayloadOf(payment), idempotency_key: key });
    } else if (action === 'refund' && bill.actions.refund_url) {
      submit(bill.actions.refund_url, { amount: Number(amount), reason: reason.trim(), method: method || null, idempotency_key: key });
    } else if (action === 'adjust' && bill.actions.adjust_url) {
      submit(bill.actions.adjust_url, { amount: Number(amount), reason: reason.trim(), idempotency_key: key });
    }
  };

  const title = action === 'pay' ? 'Collect a payment' : action === 'refund' ? 'Record a refund' : 'Adjust the balance';
  const note =
    action === 'refund'
      ? 'Records money returned to the patient, up to what was collected. It does not move money: return it by the method you choose.'
      : action === 'adjust'
        ? 'A concession or write-off on what is still due. The bill’s lines stay as issued.'
        : null;

  return (
    <form onSubmit={send} className="space-y-4 border-t border-cx-line pt-4" noValidate>
      <div>
        <h3 className="text-[14px] font-semibold text-cx-nav-ink">{title}</h3>
        {note && <p className="mt-0.5 text-[12.5px] text-cx-text-3">{note}</p>}
      </div>
      {serverError && <Notice tone="danger">{serverError}</Notice>}
      {action === 'pay' ? (
        <PaymentForm value={payment} onChange={(patch) => setPayment((current) => ({ ...current, ...patch }))} methods={bill.payment_methods} due={bill.outstanding} idPrefix="bill-pay" />
      ) : (
        <>
          <Field label={`Amount (at most ${formatMoney(ceiling)})`} htmlFor="bill-action-amount" required>
            <Input id="bill-action-amount" inputMode="decimal" value={amount} onChange={(event) => setAmount(event.target.value)} autoFocus />
          </Field>
          {action === 'refund' && bill.payment_methods.length > 0 && (
            <Field label="Returned by" htmlFor="bill-action-method">
              <Select id="bill-action-method" value={method} onChange={(event) => setMethod(event.target.value)} options={bill.payment_methods} placeholder="As it was paid" />
            </Field>
          )}
          <Field label="Reason" htmlFor="bill-action-reason" required>
            <Textarea id="bill-action-reason" rows={2} maxLength={255} value={reason} onChange={(event) => setReason(event.target.value)} />
          </Field>
        </>
      )}
      <div className="flex justify-end gap-2">
        <Button type="button" onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
        <Button type="submit" tone={action === 'pay' ? 'primary' : 'danger'} loading={busy} disabled={problem !== null}>
          {action === 'pay' ? 'Record payment' : action === 'refund' ? 'Record refund' : 'Record adjustment'}
        </Button>
      </div>
    </form>
  );
}
