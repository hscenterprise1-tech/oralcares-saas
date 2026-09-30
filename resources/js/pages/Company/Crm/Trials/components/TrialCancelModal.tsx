import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmTextarea,
} from '@/pages/Company/design';

/**
 * Stop a trial without recording a loss.
 *
 * Distinct from marking it lost — see that modal's own docblock. Cancelling
 * is us ending the evaluation (a duplicate account, a mistake, a request
 * withdrawn), not the customer declining, so it carries a plain reason
 * rather than the fixed loss vocabulary and never touches the deal's stage.
 */
export function TrialCancelModal({
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
    reason: '',
  });

  const close = () => {
    reset();
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    post(route('company.crm.trials.cancel', trialId), {
      preserveScroll: true,
      onSuccess: close,
    });
  };

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`Cancel ${company}'s trial`}
      description="Access ends immediately. The deal's stage is left untouched."
    >
      <form onSubmit={submit} className="space-y-5">
        <CrmFormField label="Reason" required error={errors.reason}>
          <CrmTextarea
            rows={3}
            value={data.reason}
            onChange={(e) => setData('reason', e.target.value)}
            placeholder="e.g. Duplicate account, started by mistake"
          />
        </CrmFormField>

        <p className="rounded-2xl bg-ovs-crm-surface-inset px-4 py-3 text-sm text-ovs-crm-text-secondary">
          The tenant&rsquo;s access is revoked right away. Its data is kept, and
          this does not record a loss or move the deal.
        </p>

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Keep trial</CrmButton>
          <CrmButton type="submit" tone="danger" disabled={processing || !data.reason.trim()}>
            {processing ? 'Cancelling…' : 'Cancel trial'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
