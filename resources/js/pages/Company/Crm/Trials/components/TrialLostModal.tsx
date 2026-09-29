import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmSelect,
} from '@/pages/Company/design';

/**
 * Record that an evaluation was lost, and close the deal with it.
 *
 * Distinct from cancelling. Cancelling is us stopping a trial — a duplicate
 * account, a mistake — and says nothing about the customer. This is the
 * customer deciding against us, and the reason plus the competitor are the only
 * part of a failed evaluation worth anything six months later.
 *
 * The reason is a fixed list because the point of recording a loss is to count
 * losses of the same kind afterwards, and free text cannot be counted. It is the
 * same vocabulary the deal's own loss triage uses — the server sends it, rather
 * than this file keeping a second copy that would drift.
 *
 * The copy says plainly that the deal closes too. A rep who thinks they are
 * annotating a trial and discovers they have closed the deal has been misled by
 * the interface, not by the button.
 */
export function TrialLostModal({
  isOpen,
  onClose,
  trialId,
  company,
  reasons,
}: {
  isOpen: boolean;
  onClose: () => void;
  trialId: number;
  company: string;
  reasons: { value: string; label: string }[];
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    lost_reason: '',
    lost_competitor: '',
  });

  const close = () => {
    reset();
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    post(route('company.crm.trials.mark-lost', trialId), {
      preserveScroll: true,
      onSuccess: close,
    });
  };

  // Asked for only when it is the answer, rather than sitting there inviting a
  // guess on every other reason.
  const wantsCompetitor = data.lost_reason === 'Competitor';

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`Mark ${company}'s trial lost`}
      description="This closes the deal behind it as well."
    >
      <form onSubmit={submit} className="space-y-5">
        <CrmFormField label="Why did we lose it?" required error={errors.lost_reason}>
          <CrmSelect
            value={data.lost_reason}
            onValueChange={(val) => setData('lost_reason', val)}
            options={[{ value: '', label: 'Choose a reason' }, ...reasons]}
          />
        </CrmFormField>

        <CrmFormField
          label="Who did they choose?"
          error={errors.lost_competitor}
          hint={wantsCompetitor ? 'Named competitors are what make a loss report useful.' : 'Optional.'}
        >
          <CrmInput
            value={data.lost_competitor}
            onChange={(e) => setData('lost_competitor', e.target.value)}
            placeholder="e.g. Practo, Dentrix"
          />
        </CrmFormField>

        <p className="rounded-2xl bg-ovs-crm-surface-inset px-4 py-3 text-sm text-ovs-crm-text-secondary">
          The trial is closed with this outcome and the deal moves to{' '}
          <strong className="text-ovs-crm-text-primary">Lost</strong>, cancelling its
          open follow-ups. The customer&rsquo;s data is left intact.
        </p>

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone="danger" disabled={processing || !data.lost_reason}>
            {processing ? 'Recording…' : 'Mark lost'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
