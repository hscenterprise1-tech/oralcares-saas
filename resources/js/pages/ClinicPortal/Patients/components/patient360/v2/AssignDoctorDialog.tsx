import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/clinic/ui/Button';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Modal } from '@/clinic/ui/Modal';

/**
 * Assign the patient's doctor (`PatientController::transfer`): one of the
 * clinic's practitioners, with an optional reason. The server checks the
 * permission and the practitioner again, keeps the assignment history and
 * writes the timeline.
 */
export function AssignDoctorDialog({
  url,
  practitioners,
  current,
  onClose,
}: {
  url: string;
  practitioners: { id: number; name: string; specialty: string | null }[];
  current: string | null;
  onClose: () => void;
}) {
  const [doctor, setDoctor] = useState('');
  const [reason, setReason] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const save = () =>
    router.post(
      url,
      { to_practitioner_id: Number(doctor), reason: reason.trim() || null },
      {
        preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
        onError: (errors) => setProblem(Object.values(errors as Record<string, string>)[0] ?? 'Not saved.'),
        onSuccess: onClose,
      },
    );

  return (
    <Modal
      open
      onOpenChange={(open) => !open && !busy && onClose()}
      title="Assign doctor"
      description={current ? `Now assigned to ${current}. The change is kept in the patient's history.` : 'No doctor is assigned yet.'}
      size="sm"
      footer={
        <>
          <Button tone="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button tone="primary" loading={busy} disabled={!doctor} onClick={save}>
            Assign
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Doctor" htmlFor="assign-doctor" required error={problem ?? undefined}>
          <Select
            id="assign-doctor"
            value={doctor}
            placeholder="Choose a doctor"
            onChange={(event) => setDoctor(event.target.value)}
            options={practitioners.map((item) => ({ value: String(item.id), label: item.specialty ? `${item.name} · ${item.specialty}` : item.name }))}
          />
        </Field>
        <Field label="Reason (optional)" htmlFor="assign-reason">
          <Input id="assign-reason" value={reason} maxLength={255} onChange={(event) => setReason(event.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}
