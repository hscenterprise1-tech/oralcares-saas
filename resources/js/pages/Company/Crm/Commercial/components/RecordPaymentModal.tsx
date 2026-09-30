import React, { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  inr,
} from '@/pages/Company/design';
import type { Reconciliation } from '../commercial';

/**
 * Write down that money arrived.
 *
 * The outstanding balance is offered as the default amount, because settling
 * the remainder is what nearly every payment is. What the amount will *do* is
 * shown before it is saved — settle the quotation and raise its invoice, leave
 * a balance, or overshoot — since those three have very different consequences
 * and only the first produces paper.
 *
 * Only a settled payment counts towards the balance. A pending gateway attempt
 * is recorded so it is not lost, and reconciles nothing until it clears.
 */
const GATEWAYS = ['bank_transfer', 'upi', 'card', 'cheque', 'cash', 'manual'];

export function RecordPaymentModal({
  isOpen,
  onClose,
  quotationId,
  company,
  reconciliation,
}: {
  isOpen: boolean;
  onClose: () => void;
  quotationId: number;
  company: string;
  reconciliation: Reconciliation;
}) {
  const outstanding = Math.max(0, reconciliation.balance);

  const [amount, setAmount] = useState(outstanding || reconciliation.expected);
  const [gateway, setGateway] = useState('bank_transfer');
  const [reference, setReference] = useState('');
  const [status, setStatus] = useState('successful');
  const [processing, setProcessing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  /*
   * Re-default the amount each time the form opens.
   *
   * The modal stays mounted between openings, so without this it kept the
   * figure from the previous payment — after part-paying ₹20,000 of ₹45,000 it
   * offered ₹20,000 again rather than the ₹25,000 still outstanding, which is
   * the number somebody almost always wants.
   */
  useEffect(() => {
    if (isOpen) {
      setAmount(Math.max(0, reconciliation.balance) || reconciliation.expected);
      setErrors({});
    }
  }, [isOpen, reconciliation.balance, reconciliation.expected]);

  const settledAfter = reconciliation.settled + (status === 'successful' ? amount : 0);
  const balanceAfter = Math.round((reconciliation.expected - settledAfter) * 100) / 100;

  const outcome = status !== 'successful'
    ? 'pending'
    : balanceAfter > 0.01
      ? 'partial'
      : balanceAfter < -0.01
        ? 'over'
        : 'settled';

  const close = () => {
    setErrors({});
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    setProcessing(true);

    router.post(
      route('company.crm.quotations.payments.store', quotationId),
      { amount, gateway, transaction_id: reference, status },
      {
        preserveScroll: true,
        onSuccess: close,
        onError: (e) => setErrors(e as Record<string, string>),
        onFinish: () => setProcessing(false),
      },
    );
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`Record payment — ${company}`}
      description={`${inr(reconciliation.settled)} of ${inr(reconciliation.expected)} received so far.`}
    >
      <form onSubmit={submit} className="space-y-5">
        <CrmFormField label="Amount" error={errors.amount} required>
          <CrmInput
            type="number"
            min={0.01}
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(parseFloat(e.target.value) || 0)}
            required
          />
        </CrmFormField>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CrmFormField label="Method" error={errors.gateway} required>
            <CrmSelect value={gateway} onChange={(e) => setGateway(e.target.value)} required>
              {GATEWAYS.map((g) => (
                <option key={g} value={g}>{g.replace(/_/g, ' ')}</option>
              ))}
            </CrmSelect>
          </CrmFormField>

          <CrmFormField label="Status" error={errors.status} required>
            <CrmSelect value={status} onChange={(e) => setStatus(e.target.value)} required>
              <option value="successful">Settled</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
            </CrmSelect>
          </CrmFormField>
        </div>

        <CrmFormField label="Reference" error={errors.transaction_id} hint="Bank reference, UTR or cheque number.">
          <CrmInput value={reference} onChange={(e) => setReference(e.target.value)} />
        </CrmFormField>

        {/* What this will do, before it does it. */}
        <p className={`rounded-2xl px-4 py-3 text-sm ${
          outcome === 'over'
            ? 'bg-ovs-crm-accent-coral-soft text-ovs-crm-accent-coral-text'
            : outcome === 'settled'
              ? 'bg-ovs-crm-accent-green-soft text-ovs-crm-accent-green-text'
              : 'bg-ovs-crm-surface-inset text-ovs-crm-text-secondary'
        }`}
        >
          {outcome === 'pending' && <>Recorded but not counted. A pending payment settles nothing until it clears.</>}
          {outcome === 'partial' && <>Leaves <strong>{inr(balanceAfter)}</strong> outstanding. No invoice is raised until the quotation is settled in full.</>}
          {outcome === 'settled' && <>Settles this quotation. Its invoice will be raised automatically.</>}
          {outcome === 'over' && (
            <span className="flex items-start gap-2">
              <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
              <span>
                This is <strong>{inr(Math.abs(balanceAfter))}</strong> more than the quotation asks for.
                It will be recorded in full and flagged as overpaid — no invoice will be raised.
              </span>
            </span>
          )}
        </p>

        {errors.payment && (
          <p className="rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
            {errors.payment}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone="primary" disabled={processing || amount <= 0}>
            {processing ? 'Recording…' : 'Record payment'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
