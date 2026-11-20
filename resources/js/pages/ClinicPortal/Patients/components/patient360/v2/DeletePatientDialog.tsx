import { useState } from 'react';
import { useOwnerModeStepUp } from '@/clinic/ownerModeStepUp';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';

/**
 * Delete a patient — the governed `patient.delete` (`DeletePatient`): the
 * owner deletes directly (in Owner Mode; the step-up dialog asks), anyone
 * else with the right asks the owner. A reason and an explicit confirmation
 * are required; the server checks everything again. It is a soft delete:
 * the record leaves every list but stays in the database.
 */
export function DeletePatientDialog({
  onClose,
  mode,
  patient,
  endpoints,
}: {
  onClose: () => void;
  mode: 'direct' | 'request';
  patient: { id: number; name: string };
  endpoints: { request: string; direct: string };
}) {
  const stepUp = useOwnerModeStepUp();
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const submit = () => {
    const problems: Record<string, string> = {};
    if (reason.trim().length < 10) {
      problems.reason = 'Give a reason of at least 10 characters.';
    }
    if (!confirmed) {
      problems.confirm = 'Confirm before continuing.';
    }
    setErrors(problems);
    if (Object.keys(problems).length > 0) {
      return;
    }

    stepUp.submit({
      method: 'post',
      url: mode === 'direct' ? endpoints.direct : endpoints.request,
      data: { action: 'patient.delete', target_id: patient.id, reason: reason.trim(), payload: {} },
      onSuccess: onClose,
      onError: (serverErrors) => setErrors(serverErrors as Record<string, string>),
    });
  };

  return (
    <>
      <Modal
        open
        onOpenChange={(open) => !open && !stepUp.busy && onClose()}
        title="Delete patient"
        description={mode === 'direct' ? `${patient.name}. This is recorded with your name and the time.` : `${patient.name}. The clinic owner decides; nothing changes until then.`}
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={onClose} disabled={stepUp.busy}>
              Cancel
            </Button>
            <Button tone="danger" onClick={submit} loading={stepUp.busy} loadingLabel="Saving…" disabled={stepUp.busy}>
              {mode === 'direct' ? 'Delete patient' : 'Send to the owner'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Notice tone="warning">
            The patient leaves the patient list, search and every working screen, upcoming bookings and follow-ups are cancelled, and no messages are sent. Visits, consultations, bills and documents are kept in the clinic's records.
          </Notice>
          {(errors.governance || errors.action) && <Notice tone="danger">{errors.governance || errors.action}</Notice>}
          <Field label="Reason" htmlFor="delete-reason" required hint="For example: duplicate entry, registered by mistake." error={errors.reason || undefined}>
            <Textarea id="delete-reason" value={reason} maxLength={2000} onChange={(event) => setReason(event.target.value)} invalid={!!errors.reason} />
          </Field>
          <div>
            <Checkbox checked={confirmed} onCheckedChange={setConfirmed} label={`I confirm that ${patient.name} should be deleted.`} />
            {errors.confirm && (
              <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
                {errors.confirm}
              </p>
            )}
          </div>
        </div>
      </Modal>
      {stepUp.dialog}
    </>
  );
}
