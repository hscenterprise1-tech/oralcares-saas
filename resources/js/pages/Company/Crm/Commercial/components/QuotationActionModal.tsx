import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmTextarea,
} from '@/pages/Company/design';

/**
 * The three decisions that move a quotation on.
 *
 * One component rather than three near-identical ones: they differ only in
 * their endpoint, their copy and whether the note is required. Splitting them
 * is how three modals drift apart.
 *
 * Every one of these is gated server-side — the buttons that open this only
 * render when the payload's `can` flags allow it, and the endpoint checks again
 * regardless.
 */
export type QuotationAction = 'send' | 'approve' | 'reject';

const COPY: Record<QuotationAction, {
  title: string;
  description: string;
  field: string;
  hint: string;
  placeholder: string;
  submit: string;
  required: boolean;
  key: 'notes' | 'reason';
}> = {
  send: {
    title: 'Send to the customer',
    description: 'Marks the quotation sent and starts the clock on its validity.',
    field: 'Note',
    hint: 'Optional. Kept on the record, not sent to the customer.',
    placeholder: 'Sending the revised pricing we agreed on the call.',
    submit: 'Send quotation',
    required: false,
    key: 'notes',
  },
  approve: {
    title: 'Approve this quotation',
    description: 'Clears the discount it carries. A rep cannot approve their own.',
    field: 'Approval note',
    hint: 'Why this discount is acceptable. Read later at renewal.',
    placeholder: 'Volume commitment across three branches justifies the 12%.',
    submit: 'Approve',
    required: false,
    key: 'notes',
  },
  reject: {
    title: 'Reject this quotation',
    description: 'Sends it back. The reason is shown on the record.',
    field: 'Reason',
    hint: 'Required — this is what the person who raised it will read.',
    placeholder: 'Discount exceeds what we can offer at this contract length.',
    submit: 'Reject',
    required: true,
    key: 'reason',
  },
};

const ENDPOINT: Record<QuotationAction, string> = {
  send: 'company.crm.quotations.send',
  approve: 'company.crm.quotations.approve',
  reject: 'company.crm.quotations.reject',
};

export function QuotationActionModal({
  isOpen,
  onClose,
  kind,
  quotationId,
  company,
}: {
  isOpen: boolean;
  onClose: () => void;
  kind: QuotationAction;
  quotationId: number;
  company: string;
}) {
  const copy = COPY[kind];
  const { data, setData, post, processing, errors, reset } = useForm<{ notes: string; reason: string }>({
    notes: '',
    reason: '',
  });
  /*
   * A blocked state transition — this quotation is already decided,
   * superseded or converted — comes back keyed `quotation`, not against
   * either field this form has. Surfaced on its own so the guard's reason
   * is not silently dropped.
   */
  const stateError = (errors as Record<string, string>).quotation;

  const close = () => {
    reset();
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    post(route(ENDPOINT[kind], quotationId), {
      preserveScroll: true,
      onSuccess: close,
    });
  };

  const value = copy.key === 'reason' ? data.reason : data.notes;
  const error = copy.key === 'reason' ? errors.reason : errors.notes;

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`${copy.title} — ${company}`}
      description={copy.description}
    >
      <form onSubmit={submit} className="space-y-5">
        {stateError && (
          <p className="rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
            {stateError}
          </p>
        )}

        <CrmFormField label={copy.field} hint={copy.hint} error={error} required={copy.required}>
          <CrmTextarea
            rows={3}
            value={value}
            onChange={(e) => setData(copy.key, e.target.value)}
            placeholder={copy.placeholder}
            required={copy.required}
          />
        </CrmFormField>

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone={kind === 'reject' ? 'danger' : 'primary'} disabled={processing}>
            {processing ? 'Working…' : copy.submit}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
