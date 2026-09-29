import React, { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Check, Phone } from 'lucide-react';
import { CrmAvatar, CrmButton, CrmCard, CrmIconButton, CrmPageHead, CrmStatusPill } from '@/pages/Company/design';
import { ClinicShell } from '../components/ClinicShell';
import { PatientFields, PatientFormData } from './components/PatientFields';
import { DuplicateMatch, PatientCreateProps } from './types';

/*
 * New patient registration — a full page, not a modal, matching
 * `Clinic/Admin/Employees/Create.tsx`. Duplicate detection is a warning
 * banner rather than a blocking check: legitimate duplicates happen (family
 * members sharing a phone, a patient re-registering under a slightly
 * different name), so the form never refuses to submit over it — it just
 * makes sure the person at the desk has seen the possible match first.
 */

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';
}

export default function CreatePatient({ practitioners, allergyCatalogue, fieldConfig }: PatientCreateProps) {
  const { data, setData, post, processing, errors } = useForm<PatientFormData>({
    first_name: '',
    last_name: '',
    gender: '',
    date_of_birth: '',
    age: '',
    blood_group: '',
    phone: '',
    alternate_phone: '',
    email: '',
    address: '',
    city: '',
    state: '',
    pincode: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    allergies: [],
    medical_alerts: [],
    doctor_id: '',
  });

  const [duplicates, setDuplicates] = useState<DuplicateMatch[]>([]);
  const [checking, setChecking] = useState(false);
  const checkTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const lastCheckedKey = useRef('');

  useEffect(() => () => clearTimeout(checkTimer.current), []);

  useEffect(() => {
    const name = `${data.first_name.trim()} ${data.last_name.trim()}`.trim();
    const hasEnoughToCheck = data.phone.trim().length >= 6 || data.email.trim().length > 3 || (name.length >= 3 && data.date_of_birth);

    if (!hasEnoughToCheck) {
      setDuplicates([]);
      return;
    }

    const key = JSON.stringify({ phone: data.phone, email: data.email, name, dob: data.date_of_birth });
    if (key === lastCheckedKey.current) return;

    clearTimeout(checkTimer.current);
    checkTimer.current = setTimeout(async () => {
      lastCheckedKey.current = key;
      setChecking(true);
      try {
        const res = await fetch(route('clinic.patients.check-duplicate'), {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify({
            phone: data.phone,
            email: data.email,
            first_name: data.first_name,
            last_name: data.last_name,
            date_of_birth: data.date_of_birth,
          }),
        });
        const json = await res.json();
        setDuplicates(json.matches ?? []);
      } catch {
        // A failed duplicate check should never block registration — the
        // banner just doesn't appear, same as if nothing matched.
      } finally {
        setChecking(false);
      }
    }, 400);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data.first_name, data.last_name, data.phone, data.email, data.date_of_birth]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('clinic.patients.store'));
  };

  return (
    <ClinicShell title="Register patient">
      <div className="mx-auto min-w-0 max-w-[900px] space-y-4 pb-16">
        <CrmIconButton icon={ArrowLeft} label="Back to patients" tone="ghost" href={route('clinic.patients.index')} className="mb-2" />

        <CrmPageHead
          className="mb-1"
          eyebrow="Clinic"
          title="Register patient"
          description="A UHID is generated automatically once this is saved."
        />

        {duplicates.length > 0 && (
          <CrmCard className="border border-ovs-crm-stage-coral-border bg-ovs-crm-stage-coral-soft/40 px-5 py-4">
            <div className="flex items-start gap-3">
              <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ovs-crm-stage-coral-soft text-ovs-crm-stage-coral-text">
                <AlertTriangle className="h-4 w-4" aria-hidden="true" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold text-ovs-crm-text-primary">
                  {duplicates.length === 1 ? 'A possible duplicate already exists' : `${duplicates.length} possible duplicates already exist`}
                </p>
                <p className="mt-0.5 text-[12px] text-ovs-crm-text-muted">
                  Check before registering a new record — this doesn't block saving.
                </p>

                <div className="mt-3 space-y-2">
                  {duplicates.map((match) => (
                    <div
                      key={match.id}
                      className="flex flex-wrap items-center justify-between gap-3 rounded-[14px] bg-white px-3 py-2.5 shadow-ovs-crmPanel"
                    >
                      <div className="flex min-w-0 items-center gap-2.5">
                        <CrmAvatar name={match.name} size="sm" />
                        <div className="min-w-0">
                          <p className="truncate text-[12.5px] font-semibold text-ovs-crm-text-primary">{match.name}</p>
                          <p className="truncate text-[11px] text-ovs-crm-text-muted">
                            <span className="tabular-nums">{match.uhid}</span>
                            {match.phone && (
                              <>
                                {' · '}
                                <Phone className="mb-0.5 inline h-2.5 w-2.5" aria-hidden="true" /> {match.phone}
                              </>
                            )}
                          </p>
                        </div>
                      </div>
                      <CrmButton size="sm" href={route('clinic.patients.show', match.id)}>
                        View existing patient →
                      </CrmButton>
                    </div>
                  ))}
                </div>
              </div>
            </div>
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
          />

          <CrmCard className="flex flex-wrap items-center gap-3 px-5 py-4">
            <div className="min-w-0 flex-1">
              {checking ? (
                <CrmStatusPill tone="neutral">Checking for duplicates…</CrmStatusPill>
              ) : (
                <p className="text-[12.5px] text-ovs-crm-text-muted">
                  UHID, status and audit trail are handled automatically once saved.
                </p>
              )}
            </div>
            <CrmButton href={route('clinic.patients.index')}>Cancel</CrmButton>
            <CrmButton
              tone="primary"
              icon={Check}
              loading={processing}
              disabled={!data.first_name.trim() || !data.phone.trim() || (fieldConfig.gender === 'required' && !data.gender)}
              type="submit"
            >
              Register patient
            </CrmButton>
          </CrmCard>
        </form>
      </div>
    </ClinicShell>
  );
}
