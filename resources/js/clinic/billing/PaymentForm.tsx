import { ChoiceChips } from '../ui/ChoiceChips';
import { Field, Input } from '../ui/controls';
import { formatMoney } from '../ui/visuals';

/** A payment as it is typed: the amount stays text until it is sent. */
export interface PaymentValue {
  method: string;
  amount: string;
  reference: string;
}

export type PaymentMethodOption = { value: string; label: string };

/** A fresh payment: the first method the clinic accepts, the whole amount due. */
export function paymentFor(methods: PaymentMethodOption[], due: number | null): PaymentValue {
  return { method: methods[0]?.value ?? '', amount: due !== null && due > 0 ? String(due) : '', reference: '' };
}

/** The body every payment endpoint takes. */
export function paymentPayloadOf(value: PaymentValue): { method: string; amount: number; reference: string | null } {
  return { method: value.method, amount: Number(value.amount), reference: value.reference.trim() || null };
}

/** What is wrong with it before it is sent, in words; null when it can go. */
export function paymentProblem(value: PaymentValue, due: number | null, currency = 'INR'): string | null {
  const amount = Number(value.amount);
  if (value.method === '') {
    return 'Choose how the patient is paying.';
  }
  if (!(amount > 0)) {
    return 'Enter the amount received.';
  }
  if (due !== null && amount > due) {
    return `The amount is more than the ${formatMoney(due, currency)} due.`;
  }

  return null;
}

/**
 * The one way a payment is entered — at the desk, at checkout, on a bill in
 * Patient 360, at check-in. The methods are the clinic's accepted ones (from
 * the server); the amount due is the server's. A part payment says what will
 * stay outstanding. The server checks all of it again.
 */
export function PaymentForm({
  value,
  onChange,
  methods,
  due,
  currency = 'INR',
  idPrefix,
  error,
  methodLabel = 'Paid by',
}: {
  value: PaymentValue;
  onChange: (patch: Partial<PaymentValue>) => void;
  methods: PaymentMethodOption[];
  /** What is owed; null when there is no ceiling to show. */
  due: number | null;
  currency?: string;
  idPrefix: string;
  error?: string | null;
  methodLabel?: string;
}) {
  const amount = Number(value.amount);
  const money = (n: number) => formatMoney(n, currency);

  return (
    <div className="space-y-3">
      <div>
        <p id={`${idPrefix}-method-label`} className="mb-1.5 text-[12px] font-semibold text-clinic-ink">
          {methodLabel}
        </p>
        <ChoiceChips label={methodLabel} id={`${idPrefix}-method`} options={methods} value={value.method} onChange={(method) => onChange({ method })} />
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="Amount received" htmlFor={`${idPrefix}-amount`} hint={due !== null ? `Due ${money(due)}` : undefined}>
          <Input
            id={`${idPrefix}-amount`}
            inputMode="decimal"
            value={value.amount}
            onChange={(event) => onChange({ amount: event.target.value.replace(/[^0-9.]/g, '') })}
            invalid={!!error}
            aria-describedby={error ? `${idPrefix}-error` : undefined}
          />
        </Field>
        {value.method !== '' && value.method !== 'cash' && (
          <Field label="Reference" htmlFor={`${idPrefix}-reference`} hint="UPI or card reference (optional)">
            <Input id={`${idPrefix}-reference`} value={value.reference} maxLength={100} onChange={(event) => onChange({ reference: event.target.value })} autoComplete="off" />
          </Field>
        )}
      </div>
      {due !== null && amount > 0 && amount < due && <p className="text-[12px] text-clinic-secondary">{money(due - amount)} will stay outstanding on the bill.</p>}
      {error && (
        <p id={`${idPrefix}-error`} role="alert" className="text-[12px] font-medium text-clinic-danger">
          {error}
        </p>
      )}
    </div>
  );
}
