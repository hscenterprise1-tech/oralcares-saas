import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Home, Phone, Save, ShieldAlert, Stethoscope, UserRound, type LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Field, Input, Select } from '@/clinic/ui/controls';
import { Notice } from '@/clinic/ui/Notice';
import { validPhone } from '@/pages/Clinic/Reception/intake/PatientFields';
import { BTN, BTN_PRIMARY, FOCUS, IconTile, Pill, day } from './components/patient360/v2/ui';

export interface EditablePatient {
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
  current_practitioner_name: string | null;
  registered_on: string | null;
  documents?: { id: number; file_name: string; document_type: string; size_bytes: number | null; created_at: string | null; download_url: string }[];
}

interface EditProps {
  patient: EditablePatient;
  fieldConfig?: Record<string, string>;
  channels?: string[];
  links?: { index: string; show?: string };
}

export default function PatientEdit(props: EditProps) {
  const name = [props.patient.first_name, props.patient.last_name].filter(Boolean).join(' ');

  return (
    <ClinicPortalShell title={`Edit ${name}`}>
      <EditForm {...props} />
    </ClinicPortalShell>
  );
}

const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

const GROUP_LOOK: Record<'blue' | 'teal' | 'violet' | 'rose', { band: string; ink: string; edge: string }> = {
  blue: { band: 'bg-gradient-to-r from-[#EEF5FF] via-white to-white', ink: 'text-cx-blue-ink', edge: 'before:bg-[#2B6FD8]' },
  teal: { band: 'bg-gradient-to-r from-[#E8FAF6] via-white to-white', ink: 'text-cx-teal-ink', edge: 'before:bg-[#0E9F87]' },
  violet: { band: 'bg-gradient-to-r from-[#F3EEFF] via-white to-white', ink: 'text-cx-violet-ink', edge: 'before:bg-[#6B48DE]' },
  rose: { band: 'bg-gradient-to-r from-[#FFF0F1] via-white to-white', ink: 'text-cx-rose-ink', edge: 'before:bg-[#E5484D]' },
};

/** A coloured band of the one form: its heading on the left (stacked on small screens), its fields on a grid. */
function Group({ icon, tone, title, children }: { icon: LucideIcon; tone: keyof typeof GROUP_LOOK; title: string; children: ReactNode }) {
  const look = GROUP_LOOK[tone];

  return (
    <fieldset className={cn("relative grid gap-x-5 gap-y-3 border-t border-[#EDF1F7] px-5 py-5 first:border-t-0 before:absolute before:inset-y-4 before:left-0 before:w-1 before:rounded-r-full before:content-[''] sm:px-6 xl:grid-cols-[170px_minmax(0,1fr)]", look.band, look.edge)}>
      <legend className="sr-only">{title}</legend>
      <p aria-hidden="true" className={cn('flex items-center gap-2.5 text-[13px] font-semibold xl:items-start xl:pt-0.5', look.ink)}>
        <IconTile icon={icon} tone={tone} size="sm" />
        <span className="pt-0.5">{title}</span>
      </p>
      <div className="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">{children}</div>
    </fieldset>
  );
}

