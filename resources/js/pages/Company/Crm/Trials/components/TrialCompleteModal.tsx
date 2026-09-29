import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmSelect,
  CrmTextarea,
} from '@/pages/Company/design';

/**
 * Record how a trial went.
 *
 * Completing is not converting. This writes down the outcome and moves the deal
 * to wherever that outcome points; winning the deal is a separate action with
 * its own commercial gate. Keeping them apart is why a rep can record an
 * honest "not interested" without that being a destructive act.
 *
 * The outcomes are the enum's, verbatim — a form offering choices the server
 * cannot store is how free-text outcomes end up in a reporting column.
 */
const OUTCOMES: Array<{ value: string; label: string }> = [
  { value: 'highly_interested', label: 'Highly interested' },
  { value: 'quotation_requested', label: 'Quotation requested' },
  { value: 'interested', label: 'Interested' },
  { value: 'needs_followup', label: 'Needs follow-up' },
  { value: 'needs_extension', label: 'Needs more time' },
  { value: 'no_decision', label: 'No decision' },
  { value: 'not_interested', label: 'Not interested' },
  { value: 'lost', label: 'Lost' },
];

export function TrialCompleteModal({
  isOpen,
  onClose,
  trialId,
  company,
}: {
  isOpen: boolean;
  onClose: () => void;
  trialId: number;
  company: string;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    outcome: 'quotation_requested',
    feedback: '',
    objections: '',
    next_action: '',
  });

  const close = () => {
    reset();
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    post(route('company.crm.trials.complete', trialId), {
      preserveScroll: true,
      onSuccess: close,
    });
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`Close ${company}'s trial`}
      description="Records the outcome. Winning the deal is a separate step, and needs an approved quotation settled in full."
    >
      <form onSubmit={submit} className="space-y-5">
        <CrmFormField label="Outcome" error={errors.outcome} required>
          <CrmSelect
            value={data.outcome}
            onChange={(e) => setData('outcome', e.target.value)}
            required
          >
            {OUTCOMES.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </CrmSelect>
        </CrmFormField>

        <CrmFormField label="What they said" error={errors.feedback}>
          <CrmTextarea
            rows={3}
            value={data.feedback}
            onChange={(e) => setData('feedback', e.target.value)}
            placeholder="Liked the appointment flow; wants to see lab integration."
          />
        </CrmFormField>

        <CrmFormField label="Objections" error={errors.objections} hint="What stood in the way.">
          <CrmTextarea
            rows={2}
            value={data.objections}
            onChange={(e) => setData('objections', e.target.value)}
          />
        </CrmFormField>

        <CrmFormField label="Next step" error={errors.next_action}>
          <CrmTextarea
            rows={2}
            value={data.next_action}
            onChange={(e) => setData('next_action', e.target.value)}
            placeholder="Send revised quotation with the lab module included."
          />
        </CrmFormField>

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone="primary" disabled={processing}>
            {processing ? 'Saving…' : 'Record outcome'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
