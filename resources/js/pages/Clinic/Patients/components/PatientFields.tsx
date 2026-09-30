import React from 'react';
import { AlertTriangle, IdCard, MapPin, Phone, ShieldAlert, Stethoscope } from 'lucide-react';
import { CrmAvatar, CrmDatePicker, CrmFormField, CrmInput, CrmSection, CrmSelect, CrmTextarea, toneHex } from '@/pages/Company/design';
import { PractitionerOption } from '../types';
import { AllergyOption, AllergyPicker } from './AllergyPicker';
import { TagChipInput } from './TagChipInput';

/**
 * The field set for both Create and (once it exists) Edit — same shape,
 * only the submit action differs, matching the Employees precedent
 * (`Clinic/Admin/Employees/components/EmployeeFields.tsx`).
 *
 * Which of these actually render, and which are required, is not fixed —
 * it's driven by `fieldConfig`, resolved server-side by
 * `PatientFieldConfigService` and re-enforced there too
 * (`RegisterPatientRequest::rules()` reads the same config), so a clinic's
 * setting is never just cosmetic.
 */

export interface PatientFormData {
  first_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  age: string;
  blood_group: string;
  phone: string;
  alternate_phone: string;
  email: string;
  address: string;
  city: string;
  state: string;
  pincode: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  allergies: string[];
  medical_alerts: string[];
  doctor_id: string;
}

export type FieldState = 'required' | 'optional' | 'hidden';

/** The fields `fieldConfig` actually covers — `first_name`/`phone` are never in it, they're always required. */
export type ConfigurableField =
  | 'last_name'
  | 'date_of_birth'
  | 'gender'
  | 'blood_group'
  | 'alternate_phone'
  | 'email'
  | 'address'
  | 'emergency_contact'
  | 'allergies'
  | 'doctor_id';

export type PatientFieldConfig = Record<ConfigurableField, FieldState>;

const GENDER_OPTIONS = [
  { value: 'male', label: 'Male' },
  { value: 'female', label: 'Female' },
  { value: 'other', label: 'Other' },
];

const BLOOD_GROUP_OPTIONS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

const AREA = {
  identity: toneHex('violet'),
  contact: toneHex('blue'),
  address: toneHex('teal'),
  emergency: toneHex('amber'),
  medical: toneHex('coral'),
  doctor: toneHex('emerald'),
} as const;

/** Age from a birth date, so the two never quietly disagree. */
function ageFromDob(dob: string): string {
  const birth = new Date(dob);
  if (Number.isNaN(birth.getTime())) return '';

  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const hadBirthdayThisYear =
    today.getMonth() > birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() >= birth.getDate());
  if (!hadBirthdayThisYear) age -= 1;

  return age >= 0 ? String(age) : '';
}

interface PatientFieldsProps {
  data: PatientFormData;
  setData: <K extends keyof PatientFormData>(key: K, value: PatientFormData[K]) => void;
  errors: Partial<Record<keyof PatientFormData, string>>;
  practitioners: PractitionerOption[];
  allergyCatalogue: AllergyOption[];
  fieldConfig: PatientFieldConfig;
  /**
   * 'edit' always hides the "Assigned doctor" section, regardless of
   * `fieldConfig` — reassigning an existing patient's doctor goes through
   * the audited Transfer action instead, which is how a doctor change ever
   * gets its own timeline entry and `PatientDoctorAssignment` row. Letting
   * this form silently change it too would bypass that audit trail.
   */
  mode?: 'create' | 'edit';
}

