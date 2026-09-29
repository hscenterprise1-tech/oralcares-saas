import React from 'react';
import { useForm } from '@inertiajs/react';
import {
  CrmModal,
  CrmButton,
  CrmFormField,
  CrmInput,
  CrmTextarea,
} from '@/pages/Company/design';
import { writtenDate } from '../trialPresentation';

/**
 * Extend a trial, and the customer's access with it.
 *
 * Worth saying out loud in the copy: extending used to move the trial record
 * and nothing else, so a customer promised another week was still locked out on
 * the original date. Now the subscription window moves too, and the form says
 * so — a rep who does not know that will not offer it with any confidence.
 *
 * The reason is required because it is the only durable record of why a
 * customer got more time, and it is read months later when the same account
 * comes up for renewal.
 */
export function TrialExtendModal({
  isOpen,
  onClose,
  trialId,
  company,
  endDate,
  extensionCount,
}: {
  isOpen: boolean;
  onClose: () => void;
  trialId: number;
  company: string;
  endDate: string | null;
  extensionCount: number;
}) {
  const { data, setData, post, processing, errors, reset } = useForm({
    additional_days: 7,
    extension_reason: '',
  });

  const close = () => {
    reset();
    onClose();
  };

  const submit = (e: React.FormEvent) => {
    e.preventDefault();

    post(route('company.crm.trials.extend', trialId), {
      preserveScroll: true,
      onSuccess: close,
    });
  };

  const newEnd = (() => {
    if (!endDate) return null;

    const d = new Date(endDate);
    if (Number.isNaN(d.getTime())) return null;

    d.setDate(d.getDate() + Number(data.additional_days || 0));

    return d.toISOString();
  })();

  return (
    <CrmModal
      open={isOpen}
      onClose={close}
      title={`Extend ${company}'s trial`}
      description="The customer keeps working throughout — their access moves with the trial."
    >
      <form onSubmit={submit} className="space-y-5">
        <CrmFormField label="Additional days" error={errors.additional_days}>
          {/*
            * Three presets and a field, not a dropdown of three.
            *
            * Almost every extension is a few days, a week or a fortnight, and
            * making those one tap is the difference between a rep extending a
            * trial on the call and promising to do it later. The input stays
            * because "until the 30th" is a real request and a fixed list would
            * refuse it.
            */}
          <div className="mb-2 flex flex-wrap gap-1.5">
            {[3, 7, 14].map((days) => (
              <CrmButton
                key={days}
                type="button"
                size="sm"
                tone={Number(data.additional_days) === days ? 'primary' : 'ghost'}
                onClick={() => setData('additional_days', days)}
              >
                {days} days
              </CrmButton>
            ))}
          </div>
          <CrmInput
            type="number"
            min={1}
            max={60}
            value={data.additional_days}
            onChange={(e) => setData('additional_days', Number(e.target.value))}
            required
          />
        </CrmFormField>

        <CrmFormField
          label="Why"
          error={errors.extension_reason}
          hint="Read months later when this account comes up for renewal."
        >
          <CrmTextarea
            rows={3}
            value={data.extension_reason}
            onChange={(e) => setData('extension_reason', e.target.value)}
            placeholder="Client is still evaluating the EMR module."
            required
          />
        </CrmFormField>

        <p className="rounded-2xl bg-ovs-crm-surface-inset px-4 py-3 text-sm text-ovs-crm-text-secondary">
          {newEnd ? (
            <>Trial will run to <strong className="text-ovs-crm-text-primary">{writtenDate(newEnd)}</strong>, and the customer&rsquo;s access moves with it — they keep working throughout.</>
          ) : (
            <>The customer&rsquo;s access moves with the trial — they keep working throughout.</>
          )}
          {extensionCount > 0 && (
            <> This trial has already been extended {extensionCount} {extensionCount === 1 ? 'time' : 'times'}.</>
          )}
        </p>

        {/*
          * The service refuses a closed trial with a message under its own key,
          * which the form's generic type has no way to know about.
          */}
        {(errors as Record<string, string>).extension && (
          <p className="rounded-2xl bg-ovs-crm-accent-coral-soft px-4 py-3 text-sm text-ovs-crm-accent-coral-text">
            {(errors as Record<string, string>).extension}
          </p>
        )}

        <div className="flex justify-end gap-2">
          <CrmButton type="button" tone="ghost" onClick={close}>Cancel</CrmButton>
          <CrmButton type="submit" tone="primary" disabled={processing}>
            {processing ? 'Extending…' : 'Extend trial'}
          </CrmButton>
        </div>
      </form>
    </CrmModal>
  );
}
