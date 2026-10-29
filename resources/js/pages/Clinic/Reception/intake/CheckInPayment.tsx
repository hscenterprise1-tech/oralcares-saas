import { useEffect, useRef, useState } from 'react';
import { CalendarClock, CheckCircle2, IndianRupee, Printer } from 'lucide-react';
import { Button, Modal, Notice, PaymentForm as PaymentEntry, paymentPayloadOf, paymentProblem } from '@/clinic';
import { ClinicHttpError, errorKind, requestJson } from '@/clinic/http';
import { cn } from '@/lib/utils';
import { requestKey } from './PatientFields';
import { chargedAtCheckIn, type BookingMatch, type CheckInResult, type IntakeConfig, type PaymentForm, type PractitionerOption } from './types';

const money = (amount: number) => `₹${amount.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;

export function emptyPayment(): PaymentForm {
  return { collect: false, method: '', amount: '', reference: '' };
}

/**
 * What checking in will charge, said before anything is saved: the
 * doctor's consultation fee when the clinic charges it at check-in and the
 * visit's module does (Physiotherapy never does). Information only — money
 * is taken after the check-in, in its own Collect Payment step.
 */
export function ChargeNotice({ config, doctor, module }: { config: IntakeConfig; doctor: PractitionerOption | null; module: string | null }) {
  if (!doctor?.fee || !chargedAtCheckIn(config, doctor, module)) {
    return null;
  }

  return (
    <p className="flex flex-wrap items-center gap-1.5 rounded-2xl border border-clinic-border px-4 py-3 text-[12.5px] text-clinic-ink">
      <IndianRupee className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />
      <span className="font-bold">Consultation {money(doctor.fee)}</span>
      <span className="text-clinic-secondary">· charged at check-in{config.payment.can_collect ? '; you can collect it right after' : '; billing collects it'}</span>
    </p>
  );
}

/**
 * Collect Payment, after a successful check-in, for what the check-in
 * charged. The lines, the reason and the amount owed are the server's; the
 * payment goes to the existing billing payment endpoint with a request key
 * made when this opened, so a double click or retry is recorded once.
 * "Collect later" closes it: the bill stays owing, nothing is undone, no
 * payment is invented. A failed payment leaves the check-in as it is.
 */
export function CollectPaymentDialog({
  open,
  billing,
  methods,
  onPaid,
  onClose,
}: {
  open: boolean;
  billing: NonNullable<CheckInResult['billing']>;
  methods: IntakeConfig['payment']['methods'];
  onPaid: (billing: NonNullable<CheckInResult['billing']>) => void;
  onClose: () => void;
}) {
  const [value, setValue] = useState<PaymentForm>(() => ({ ...emptyPayment(), collect: true, amount: String(billing.outstanding), method: methods[0]?.value ?? '' }));
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const key = useRef(requestKey());
  const sending = useRef(false);

  // Each opening is a new payment attempt with its own key and the amount now owed.
  useEffect(() => {
    if (open) {
      key.current = requestKey();
      setValue({ ...emptyPayment(), collect: true, amount: String(billing.outstanding), method: methods[0]?.value ?? '' });
      setError(null);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const collect = async () => {
    if (sending.current || !billing.pay_url) {
      return;
    }
    const problem = paymentProblem(value, billing.outstanding);
    setError(problem);
    if (problem) {
      return;
    }
    sending.current = true;
    setBusy(true);
    try {
      const response = await requestJson<{ billing: NonNullable<CheckInResult['billing']> }>('POST', billing.pay_url, { body: { ...paymentPayloadOf(value), idempotency_key: key.current } });
      onPaid(response.billing);
    } catch (failure) {
      const message = failure instanceof ClinicHttpError ? ((failure.body as { message?: string } | null)?.message ?? null) : null;
      setError(errorKind(failure) === 'forbidden' ? 'You cannot take payments.' : (message ?? 'The payment was not recorded. Check the connection and try again; the check-in is saved.'));
    } finally {
      sending.current = false;
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onOpenChange={(next) => !next && !busy && onClose()}
      size="md"
      title="Collect payment"
      description="The patient is checked in. Take the payment now, or leave it owing."
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy} className="mr-auto">
            Collect later
          </Button>
          <Button tone="primary" icon={CheckCircle2} onClick={() => void collect()} loading={busy} loadingLabel="Recording…" disabled={busy} data-autofocus>
            Record {value.amount && Number(value.amount) > 0 ? money(Number(value.amount)) : 'payment'}
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        <div className="rounded-2xl border border-clinic-border p-4">
          <p className="text-[12.5px] text-clinic-secondary">{billing.reason}</p>
          <dl className="mt-2 divide-y divide-clinic-border text-[13px]">
            {billing.lines.map((line, index) => (
              <div key={index} className="flex justify-between gap-3 py-1.5">
                <dt className="text-clinic-ink">
                  {line.description}
                  {line.quantity > 1 ? ` × ${line.quantity}` : ''}
                </dt>
                <dd className="font-semibold tabular-nums text-clinic-ink">{money(line.amount)}</dd>
              </div>
            ))}
            <div className="flex justify-between gap-3 py-1.5">
              <dt className="font-bold text-clinic-ink">Owing · bill {billing.billing_number}</dt>
              <dd className="font-bold tabular-nums text-clinic-ink">{money(billing.outstanding)}</dd>
            </div>
          </dl>
        </div>
        <PaymentEntry value={value} onChange={(patch) => { setValue((current) => ({ ...current, ...patch })); setError(null); }} methods={methods} due={billing.outstanding} idPrefix="collect" error={error} methodLabel="Payment method" />
        {methods.length === 0 && <Notice tone="warning">This clinic has no payment methods set up for the desk.</Notice>}
      </div>
    </Modal>
  );
}

/** After saving: the charge and, only when money was taken, the receipt. Never a receipt for an unpaid bill. */
export function BillingResult({ result }: { result: CheckInResult }) {
  const billing = result.billing;
  if (!billing) {
    return null;
  }
  const receipt = billing.receipt;

  return (
    <div className={cn('w-full max-w-md rounded-2xl p-4 text-left ring-1', receipt ? 'bg-gradient-to-br from-[#F2FAF8] to-[#F3F7FD] ring-[#CDEBE5]' : 'bg-[#F7F9FD] ring-[#E3E8F4]')}>
      <p className="flex items-center gap-1.5 text-[13px] font-bold text-clinic-ink">
        {receipt ? <CheckCircle2 className="h-4 w-4 text-clinic-success" aria-hidden="true" /> : <IndianRupee className="h-4 w-4" aria-hidden="true" />}
        {receipt ? 'Payment successful' : 'Consultation charged'}
      </p>
      <dl className="mt-2 grid grid-cols-[110px_minmax(0,1fr)] gap-x-3 gap-y-1 text-[12.5px]">
        {receipt && (
          <>
            <dt className="text-clinic-secondary">Receipt</dt>
            <dd className="font-semibold text-clinic-ink">{receipt.number}</dd>
            <dt className="text-clinic-secondary">Paid</dt>
            <dd className="font-semibold text-clinic-ink">
              {money(receipt.amount)} · {receipt.method}
            </dd>
          </>
        )}
        <dt className="text-clinic-secondary">Bill</dt>
        <dd className="text-clinic-ink">
          {billing.billing_number} · {money(billing.total)}
        </dd>
        <dt className="text-clinic-secondary">Outstanding</dt>
        <dd className={cn('font-semibold', billing.outstanding > 0 ? 'text-amber-800' : 'text-clinic-ink')}>{money(billing.outstanding)}</dd>
      </dl>
      <div className="mt-3 flex flex-wrap gap-2">
        {receipt?.print_url && (
          <a href={receipt.print_url} target="_blank" rel="noreferrer" className={cn(linkPill, 'border-[#1769AA] bg-[#1769AA] text-white hover:bg-[#135A93]')}>
            <Printer className="h-3.5 w-3.5" aria-hidden="true" />
            Print receipt
          </a>
        )}
        {billing.print_url && (
          <a href={billing.print_url} target="_blank" rel="noreferrer" className={cn(linkPill, 'border-clinic-border bg-clinic-surface text-clinic-ink hover:border-clinic-border-strong')}>
            <Printer className="h-3.5 w-3.5" aria-hidden="true" />
            {receipt ? 'A4 bill' : 'View bill'}
          </a>
        )}
      </div>
      {receipt?.messages_url && <PaymentMessagesStatus url={receipt.messages_url} retryUrl={receipt.retry_url} />}
    </div>
  );
}

const linkPill = 'inline-flex min-h-10 items-center gap-1.5 rounded-full border px-4 text-[13px] font-semibold';

type PaymentMessage = { kind: string; label: string; state: string; detail: string | null; at: string | null; can_retry: boolean };
type PaymentMessagesPayload = { whatsapp: { state: string; label: string }; messages: PaymentMessage[]; message?: string };

const MESSAGE_STATE: Record<string, { label: string; tone: string }> = {
  waiting: { label: 'Queued — not sent yet', tone: 'text-clinic-secondary' },
  queued: { label: 'Queued on WhatsApp — not sent yet', tone: 'text-clinic-secondary' },
  sent: { label: 'Sent on WhatsApp', tone: 'text-clinic-ink' },
  delivered: { label: 'Delivered on WhatsApp', tone: 'text-emerald-800' },
  read: { label: 'Read on WhatsApp', tone: 'text-emerald-800' },
  failed: { label: 'WhatsApp failed', tone: 'text-red-700' },
  skipped: { label: 'Not sent on WhatsApp', tone: 'text-amber-800' },
  not_whatsapp: { label: 'Not sent on WhatsApp', tone: 'text-amber-800' },
};

/**
 * Where the patient's payment messages stand, exactly as recorded — queued
 * is never shown as sent. Read when the receipt appears and on Refresh; a
 * failed WhatsApp message can be sent again (the same message, never a
 * second one). The payment itself is never affected by any of this.
 */
function PaymentMessagesStatus({ url, retryUrl }: { url: string; retryUrl: string | null }) {
  const [data, setData] = useState<PaymentMessagesPayload | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async (method: 'GET' | 'POST' = 'GET', target = url) => {
    setBusy(true);
    try {
      const response = await requestJson<PaymentMessagesPayload>(method, target);
      setData(response);
      setProblem(response.message ?? null);
    } catch {
      setProblem('Could not read the message status. The payment is recorded.');
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [url]);

  const retryable = !!retryUrl && (data?.messages.some((message) => message.can_retry) ?? false);

  return (
    <div className="mt-3 border-t border-clinic-border pt-3" aria-live="polite">
      <p className="text-[12px] font-bold uppercase tracking-wide text-clinic-secondary">Patient messages</p>
      {data && data.messages.length === 0 && <p className="mt-1 text-[12.5px] text-clinic-secondary">No message was queued for this payment.</p>}
      <ul className="mt-1 space-y-1">
        {data?.messages.map((message) => {
          const state = MESSAGE_STATE[message.state] ?? { label: message.state, tone: 'text-clinic-ink' };

          return (
            <li key={message.kind} className="text-[12.5px]">
              <span className="font-semibold text-clinic-ink">{message.label}:</span> <span className={cn('font-semibold', state.tone)}>{state.label}</span>
              {message.detail && <span className="block text-[12px] text-clinic-secondary">{message.detail}</span>}
            </li>
          );
        })}
      </ul>
      {problem && <p className="mt-1 text-[12px] text-clinic-secondary">{problem}</p>}
      <div className="mt-2 flex flex-wrap gap-2">
        <Button tone="ghost" size="sm" onClick={() => void load()} disabled={busy}>
          Refresh status
        </Button>
        {retryable && retryUrl && (
          <Button tone="secondary" size="sm" onClick={() => void load('POST', retryUrl)} disabled={busy}>
            Send again on WhatsApp
          </Button>
        )}
      </div>
    </div>
  );
}

/**
 * A booking made with the mobile number being typed. One exact match is
 * offered, never attached on its own; several are listed to choose from.
 */
export function BookingFound({ matches, chosen, onUse, onWalkIn }: { matches: BookingMatch[]; chosen: number | null; onUse: (match: BookingMatch) => void; onWalkIn: () => void }) {
  if (matches.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby="nc-booking-heading" className="rounded-2xl border border-sky-300 bg-sky-50/70 p-4">
      <h3 id="nc-booking-heading" role="status" className="flex items-center gap-2 text-[14px] font-bold text-sky-950">
        <CalendarClock className="h-4 w-4" aria-hidden="true" />
        {matches.length === 1 ? 'Appointment found' : `${matches.length} appointments found for this number`}
      </h3>
      <ul className="mt-2 space-y-2">
        {matches.map((match) => (
          <li key={match.id} className="flex flex-col gap-2 rounded-xl border border-sky-200 bg-white p-3 sm:flex-row sm:items-center">
            <p className="min-w-0 flex-1 text-[13px] text-clinic-ink">
              <span className="font-bold">{match.doctor?.name ?? 'Any doctor'}</span>
              {match.doctor?.practice && <span className="text-clinic-secondary"> · {match.doctor.practice}</span>}
              <span className="block text-[12px] text-clinic-secondary">
                Today{match.time ? ` · ${match.time}` : ''}
                {match.name ? ` · booked as ${match.name}` : ''}
              </span>
            </p>
            {chosen === match.id ? (
              <span className="inline-flex items-center gap-1 text-[12.5px] font-semibold text-emerald-700">
                <CheckCircle2 className="h-4 w-4" aria-hidden="true" /> Using this appointment
              </span>
            ) : (
              <Button tone="primary" size="sm" onClick={() => onUse(match)}>
                Use appointment
              </Button>
            )}
          </li>
        ))}
      </ul>
      <button type="button" onClick={onWalkIn} className="mt-2 rounded text-[12.5px] font-semibold text-sky-950 underline underline-offset-2 hover:no-underline">
        {chosen ? 'Do not use the appointment' : 'Continue as walk-in'}
      </button>
    </section>
  );
}
