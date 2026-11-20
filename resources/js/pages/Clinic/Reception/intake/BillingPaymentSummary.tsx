import { Banknote, Building, Clock, CreditCard, IndianRupee, QrCode, ShieldCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { IconChip } from './IconChip';
import type { IntakeConfig, PractitionerOption } from './types';

export type PaymentMode = 'full' | 'partial' | 'pay_later';

export interface PaymentState {
  mode: PaymentMode;
  method: string;
  amount: string;
  reference: string;
}

interface BillingPaymentSummaryProps {
  config: IntakeConfig | null;
  doctor: PractitionerOption | null;
  module: string;
  paymentState: PaymentState;
  onChange: (patch: Partial<PaymentState>) => void;
  disabled?: boolean;
}

const METHOD_ICONS: Record<string, typeof Banknote> = {
  cash: Banknote,
  upi: QrCode,
  card: CreditCard,
  bank_transfer: Building,
  cheque: Building,
};

/** The portal's own field (`clinic/ui/controls`): white, a hairline, the blue focus ring. */
const FIELD =
  'h-11 w-full rounded-xl border border-[#DCE6F3] bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] transition-[border-color,box-shadow] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20 disabled:bg-[#F1F5F9]';

const LABEL = 'mb-1.5 block text-[12px] font-semibold leading-none text-cx-text-2';

/**
 * The consultation fee and how it is settled at check-in, for New Case and
 * Repeat Case alike, in the Owner Dashboard's language: a section tile and
 * title, the fee as a figure, Full / Partial / Pay later as the dashboard's
 * pill switch, the methods as tiles, and what is collected now on a soft wash.
 * What is owed is the server's (the doctor's fee, charged at check-in unless
 * the clinic or the module bills later); this member's rights decide which
 * choices are open. Nothing here sets an amount the server did not.
 */
export function BillingPaymentSummary({ config, doctor, module, paymentState, onChange, disabled = false }: BillingPaymentSummaryProps) {
  // Check if this module or clinic charges at check-in
  const isNoChargeModule = (config?.check_in.no_charge_modules || []).includes(module.toLowerCase());
  const collectsAtCheckIn = config?.check_in.payment === 'at_check_in' && !isNoChargeModule;
  const rawFee = doctor?.fee ?? null;
  const fee = collectsAtCheckIn && rawFee !== null && rawFee > 0 ? rawFee : 0;
  const canCharge = config?.payment.can_charge ?? true;
  const canCollect = config?.payment.can_collect ?? true;

  const paymentMethods =
    config?.payment.methods && config.payment.methods.length > 0
      ? config.payment.methods
      : [
          { value: 'cash', label: 'Cash' },
          { value: 'upi', label: 'UPI' },
          { value: 'card', label: 'Card' },
        ];

  const paidAmount = paymentState.mode === 'full' ? fee : paymentState.mode === 'partial' ? Math.min(Number(paymentState.amount) || 0, fee) : 0;

  const outstandingBalance = Math.max(0, fee - paidAmount);
  const money = (value: number) => `₹${value.toLocaleString('en-IN')}`;

  const choices: { mode: PaymentMode; label: string; open: boolean; onSelect: () => void; title?: string }[] = [
    { mode: 'full', label: `Full ${money(fee)}`, open: canCollect, onSelect: () => onChange({ mode: 'full', amount: String(fee) }) },
    { mode: 'partial', label: 'Partial', open: canCollect, onSelect: () => onChange({ mode: 'partial', amount: String(Math.floor(fee / 2)) }) },
    { mode: 'pay_later', label: 'Pay later', open: canCharge, onSelect: () => onChange({ mode: 'pay_later', amount: '0' }), title: !canCharge ? 'Requires billing.create permission' : undefined },
  ];

  return (
    <section aria-labelledby="intake-payment-title" className="rounded-2xl border border-cx-line/80 bg-white p-4 shadow-cx-card sm:p-5">
      {/* The section, as the Owner Dashboard opens one: its tile, its title, the figure it is about. */}
      <header className="flex items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <IconChip icon={IndianRupee} tone="mint" size="md" variant="solid" />
          <div className="min-w-0">
            <h3 id="intake-payment-title" className="text-[15px] font-semibold leading-5 text-cx-nav-ink">
              {isNoChargeModule ? 'Payment' : 'Consultation fee'}
            </h3>
            <p className="mt-0.5 text-[12px] leading-4 text-cx-text-3">{fee > 0 ? 'Charged at check-in' : 'Nothing to collect at check-in'}</p>
          </div>
        </div>
        <span className={cn('shrink-0 text-[22px] font-semibold leading-7 tracking-[-0.02em] tabular-nums', fee > 0 ? 'text-cx-nav-ink' : 'text-cx-text-3')}>{money(fee)}</span>
      </header>

      {/* When no fee is due at check-in */}
      {fee === 0 ? (
        <div className="mt-4 flex items-start gap-3 rounded-md bg-gradient-to-br from-[#F4F6FA] to-[#EBEFF5] px-4 py-3.5">
          <IconChip icon={Clock} tone="slate" size="sm" variant="soft" />
          <p className="text-[12.5px] leading-5 text-cx-text-2">
            {isNoChargeModule ? 'Nothing is charged at check-in. After the session the desk bills the treatments given, or the patient pays from a package.' : 'This clinic bills the consultation after the doctor completes the visit.'}
          </p>
        </div>
      ) : (
        <div className="mt-4 space-y-4">
          {/* Full / Partial / Pay later: the dashboard's pill switch, each open only as this member's rights allow. */}
          <div>
            <span className={LABEL} id="intake-settlement-label">
              Settlement
            </span>
            <div role="radiogroup" aria-labelledby="intake-settlement-label" className="grid grid-cols-3 gap-1 rounded-full bg-white p-1 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)]">
              {choices.map((choice) => {
                const selected = paymentState.mode === choice.mode;

                return (
                  <button
                    key={choice.mode}
                    type="button"
                    role="radio"
                    aria-checked={selected}
                    disabled={disabled || !choice.open}
                    onClick={choice.onSelect}
                    title={choice.title}
                    className={cn(
                      'min-h-10 truncate rounded-full px-2 text-[13px] font-semibold motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/30 disabled:cursor-not-allowed disabled:opacity-45',
                      selected ? (choice.mode === 'pay_later' ? 'bg-cx-amber-bg text-cx-amber-ink' : 'bg-cx-teal-bg text-cx-teal-ink') : 'text-cx-text-2 hover:text-cx-nav-ink',
                    )}
                  >
                    {choice.label}
                  </button>
                );
              })}
            </div>
            {!canCharge && <p className="mt-1.5 text-[11.5px] text-cx-amber-ink">Pay later needs billing rights.</p>}
          </div>

          {/* Partial Amount Input */}
          {paymentState.mode === 'partial' && (
            <div>
              <label htmlFor="intake-partial-amount" className={LABEL}>
                Amount collected today
              </label>
              <div className="relative">
                <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[13.5px] font-semibold text-cx-text-3">₹</span>
                <input
                  id="intake-partial-amount"
                  type="number"
                  inputMode="decimal"
                  min={1}
                  max={fee}
                  disabled={disabled}
                  placeholder={`Up to ${money(fee)}`}
                  value={paymentState.amount}
                  onChange={(e) => onChange({ amount: e.target.value })}
                  className={cn(FIELD, 'pl-8 font-semibold tabular-nums')}
                />
              </div>
            </div>
          )}

          {/* Payment Method Selector (For Full or Partial) */}
          {paymentState.mode !== 'pay_later' && (
            <div className="space-y-3">
              <div>
                <span className={LABEL} id="intake-method-label">
                  Payment method
                </span>
                <div role="radiogroup" aria-labelledby="intake-method-label" className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                  {paymentMethods.map((meth) => {
                    const active = (paymentState.method || paymentMethods[0]?.value) === meth.value;
                    const Icon = METHOD_ICONS[meth.value] || CreditCard;

                    return (
                      <button
                        key={meth.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        disabled={disabled}
                        onClick={() => onChange({ method: meth.value })}
                        className={cn(
                          'flex min-h-12 min-w-0 items-center gap-2.5 rounded-md bg-white px-3 py-2 text-left ring-1 ring-inset motion-safe:transition-[box-shadow,background-color] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/40 disabled:opacity-50',
                          active ? 'bg-cx-teal-bg/60 ring-2 ring-[#0E9F87]/50' : 'ring-cx-line hover:ring-[#B9C6D8]',
                        )}
                      >
                        <IconChip icon={Icon} tone={active ? 'teal' : 'slate'} size="sm" variant="soft" />
                        <span className={cn('min-w-0 truncate text-[13px] font-semibold', active ? 'text-cx-teal-ink' : 'text-cx-nav-ink')}>{meth.label}</span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {/* Reference / UTR Number for Digital Payments */}
              {(paymentState.method === 'upi' || paymentState.method === 'card' || paymentState.method === 'bank_transfer') && (
                <div>
                  <label htmlFor="intake-payment-reference" className={LABEL}>
                    Reference <span className="font-normal text-cx-text-3">· optional</span>
                  </label>
                  <input
                    id="intake-payment-reference"
                    type="text"
                    disabled={disabled}
                    placeholder="UPI ref, card last 4 digits or UTR"
                    value={paymentState.reference}
                    onChange={(e) => onChange({ reference: e.target.value })}
                    className={FIELD}
                  />
                </div>
              )}
            </div>
          )}

          {/* Pay Later Notice */}
          {paymentState.mode === 'pay_later' && (
            <div className="flex items-start gap-3 rounded-md bg-gradient-to-br from-[#FFF7EA] to-[#FFEDD2] px-4 py-3.5">
              <IconChip icon={ShieldCheck} tone="amber" size="sm" variant="soft" />
              <p className="text-[12.5px] leading-5 text-cx-text-2">
                <span className="block font-semibold text-cx-amber-ink">Paid later</span>
                The visit is checked in with {money(fee)} outstanding, settled at checkout.
              </p>
            </div>
          )}

          {/* What is collected now (and what stays due), as a dashboard figure on its wash. */}
          <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-1 rounded-md bg-gradient-to-br from-[#EAFAF6] to-[#DCF3EE] px-4 py-3">
            <span className="text-[12.5px] font-medium text-cx-teal-ink">To collect now</span>
            <span className="text-right">
              <span className="block text-[20px] font-semibold leading-7 tracking-[-0.02em] text-cx-nav-ink tabular-nums">{money(paidAmount)}</span>
              {outstandingBalance > 0 && <span className="block text-[12px] font-medium text-cx-amber-ink tabular-nums">{money(outstandingBalance)} stays due</span>}
            </span>
          </div>
        </div>
      )}
    </section>
  );
}
