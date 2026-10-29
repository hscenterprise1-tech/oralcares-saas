import { useState } from 'react';
import { useOwnerModeStepUp } from '@/clinic/ownerModeStepUp';
import { Button } from '@/clinic/ui/Button';
import { Checkbox, Field, Input, Textarea } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';
import { Notice } from '@/clinic/ui/Notice';
import { localDateKey } from '@/clinic/ui/visuals';

/**
 * Record a patient's death, or correct a death recorded in error. Both are
 * governed actions (`RecordDeath`, `CorrectDeath`): the owner records it
 * directly (in Owner Mode for a doctor-owner — the step-up dialog asks),
 * anyone else with the right asks the owner. A reason and an explicit
 * confirmation are required; the server checks everything again.
 */
export function DeathRecordDialog({
  open,
  onOpenChange,
  kind,
  mode,
  patient,
  endpoints,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  kind: 'record' | 'correct';
  mode: 'direct' | 'request';
  patient: { id: number; name: string; dateOfDeath: string | null };
  endpoints: { request: string; direct: string };
}) {
  const stepUp = useOwnerModeStepUp();
  const [date, setDate] = useState('');
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const recording = kind === 'record';

  const close = (next: boolean) => {
    onOpenChange(next);
    if (!next) {
      setDate('');
      setReason('');
      setConfirmed(false);
      setErrors({});
    }
  };

  const submit = () => {
    const problems: Record<string, string> = {};
    if (recording && date === '') {
      problems.date_of_death = 'Give the date of death.';
    }
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
      data: {
        action: recording ? 'patient.record_death' : 'patient.correct_death',
        target_id: patient.id,
        reason: reason.trim(),
        payload: recording ? { date_of_death: date } : {},
      },
      onSuccess: () => close(false),
      onError: (serverErrors) => setErrors(serverErrors as Record<string, string>),
    });
  };

  const title = recording ? 'Record death' : 'Correct the recorded death';
  const action = mode === 'direct' ? (recording ? 'Record death' : 'Correct record') : 'Send to the owner';

  return (
    <>
      <Modal
        open={open}
        onOpenChange={close}
        title={title}
        description={mode === 'direct' ? `For ${patient.name}. This is recorded with your name and the time.` : `For ${patient.name}. The clinic owner decides; nothing changes until then.`}
        size="sm"
        footer={
          <>
            <Button tone="ghost" onClick={() => close(false)} disabled={stepUp.busy}>
              Cancel
            </Button>
            <Button tone={recording ? 'danger' : 'primary'} onClick={submit} loading={stepUp.busy} loadingLabel="Saving…" disabled={stepUp.busy}>
              {action}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Notice tone={recording ? 'warning' : 'info'}>
            {recording
              ? 'Once recorded, no new visits, bookings, consultations, prescriptions, orders or charges can be made for this patient, upcoming bookings and follow-ups are cancelled, and no messages are sent. Every record stays readable.'
              : `The patient becomes a current patient again. The death recorded${patient.dateOfDeath ? ` for ${patient.dateOfDeath}` : ''} stays in the patient's history. Cancelled bookings are not restored.`}
          </Notice>
          {(errors.governance || errors.action) && <Notice tone="danger">{errors.governance || errors.action}</Notice>}
          {recording && (
            <Field label="Date of death" htmlFor="death-date" required error={errors.date_of_death || errors['payload.date_of_death'] || undefined}>
              <Input id="death-date" type="date" value={date} max={localDateKey()} onChange={(event) => setDate(event.target.value)} invalid={!!errors.date_of_death} />
            </Field>
          )}
          <Field label="Reason" htmlFor="death-reason" required hint={recording ? 'How the clinic learned of it (no medical cause is needed).' : 'Why the record was wrong.'} error={errors.reason || undefined}>
            <Textarea id="death-reason" value={reason} maxLength={2000} onChange={(event) => setReason(event.target.value)} invalid={!!errors.reason} />
          </Field>
          <div>
            <Checkbox
              checked={confirmed}
              onCheckedChange={setConfirmed}
              label={recording ? `I confirm that ${patient.name} has died.` : 'I confirm the recorded death was entered in error.'}
            />
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
