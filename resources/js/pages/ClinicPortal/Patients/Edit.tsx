import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, HeartHandshake, MapPin, Phone, Save, ShieldAlert, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Notice } from '@/clinic/ui/Notice';
import { PatientAvatar } from './components/patientVisuals';

type FieldState = 'required' | 'optional' | 'hidden';

type EditablePatient = {
  id: number;
  uhid: string | null;
  status: string;
  first_name: string;
  middle_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  age: number | null;
  age_is_estimated: boolean;
  blood_group: string;
  phone: string;
  alternate_phone: string;
  email: string;
  address: string;
  locality: string;
  city: string;
  state: string;
  pincode: string;
  country: string;
  preferred_channel: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  allergies: string[];
  medical_alerts: string[];
  current_practitioner_name: string | null;
  registered_on: string | null;
};

type EditProps = {
  patient: EditablePatient;
  clinical: { can_view: boolean; can_edit: boolean };
  /** The clinic's field settings for this member (`PatientFieldConfigService`). */
  fieldConfig: Record<string, FieldState>;
  channels: string[];
};

/** The form's fields and the setting each follows. */
const SETTING: Record<string, string> = {
  last_name: 'last_name',
  gender: 'gender',
  date_of_birth: 'date_of_birth',
  blood_group: 'blood_group',
  alternate_phone: 'alternate_phone',
  email: 'email',
  address: 'address',
  locality: 'address_locality',
  city: 'address_city',
  state: 'address_state',
  pincode: 'address_pincode',
  country: 'address_country',
  emergency_contact_name: 'emergency_contact',
  emergency_contact_phone: 'emergency_contact',
};

const EDITABLE = [
  'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth', 'age', 'blood_group', 'phone', 'alternate_phone', 'email',
  'address', 'locality', 'city', 'state', 'pincode', 'country', 'preferred_channel', 'emergency_contact_name', 'emergency_contact_phone',
] as const;

type FormData = Record<(typeof EDITABLE)[number], string>;

const CHANNEL_LABEL: Record<string, string> = { phone: 'Phone call', sms: 'SMS', whatsapp: 'WhatsApp', email: 'Email' };

/**
 * Patient 360 → Edit: the patient's demographics, contact, address and
 * emergency contact, as `PatientController@edit` sends them and `@update`
 * saves them. The clinic's field settings decide what is shown and required;
 * a field left out keeps its stored value. Allergies and alerts are part of
 * the clinical record and are changed there, so they are shown, not edited.
 */
export default function PatientEdit(props: EditProps) {
  return (
    <ClinicPortalShell title={`Edit ${[props.patient.first_name, props.patient.last_name].filter(Boolean).join(' ')}`}>
      <EditForm {...props} />
    </ClinicPortalShell>
  );
}

