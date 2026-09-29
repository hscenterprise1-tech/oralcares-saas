import React, { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { CrmButton, CrmFormField, CrmModal, CrmSelect, CrmTextarea } from '@/pages/Company/design';
import { PractitionerOption } from '../types';

/**
 * The only way `current_practitioner_id` ever changes after registration —
 * Reception/Admin only (gated by the same `can_transfer_patient` permission
 * Show.tsx already checked before rendering the trigger button). Writes
 * both the live pointer and a `PatientDoctorAssignment` audit row
 * server-side (`PatientController::transfer()`), so this form only needs
 * to collect who it's going to and why.
 */

interface TransferFormData {
  to_practitioner_id: string;
  reason: string;
}

export function TransferPatientModal({
  open,
  onClose,
  patientId,
  patientName,
  currentPractitionerName,
  practitioners,
}: {
  open: boolean;
  onClose: () => void;
  patientId: number;
  patientName: string;
  currentPractitionerName: string | null;
  practitioners: PractitionerOption[];
}) {
  const { data, setData, post, processing, errors, reset, clearErrors } = useForm<TransferFormData>({
    to_practitioner_id: '',
    reason: '',
  });

  useEffect(() => {
    if (open) {
      reset();
      clearErrors();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const submit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('clinic.patients.transfer', patientId), {
      preserveScroll: true,
      onSuccess: () => onClose(),
    });
  };

  return (
    <CrmModal
      open={open}
      onClose={onClose}
      title="Transfer patient"
      description={`Reassign ${patientName} to a different doctor. This is recorded on their activity timeline.`}
      size="sm"
      footer={
        <>
          <CrmButton onClick={onClose}>Cancel</CrmButton>
          <CrmButton tone="primary" loading={processing} disabled={!data.to_practitioner_id} onClick={submit}>
            Transfer
          </CrmButton>
        </>
      }
    >
      <form onSubmit={submit} className="space-y-4">
        <CrmFormField label="Currently assigned to">
          <p className="text-[13px] text-ovs-crm-text-secondary">{currentPractitionerName ?? 'No doctor assigned yet'}</p>
        </CrmFormField>

        <CrmFormField label="Transfer to" required error={errors.to_practitioner_id}>
          <CrmSelect value={data.to_practitioner_id} onValueChange={(v) => setData('to_practitioner_id', v)} placeholder="Select a doctor">
            {practitioners.map((p) => (
              <option key={p.id} value={String(p.id)}>
                {p.name} — {p.specialty}
              </option>
            ))}
          </CrmSelect>
        </CrmFormField>

        <CrmFormField label="Reason" error={errors.reason} hint="Optional — visible on the patient's timeline.">
          <CrmTextarea value={data.reason} onChange={(e) => setData('reason', e.target.value)} rows={2} placeholder="e.g. Patient requested a different doctor" />
        </CrmFormField>
      </form>
    </CrmModal>
  );
}

export default TransferPatientModal;