function Segmented({ value, options, onChange, label, invalid }: { value: string; options: { value: string; label: string }[]; onChange: (value: string) => void; label: string; invalid?: boolean }) {
  return (
    <div role="radiogroup" aria-label={label} aria-invalid={invalid || undefined} className={cn('flex h-11 w-full rounded-xl bg-[#F1F5FA] p-1', invalid && 'ring-1 ring-cx-rose-ink')}>
      {options.map((option) => (
        <button
          key={option.value}
          type="button"
          role="radio"
          aria-checked={value === option.value}
          onClick={() => onChange(option.value)}
          className={cn('flex-1 rounded-lg text-[13px] font-semibold transition-colors', FOCUS, value === option.value ? 'bg-white text-cx-nav-ink shadow-[0_1px_3px_rgba(15,23,42,0.12)]' : 'text-cx-text-2 hover:text-cx-nav-ink')}
        >
          {option.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Editing a patient's details (not their clinical record or documents —
 * those are kept in Patient 360): one compact form in coloured bands,
 * fields side by side, every clinic field setting (hidden, required)
 * respected. Saving returns to Patient 360.
 */
function EditForm({ patient, fieldConfig = {}, channels = [], links }: EditProps) {
  const profileUrl = links?.show ?? route('clinic.patients.show', patient.id);
  const shown = (field: string) => fieldConfig[field] !== 'hidden';
  const required = (field: string) => fieldConfig[field] === 'required';

  const initial = useMemo(
    () => ({
      first_name: patient.first_name ?? '',
      middle_name: patient.middle_name ?? '',
      last_name: patient.last_name ?? '',
      gender: patient.gender || '',
      date_of_birth: patient.date_of_birth ?? '',
      age_known: !(patient.age_is_estimated && patient.age !== null),
      age: patient.age_is_estimated && patient.age !== null ? String(patient.age) : '',
      blood_group: patient.blood_group ?? '',
      phone: patient.phone ?? '',
      alternate_phone: patient.alternate_phone ?? '',
      email: patient.email ?? '',
      preferred_channel: patient.preferred_channel ?? '',
      address: patient.address ?? '',
      locality: patient.locality ?? '',
      city: patient.city ?? '',
      state: patient.state ?? '',
      pincode: patient.pincode ?? '',
      country: patient.country ?? '',
      emergency_contact_name: patient.emergency_contact_name ?? '',
      emergency_contact_phone: patient.emergency_contact_phone ?? '',
    }),
    [patient],
  );
  const [form, setForm] = useState(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [problem, setProblem] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const leaving = useRef(false);

  const set = <K extends keyof typeof initial>(field: K, value: (typeof initial)[K]) => {
    setForm((current) => ({ ...current, [field]: value }));
    setErrors((current) => ({ ...current, [field]: '' }));
  };
  const dirty = JSON.stringify(form) !== JSON.stringify(initial);

  useEffect(() => {
    if (!dirty) {
      return;
    }
    const removeBefore = router.on('before', (event) => {
      if (leaving.current || event.detail.visit.method !== 'get') {
        return;
      }
      if (!window.confirm('Leave without saving? Your changes will be lost.')) {
        event.preventDefault();
      }
    });
    const onUnload = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', onUnload);

    return () => {
      removeBefore();
      window.removeEventListener('beforeunload', onUnload);
    };
  }, [dirty]);

  const validate = (): Record<string, string> => {
    const found: Record<string, string> = {};
    if (!form.first_name.trim()) {
      found.first_name = 'Enter the first name.';
    }
    if (required('last_name') && !form.last_name.trim()) {
      found.last_name = 'Enter the last name.';
    }
    if (required('gender') && !form.gender) {
      found.gender = 'Choose a gender.';
    }
    if (shown('date_of_birth')) {
      if (!form.age_known) {
        const age = Number(form.age);
        if (form.age === '' || Number.isNaN(age) || age < 0 || age > 120) {
          found.age = 'Enter an age from 0 to 120.';
        }
      } else if (required('date_of_birth') && !form.date_of_birth) {
        found.date_of_birth = 'Enter the date of birth.';
      }
    }
    if (!form.phone.trim()) {
      found.phone = 'Enter the mobile number.';
    } else if (!validPhone(form.phone)) {
      found.phone = 'Enter a valid mobile number.';
    }
    for (const field of ['alternate_phone', 'emergency_contact_phone'] as const) {
      if (form[field].trim() && !validPhone(form[field])) {
        found[field] = 'Enter a valid number.';
      }
    }
    if (form.email.trim() && !/^\S+@\S+\.\S+$/.test(form.email.trim())) {
      found.email = 'Enter a valid email address.';
    }

    return found;
  };

  const payload = (): Record<string, unknown> => {
    const data: Record<string, unknown> = {
      first_name: form.first_name.trim(),
      middle_name: form.middle_name.trim() || null,
      phone: form.phone.trim(),
      preferred_channel: form.preferred_channel || null,
    };
    // A field this clinic hides is never sent, so its stored value is kept.
    const optional = (field: keyof typeof initial, configKey: string = field) => {
      if (shown(configKey)) {
        data[field] = String(form[field]).trim() || null;
      }
    };
    optional('last_name');
    optional('gender');
    optional('blood_group');
    optional('alternate_phone');
    optional('email');
    optional('address');
    optional('locality', 'address_locality');
    optional('city', 'address_city');
    optional('state', 'address_state');
    optional('pincode', 'address_pincode');
    optional('country', 'address_country');
    optional('emergency_contact_name', 'emergency_contact');
    optional('emergency_contact_phone', 'emergency_contact');
    if (shown('date_of_birth')) {
      data.date_of_birth = form.age_known ? form.date_of_birth || null : null;
      if (!form.age_known) {
        data.age = Number(form.age);
      }
    }

    return data;
  };

  const fail = (message: string, found: Record<string, string> = {}) => {
    leaving.current = false;
    setSaving(false);
    setErrors(found);
    setProblem(message);
    window.requestAnimationFrame(() => document.querySelector<HTMLElement>('main [aria-invalid="true"], main [role="alert"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
  };

  const submit = (event?: FormEvent) => {
    event?.preventDefault();
    if (saving) {
      return;
    }
    const found = validate();
    if (Object.keys(found).length > 0) {
      fail('Some details need attention.', found);

      return;
    }
    setProblem(null);
    setSaving(true);
    leaving.current = true;
    router.put(route('clinic.patients.update', patient.id), payload() as never, {
      preserveScroll: true,
      onError: (errs) => fail('Some details need attention.', errs as Record<string, string>),
      onFinish: () => setSaving(false),
      onNetworkError: () => {
        fail('No connection: the details were not saved. Try again.');

        return false;
      },
    });
  };

  const err = (field: string) => errors[field] || undefined;
  const text = (key: keyof typeof initial, label: string, options: { config?: string; max?: number; type?: string; inputMode?: 'tel' | 'numeric' | 'email'; isRequired?: boolean; wide?: boolean } = {}) => {
    const config = options.config ?? key;
    if (!shown(config)) {
      return null;
    }

    return (
      <div className={cn(options.wide && 'sm:col-span-2')}>
        <Field label={label} htmlFor={key} required={options.isRequired ?? required(config)} error={err(key)}>
          <Input id={key} type={options.type ?? 'text'} inputMode={options.inputMode} value={String(form[key])} maxLength={options.max ?? 100} invalid={!!errors[key]} onChange={(e) => set(key, e.target.value as never)} />
        </Field>
      </div>
    );
  };
  const fullName = [form.first_name, form.middle_name, form.last_name].filter((part) => part.trim()).join(' ') || 'Patient';
  const actions = (
    <>
      <Link href={profileUrl} className={BTN}>
        Cancel
      </Link>
      <button type="submit" className={BTN_PRIMARY} disabled={saving || !dirty}>
        <Save className="h-4 w-4" aria-hidden="true" />
        {saving ? 'Saving…' : 'Save changes'}
      </button>
    </>
  );

  return (
    <form noValidate onSubmit={submit} className="mx-auto w-full max-w-[1320px] space-y-4 pb-24 lg:pb-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          <Link href={profileUrl} aria-label="Back to patient" className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white text-cx-text-2 shadow-[0_1px_2px_rgba(15,23,42,0.06)] hover:text-cx-nav-ink', FOCUS)}>
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          </Link>
          <div className="min-w-0">
            <h1 className="truncate text-[20px] font-semibold leading-7 tracking-[-0.02em] text-[#1D2E4A]" title={fullName}>
              Edit {fullName}
            </h1>
            <p className="flex flex-wrap items-center gap-1.5">
              {patient.uhid && <span className="rounded-full bg-white px-2.5 py-0.5 font-mono text-[12px] font-semibold text-cx-blue-ink">{patient.uhid}</span>}
              {patient.registered_on && (
                <Pill tone="slate" className="bg-white">
                  <CalendarDays className="h-3 w-3" aria-hidden="true" /> Registered {day(patient.registered_on)}
                </Pill>
              )}
              {patient.current_practitioner_name && (
                <Pill tone="teal" className="bg-white">
                  <Stethoscope className="h-3 w-3" aria-hidden="true" /> {patient.current_practitioner_name}
                </Pill>
              )}
              <span className="text-[12px] text-cx-text-3">{dirty ? '· Unsaved changes' : ''}</span>
            </p>
          </div>
        </div>
        <div className="hidden items-center gap-2 lg:flex">{actions}</div>
      </div>

      {problem && (
        <div role="alert">
          <Notice tone="danger">{problem}</Notice>
        </div>
      )}

      <div className="overflow-clip rounded-2xl border border-cx-line/80 bg-white shadow-cx-card">
        <Group icon={UserRound} tone="blue" title="Personal">
          {text('first_name', 'First name', { isRequired: true })}
          {text('middle_name', 'Middle name', { config: 'middle_name' })}
          {text('last_name', 'Last name')}
          {shown('gender') && (
            <Field label="Gender" htmlFor="gender" required={required('gender')} error={err('gender')}>
              <Segmented label="Gender" invalid={!!errors.gender} value={form.gender} onChange={(value) => set('gender', value)} options={[{ value: 'female', label: 'Female' }, { value: 'male', label: 'Male' }, { value: 'other', label: 'Other' }]} />
            </Field>
          )}
          {shown('date_of_birth') && (
            <>
              <Field label="Age from" htmlFor="age_mode">
                <Segmented label="Age from" value={form.age_known ? 'dob' : 'age'} onChange={(value) => set('age_known', value === 'dob')} options={[{ value: 'dob', label: 'Birth date' }, { value: 'age', label: 'Age only' }]} />
              </Field>
              {form.age_known ? (
                <Field label="Date of birth" htmlFor="date_of_birth" required={required('date_of_birth')} error={err('date_of_birth')}>
                  <Input id="date_of_birth" type="date" value={form.date_of_birth} max={new Date().toISOString().slice(0, 10)} invalid={!!errors.date_of_birth} onChange={(e) => set('date_of_birth', e.target.value)} />
                </Field>
              ) : (
                <Field label="Age (years)" htmlFor="age" required error={err('age')}>
                  <Input id="age" type="number" inputMode="numeric" min={0} max={120} value={form.age} invalid={!!errors.age} onChange={(e) => set('age', e.target.value)} />
                </Field>
              )}
            </>
          )}
          {shown('blood_group') && (
            <Field label="Blood group" htmlFor="blood_group">
              <Select id="blood_group" value={form.blood_group} placeholder="Not known" onChange={(e) => set('blood_group', e.target.value)} options={BLOOD.map((value) => ({ value, label: value }))} />
            </Field>
          )}
        </Group>

        <Group icon={Phone} tone="teal" title="Contact">
          {text('phone', 'Mobile number', { isRequired: true, type: 'tel', inputMode: 'tel', max: 20 })}
          {text('alternate_phone', 'Alternate number', { type: 'tel', inputMode: 'tel', max: 20 })}
          {text('email', 'Email', { type: 'email', inputMode: 'email', max: 255 })}
          {channels.length > 0 && (
            <Field label="Preferred channel" htmlFor="preferred_channel">
              <Select id="preferred_channel" value={form.preferred_channel} placeholder="No preference" onChange={(e) => set('preferred_channel', e.target.value)} options={channels.map((value) => ({ value, label: value.charAt(0).toUpperCase() + value.slice(1) }))} />
            </Field>
          )}
        </Group>

        {shown('address') && (
          <Group icon={Home} tone="violet" title="Address">
            {text('address', 'Address', { max: 500, wide: true })}
            {text('locality', 'Locality / area', { config: 'address_locality', max: 120 })}
            {text('city', 'City', { config: 'address_city' })}
            {text('state', 'State', { config: 'address_state' })}
            {text('pincode', 'PIN code', { config: 'address_pincode', max: 10, inputMode: 'numeric' })}
            {text('country', 'Country', { config: 'address_country', max: 80 })}
          </Group>
        )}

        {shown('emergency_contact') && (
          <Group icon={ShieldAlert} tone="rose" title="Emergency contact">
            {text('emergency_contact_name', 'Contact name', { config: 'emergency_contact' })}
            {text('emergency_contact_phone', 'Contact phone', { config: 'emergency_contact', type: 'tel', inputMode: 'tel', max: 20, isRequired: false })}
          </Group>
        )}

      </div>

      <p className="px-1 text-[12px] text-cx-text-3">Medical history, documents, the assigned doctor and the patient's status are kept in Patient 360. The UHID never changes.</p>

      <div className="fixed inset-x-0 bottom-0 z-30 flex items-center justify-between gap-3 border-t border-[#E6EBF1] bg-white/95 px-4 py-3 backdrop-blur lg:hidden">{actions}</div>
    </form>
  );
}
