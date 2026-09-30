import React from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Stethoscope } from 'lucide-react';
import { CrmButton, CrmCard, CrmIconButton, CrmPageHead, toneHex } from '@/pages/Company/design';
import { ClinicShell } from '../components/ClinicShell';
import { PatientFields, PatientFormData } from './components/PatientFields';
import { PatientEditProps } from './types';

/*
 * Edit patient — same `PatientFields` component Create uses (via `mode="edit"`,
 * which hides the "Assigned doctor" section: reassigning an existing
 * patient's doctor is the Transfer action's job, not this form's, since
 * only Transfer writes the audit trail for it), posted to the same
 * `PatientController::update()` action, with the same field-config
 * enforcement (`fieldConfig`, resolved server-side and re-enforced by
 * `UpdatePatientRequest::rules()`) as registration.
 */

export default function EditPatient({ patient, practitioners, allergyCatalogue, fieldConfig }: PatientEditProps) {
  const { data, setData, put, processing, errors } = useForm<PatientFormData>({
    first_name: patient.first_name,
    last_name: patient.last_name,
    gender: patient.gender,
    date_of_birth: patient.date_of_birth,
    age: patient.age,
    blood_group: patient.blood_group,
    phone: patient.phone,
    alternate_phone: patient.alternate_phone,
    email: patient.email,
    address: patient.address,
    city: patient.city,
    state: patient.state,
    pincode: patient.pincode,
    emergency_contact_name: patient.emergency_contact_name,
    emergency_contact_phone: patient.emergency_contact_phone,
    allergies: patient.allergies,
    medical_alerts: patient.medical_alerts,
    doctor_id: '',
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(route('clinic.patients.update', patient.id));
  };

  return (
    <ClinicShell title={`Edit ${patient.first_name}`.trim()}>
      <div className="mx-auto min-w-0 max-w-[900px] space-y-4 pb-16">
        <CrmIconButton
          icon={ArrowLeft}
          label="Back to patient"
          tone="ghost"
          href={route('clinic.patients.show', patient.id)}
          className="mb-2"
        />

        <CrmPageHead
          className="mb-1"
          eyebrow="Clinic"
          title={`Edit ${patient.first_name} ${patient.last_name}`.trim()}
          description={`UHID ${patient.uhid}`}
        />

        {patient.current_practitioner_name && (
          <CrmCard className="flex flex-wrap items-center gap-3 px-5 py-4">
            <span
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
              style={{ backgroundColor: `${toneHex('emerald')}1f`, color: toneHex('emerald') }}
            >
              <Stethoscope className="h-4 w-4" aria-hidden="true" />
            </span>
            <p className="min-w-0 flex-1 text-[12.5px] text-ovs-crm-text-muted">
              Currently assigned to <span className="font-medium text-ovs-crm-text-primary">{patient.current_practitioner_name}</span>.
              Use <span className="font-medium text-ovs-crm-text-primary">Transfer</span> on the patient page to reassign.
            </p>
          </CrmCard>
        )}

        <form onSubmit={handleSubmit} className="space-y-4">
          <PatientFields
            data={data}
            setData={setData}
            errors={errors}
            practitioners={practitioners}
            allergyCatalogue={allergyCatalogue}
            fieldConfig={fieldConfig}
            mode="edit"
          />

          <CrmCard className="flex flex-wrap items-center gap-3 px-5 py-4">
            <p className="min-w-0 flex-1 text-[12.5px] text-ovs-crm-text-muted">Changes are recorded on this patient's activity timeline.</p>
            <CrmButton href={route('clinic.patients.show', patient.id)}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              icon={Check}
              loading={processing}
              disabled={!data.first_name.trim() || !data.phone.trim() || (fieldConfig.gender === 'required' && !data.gender)}
              type="submit"
            >
              Save changes
            </CrmButton>
          </CrmCard>
        </form>
      </div>
    </ClinicShell>
  );
}