function EditForm({ patient, clinical, fieldConfig, channels }: EditProps) {
  const initial = Object.fromEntries(EDITABLE.map((key) => [key, key === 'age' ? (patient.age_is_estimated && patient.age !== null ? String(patient.age) : '') : String(patient[key] ?? '')])) as FormData;
  const [data, setData] = useState<FormData>(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const name = [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ');
  const profileUrl = route('clinic.patients.show', patient.id);

  const state = (field: string): FieldState => (SETTING[field] ? (fieldConfig[SETTING[field]] ?? 'optional') : 'optional');
  const shown = (field: string) => state(field) !== 'hidden';
  const isRequired = (field: string) => field === 'first_name' || field === 'phone' || state(field) === 'required';
  const set = (field: keyof FormData) => (value: string) => {
    setData((current) => ({ ...current, [field]: value }));
    setErrors((current) => ({ ...current, [field]: '' }));
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (saving) {
      return;
    }
    // Only the fields this member sees are sent; the rest keep their stored values.
    const payload: Record<string, string | null> = {};
    EDITABLE.forEach((field) => {
      if (field === 'age') {
        if (shown('date_of_birth') && data.date_of_birth === '' && data.age !== '') {
          payload.age = data.age;
        }

        return;
      }
      if (field === 'first_name' || field === 'middle_name' || field === 'phone' || field === 'preferred_channel' || shown(field)) {
        payload[field] = data[field] === '' ? null : data[field];
      }
    });
    setFormError(null);
    router.put(route('clinic.patients.update', patient.id), payload as never, {
      preserveScroll: true,
      onStart: () => setSaving(true),
      onFinish: () => setSaving(false),
      onError: (serverErrors) => {
        setErrors(serverErrors as Record<string, string>);
        setFormError('Some details need attention.');
      },
      onHttpException: () => {
        setFormError('The changes were not saved. Try again; nothing you entered was lost.');

        return false;
      },
      onNetworkError: () => {
        setFormError('The changes were not saved. Check the connection and try again.');

        return false;
      },
    });
  };

  const text = (field: keyof FormData, label: string, props: { type?: string; inputMode?: 'tel' | 'email' | 'numeric'; autoComplete?: string; maxLength?: number } = {}) =>
    (field === 'first_name' || field === 'middle_name' || field === 'phone' || shown(field)) && (
      <Field label={label} htmlFor={`edit-${field}`} required={isRequired(field)} error={errors[field] || undefined}>
        <Input
          id={`edit-${field}`}
          value={data[field]}
          onChange={(event) => set(field)(event.target.value)}
          invalid={!!errors[field]}
          required={isRequired(field)}
          {...props}
        />
      </Field>
    );

  return (
    <div className="mx-auto w-full max-w-[1080px]">
      <Link
        href={profileUrl}
        className="-ml-2 inline-flex h-9 items-center gap-1.5 rounded-sm px-2 text-[13.5px] font-semibold text-cx-text-2 hover:bg-white hover:text-cx-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan [@media(pointer:coarse)]:h-11"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Back to {name}
      </Link>

      <div className="mt-2 flex items-center gap-3.5">
        <PatientAvatar name={name} size="lg" />
        <div className="min-w-0">
          <h1 className="text-[22px] font-bold leading-7 tracking-[-0.02em] text-cx-text sm:text-[24px]">Edit patient details</h1>
          <p className="text-[13.5px] text-cx-text-2">
            {name}
            {patient.uhid && <span> · UHID {patient.uhid}</span>}
          </p>
        </div>
      </div>

      <form noValidate onSubmit={submit} className="mt-4 grid gap-4">
        {formError && <Notice tone="danger">{formError}</Notice>}

        <Group title="Identity" icon={UserRound} tone="teal">
          <div className="grid gap-4 sm:grid-cols-3">
            {text('first_name', 'First name', { autoComplete: 'given-name', maxLength: 100 })}
            {text('middle_name', 'Middle name', { autoComplete: 'additional-name', maxLength: 100 })}
            {text('last_name', 'Last name', { autoComplete: 'family-name', maxLength: 100 })}
          </div>
          <div className="mt-4 grid gap-4 sm:grid-cols-3">
            {shown('gender') && (
              <Field label="Sex" htmlFor="edit-gender" required={isRequired('gender')} error={errors.gender || undefined}>
                <Select id="edit-gender" value={data.gender} onChange={(event) => set('gender')(event.target.value)} invalid={!!errors.gender} placeholder="Choose" options={[{ value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }]} />
              </Field>
            )}
            {shown('date_of_birth') && (
              <Field label="Date of birth" htmlFor="edit-date_of_birth" required={isRequired('date_of_birth')} error={errors.date_of_birth || undefined} hint={data.date_of_birth === '' ? 'Or give an age below.' : undefined}>
                <Input id="edit-date_of_birth" type="date" value={data.date_of_birth} onChange={(event) => set('date_of_birth')(event.target.value)} invalid={!!errors.date_of_birth} max={new Date().toISOString().slice(0, 10)} />
              </Field>
            )}
            {shown('date_of_birth') && data.date_of_birth === '' && (
              <Field label="Age (years)" htmlFor="edit-age" error={errors.age || undefined}>
                <Input id="edit-age" inputMode="numeric" value={data.age} onChange={(event) => set('age')(event.target.value.replace(/\D/g, '').slice(0, 3))} invalid={!!errors.age} />
              </Field>
            )}
            {shown('blood_group') && (
              <Field label="Blood group" htmlFor="edit-blood_group" required={isRequired('blood_group')} error={errors.blood_group || undefined}>
                <Select id="edit-blood_group" value={data.blood_group} onChange={(event) => set('blood_group')(event.target.value)} invalid={!!errors.blood_group} placeholder="Not recorded" options={['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((group) => ({ value: group, label: group }))} />
              </Field>
            )}
          </div>
        </Group>

        <Group title="Contact" icon={Phone} tone="blue">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {text('phone', 'Mobile', { type: 'tel', inputMode: 'tel', autoComplete: 'tel', maxLength: 20 })}
            {text('alternate_phone', 'Alternate mobile', { type: 'tel', inputMode: 'tel', maxLength: 20 })}
            {text('email', 'Email', { type: 'email', inputMode: 'email', autoComplete: 'email', maxLength: 255 })}
            <Field label="Preferred contact" htmlFor="edit-preferred_channel" error={errors.preferred_channel || undefined}>
              <Select id="edit-preferred_channel" value={data.preferred_channel} onChange={(event) => set('preferred_channel')(event.target.value)} placeholder="No preference" options={channels.map((channel) => ({ value: channel, label: CHANNEL_LABEL[channel] ?? channel }))} />
            </Field>
          </div>
        </Group>

        {(['address', 'locality', 'city', 'state', 'pincode', 'country'] as const).some(shown) && (
          <Group title="Address" icon={MapPin} tone="violet">
            <div className="grid gap-4 sm:grid-cols-2">
              {text('address', 'Address', { autoComplete: 'street-address', maxLength: 500 })}
              {text('locality', 'Locality', { maxLength: 120 })}
            </div>
            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {text('city', 'City', { autoComplete: 'address-level2', maxLength: 100 })}
              {text('state', 'State', { autoComplete: 'address-level1', maxLength: 100 })}
              {text('pincode', 'PIN code', { inputMode: 'numeric', autoComplete: 'postal-code', maxLength: 10 })}
              {text('country', 'Country', { autoComplete: 'country-name', maxLength: 80 })}
            </div>
          </Group>
        )}

        {shown('emergency_contact_name') && (
          <Group title="Emergency contact" icon={HeartHandshake} tone="amber">
            <div className="grid gap-4 sm:grid-cols-2">
              {text('emergency_contact_name', 'Name', { maxLength: 100 })}
              {text('emergency_contact_phone', 'Mobile', { type: 'tel', inputMode: 'tel', maxLength: 20 })}
            </div>
          </Group>
        )}

        {clinical.can_view && (
          <Group title="Medical safety" icon={ShieldAlert} tone="rose">
            <p className="text-[13px] text-cx-text-2">Allergies and alerts are part of the clinical record and are changed there.</p>
            <div className="mt-3 flex flex-wrap gap-1.5">
              {patient.allergies.length + patient.medical_alerts.length === 0 ? (
                <span className="text-[13.5px] text-cx-text-2">None recorded</span>
              ) : (
                <>
                  {patient.allergies.map((allergy) => (
                    <span key={`a-${allergy}`} className="inline-flex min-h-7 items-center rounded-full bg-cx-rose-bg px-2.5 py-0.5 text-[12.5px] font-semibold text-cx-rose-ink">
                      {allergy}
                    </span>
                  ))}
                  {patient.medical_alerts.map((alert) => (
                    <span key={`m-${alert}`} className="inline-flex min-h-7 items-center rounded-full bg-cx-amber-bg px-2.5 py-0.5 text-[12.5px] font-semibold text-cx-amber-ink">
                      {alert}
                    </span>
                  ))}
                </>
              )}
            </div>
          </Group>
        )}

        <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t border-cx-line bg-white/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:rounded-2xl sm:border sm:shadow-cx-card">
          <Link href={profileUrl} className="mr-auto inline-flex h-10 items-center rounded-[10px] px-3 text-[14px] font-semibold text-cx-text-2 hover:bg-cx-slate-bg hover:text-cx-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cx-cyan max-sm:h-11">
            Cancel
          </Link>
          <Button type="submit" tone="primary" icon={Save} loading={saving} loadingLabel="Saving…" disabled={saving}>
            Save changes
          </Button>
        </div>
      </form>
    </div>
  );
}

const GROUP_TILE = {
  teal: 'bg-cx-m-teal-bg text-cx-m-teal',
  blue: 'bg-cx-m-blue-bg text-cx-m-blue',
  violet: 'bg-cx-m-purple-bg text-cx-m-purple',
  amber: 'bg-cx-m-orange-bg text-cx-m-orange',
  rose: 'bg-cx-rose-bg text-cx-rose-ink',
} as const;

function Group({ title, icon: Icon, tone, children }: { title: string; icon: typeof UserRound; tone: keyof typeof GROUP_TILE; children: ReactNode }) {
  const id = `edit-${title.toLowerCase().replace(/\W+/g, '-')}`;

  return (
    <Card aria-labelledby={id} className="p-4 sm:p-5">
      <h2 id={id} className="mb-4 flex items-center gap-3 text-[16px] font-semibold text-cx-text">
        <span className={cn('grid h-9 w-9 place-items-center rounded-[11px]', GROUP_TILE[tone])} aria-hidden="true">
          <Icon className="h-[18px] w-[18px]" strokeWidth={1.9} />
        </span>
        {title}
      </h2>
      {children}
    </Card>
  );
}