export function PatientFields({ data, setData, errors, practitioners, allergyCatalogue, fieldConfig, mode = 'create' }: PatientFieldsProps) {
  const fullName = `${data.first_name} ${data.last_name}`.trim();

  const isHidden = (field: ConfigurableField) => fieldConfig[field] === 'hidden';
  const isRequired = (field: ConfigurableField) => fieldConfig[field] === 'required';

  return (
    <div className="space-y-4">
      <CrmSection title="Identity" icon={IdCard} hue={AREA.identity}>
        <div className="mb-4 flex items-center gap-3">
          <CrmAvatar name={fullName || '?'} size="lg" />
          <p className="text-[12.5px] text-ovs-crm-text-muted">
            {fullName
              ? mode === 'edit'
                ? `Editing ${fullName}`
                : `Registering ${fullName}`
              : "This patient's colour is assigned automatically from their name."}
          </p>
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CrmFormField label="First name" required error={errors.first_name}>
            <CrmInput value={data.first_name} onChange={(e) => setData('first_name', e.target.value)} placeholder="e.g. Rohan" autoFocus />
          </CrmFormField>

          {!isHidden('last_name') && (
            <CrmFormField label="Last name" required={isRequired('last_name')} error={errors.last_name}>
              <CrmInput value={data.last_name} onChange={(e) => setData('last_name', e.target.value)} placeholder="e.g. Mehta" />
            </CrmFormField>
          )}

          {!isHidden('gender') && (
            <CrmFormField label="Gender" required={isRequired('gender')} error={errors.gender}>
              <CrmSelect
                value={data.gender}
                onValueChange={(v) => setData('gender', v)}
                placeholder="Select gender"
                options={GENDER_OPTIONS}
              />
            </CrmFormField>
          )}

          {!isHidden('blood_group') && (
            <CrmFormField label="Blood group" required={isRequired('blood_group')} error={errors.blood_group}>
              <CrmSelect value={data.blood_group} onValueChange={(v) => setData('blood_group', v)} placeholder="Unknown">
                {BLOOD_GROUP_OPTIONS.map((bg) => (
                  <option key={bg} value={bg}>
                    {bg}
                  </option>
                ))}
              </CrmSelect>
            </CrmFormField>
          )}

          {!isHidden('date_of_birth') && (
            <CrmFormField
              label="Date of birth"
              required={isRequired('date_of_birth')}
              error={errors.date_of_birth}
              hint="Sets age automatically."
            >
              <CrmDatePicker
                value={data.date_of_birth}
                max={new Date().toISOString().slice(0, 10)}
                onChange={(e) => {
                  setData('date_of_birth', e.target.value);
                  setData('age', e.target.value ? ageFromDob(e.target.value) : '');
                }}
              />
            </CrmFormField>
          )}

          <CrmFormField label="Age" error={errors.age} hint="Only if the date of birth isn't known.">
            <CrmInput
              type="number"
              min={0}
              max={150}
              value={data.age}
              onChange={(e) => setData('age', e.target.value)}
              placeholder="e.g. 41"
            />
          </CrmFormField>
        </div>
      </CrmSection>

      <CrmSection title="Contact" icon={Phone} hue={AREA.contact}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <CrmFormField label="Phone" required error={errors.phone}>
            <CrmInput value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="98765 43210" />
          </CrmFormField>

          {!isHidden('alternate_phone') && (
            <CrmFormField label="Alternate phone" required={isRequired('alternate_phone')} error={errors.alternate_phone}>
              <CrmInput value={data.alternate_phone} onChange={(e) => setData('alternate_phone', e.target.value)} placeholder="Optional" />
            </CrmFormField>
          )}

          {!isHidden('email') && (
            <CrmFormField label="Email" required={isRequired('email')} error={errors.email} className="sm:col-span-2">
              <CrmInput type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} placeholder="name@example.com" />
            </CrmFormField>
          )}
        </div>
      </CrmSection>

      {!isHidden('address') && (
        <CrmSection title="Address" icon={MapPin} hue={AREA.address}>
          <div className="grid grid-cols-1 gap-4">
            <CrmFormField label="Address" required={isRequired('address')} error={errors.address}>
              <CrmTextarea value={data.address} onChange={(e) => setData('address', e.target.value)} rows={2} placeholder="Street, area" />
            </CrmFormField>

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <CrmFormField label="City" error={errors.city}>
                <CrmInput value={data.city} onChange={(e) => setData('city', e.target.value)} />
              </CrmFormField>
              <CrmFormField label="State" error={errors.state}>
                <CrmInput value={data.state} onChange={(e) => setData('state', e.target.value)} />
              </CrmFormField>
              <CrmFormField label="Pincode" error={errors.pincode}>
                <CrmInput value={data.pincode} onChange={(e) => setData('pincode', e.target.value)} />
              </CrmFormField>
            </div>
          </div>
        </CrmSection>
      )}

      {!isHidden('emergency_contact') && (
        <CrmSection title="Emergency contact" icon={AlertTriangle} hue={AREA.emergency}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <CrmFormField label="Name" required={isRequired('emergency_contact')} error={errors.emergency_contact_name}>
              <CrmInput
                value={data.emergency_contact_name}
                onChange={(e) => setData('emergency_contact_name', e.target.value)}
                placeholder="Who to call"
              />
            </CrmFormField>
            <CrmFormField label="Phone" required={isRequired('emergency_contact')} error={errors.emergency_contact_phone}>
              <CrmInput
                value={data.emergency_contact_phone}
                onChange={(e) => setData('emergency_contact_phone', e.target.value)}
                placeholder="98765 43210"
              />
            </CrmFormField>
          </div>
        </CrmSection>
      )}

      <CrmSection
        title="Medical"
        description="Allergies and safety alerts appear as coloured flags on this patient's record everywhere it's shown."
        icon={ShieldAlert}
        hue={AREA.medical}
      >
        <div className="space-y-4">
          {!isHidden('allergies') && (
            <CrmFormField label="Allergies" required={isRequired('allergies')} error={errors.allergies}>
              <AllergyPicker catalogue={allergyCatalogue} value={data.allergies} onChange={(next) => setData('allergies', next)} />
            </CrmFormField>
          )}

          <CrmFormField label="Alerts" error={errors.medical_alerts} hint="Anything front-desk or clinical staff should see immediately.">
            <TagChipInput
              value={data.medical_alerts}
              onChange={(next) => setData('medical_alerts', next)}
              placeholder="e.g. Fall risk"
              tone="amber"
            />
          </CrmFormField>
        </div>
      </CrmSection>

      {mode === 'create' && !isHidden('doctor_id') && (
        <CrmSection
          title="Assigned doctor"
          description="Optional — books this patient's first visit with the doctor picked below."
          icon={Stethoscope}
          hue={AREA.doctor}
        >
          <CrmFormField label="Doctor" required={isRequired('doctor_id')} error={errors.doctor_id}>
            <CrmSelect value={data.doctor_id} onValueChange={(v) => setData('doctor_id', v)} placeholder="No doctor assigned yet">
              {practitioners.map((p) => (
                <option key={p.id} value={String(p.id)}>
                  {p.name} — {p.specialty}
                </option>
              ))}
            </CrmSelect>
          </CrmFormField>
        </CrmSection>
      )}
    </div>
  );
}

export default PatientFields;
