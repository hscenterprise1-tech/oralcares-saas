import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
  inr,
} from '@/pages/Company/design';
import type { DealQuotation } from '../deal';

/*
 * The remedy for the one thing standing between a deal and Won.
 *
 * `DealStageService` refuses the move into Won without an approved quotation
 * settled in full — a fact this page used to only ever show as a locked
 * button and a tooltip, with nowhere on the page to actually do anything
 * about it. This is that somewhere: it records the payment the gate is
 * waiting to see, against the deal's one approved quotation, and always as
 * `successful` — a rep opening this modal is asserting the money has
 * already arrived, not starting a gateway transaction.
 */

const PAYMENT_METHODS = [
  { value: 'bank_transfer', label: 'Bank Transfer' },
  { value: 'upi', label: 'UPI' },
  { value: 'gateway', label: 'Payment Gateway' },
  { value: 'cheque', label: 'Cheque' },
];

interface RecordAdvancePaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  quotations: DealQuotation[];
}

export function RecordAdvancePaymentModal({ isOpen, onClose, quotations }: RecordAdvancePaymentModalProps) {
  const quotation = quotations.find((q) => q.status === 'approved');

  const form = useForm({
    amount: quotation ? String(quotation.grand_total) : '',
    payment_method: 'bank_transfer',
    transaction_id: '',
    payment_date: new Date().toISOString().slice(0, 10),
  });
  const { data, setData, processing, errors, reset } = form;

  const close = () => {
    reset();
    onClose();
  };

  if (!quotation) {
    return (
      <CrmModal
        open={isOpen}
        onClose={onClose}
        title="Record advance payment"
        description="Won needs an approved quotation settled in full."
        footer={<CrmButton onClick={onClose}>Close</CrmButton>}
      >
        <p className="text-[13px] text-ovs-crm-text-secondary">
          There is no approved quotation on this deal yet — a payment has nowhere to settle
          against. Get one to Approved first, then come back here to record what was paid
          against it.
        </p>
      </CrmModal>
    );
  }

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title="Record advance payment"
      description={`Against ${quotation.quotation_number} · v${quotation.version} · ${inr(quotation.grand_total)}`}
      footer={
        <>
          <CrmButton onClick={close}>Cancel</CrmButton>
          <CrmButton
            tone="primary"
            loading={processing}
            onClick={() => {
              form.transform((current) => ({
                ...current,
                status: 'successful',
                gateway: current.payment_method,
              }));
              form.post(route('company.crm.quotations.payments.store', quotation.id), {
                preserveScroll: true,
                onSuccess: close,
              });
            }}
          >
            Record payment
          </CrmButton>
        </>
      }
    >
      <div className="flex flex-col gap-3.5">
        <CrmFormField label="Amount" required error={errors.amount}>
          <CrmInput
            type="number"
            min={0.01}
            step="0.01"
            value={data.amount}
            onChange={(e) => setData('amount', e.target.value)}
          />
        </CrmFormField>

        <CrmFormField label="Payment method" required error={errors.payment_method}>
          <CrmSelect
            value={data.payment_method}
            onChange={(e) => setData('payment_method', e.target.value)}
          >
            {PAYMENT_METHODS.map((m) => (
              <option key={m.value} value={m.value}>
                {m.label}
              </option>
            ))}
          </CrmSelect>
        </CrmFormField>

        <CrmFormField
          label="Transaction / UTR reference"
          error={errors.transaction_id}
          hint="The bank reference, UPI ref, gateway id, or cheque number."
        >
          <CrmInput
            value={data.transaction_id}
            onChange={(e) => setData('transaction_id', e.target.value)}
            placeholder="UTR1234567890"
          />
        </CrmFormField>

        <CrmFormField label="Payment date" required error={errors.payment_date}>
          <CrmInput
            type="date"
            value={data.payment_date}
            onChange={(e) => setData('payment_date', e.target.value)}
          />
        </CrmFormField>
      </div>
    </CrmModal>
  );
}
