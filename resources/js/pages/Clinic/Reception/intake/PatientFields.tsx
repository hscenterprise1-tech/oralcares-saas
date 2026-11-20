import { useEffect, useState, type ReactNode } from 'react';
import { ChevronDown, Lock, ShieldAlert, UserCheck } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, Checkbox, ChoiceChips, DatePicker, DuplicateOverride, Field, humanize, Input, PersonAvatar, Select, StatusBadge, Textarea, toIsoDate } from '@/clinic';
import { requestJson } from '@/clinic/http';
import { ageFromDob } from '@/pages/ClinicPortal/Patients/registration/types';
import { ClinicalPicker, type ClinicalChoice } from './ClinicalPicker';
import { AlertPicker } from './QuickCareFields';
import { forContext as forSpecialtyContext, formatDay, type DuplicateMatch, type IntakeConfig } from './types';

/**
 * The patient half of registration, shared by Reception's New Case popup and
 * the full Patients → Add patient page: who they are, the possible existing
 * patients the server finds as you type, their medical safety facts and the
 * clinic's optional details. Each surface brings its own visit, layout and
 * submit; the fields, rules and duplicate review are the same everywhere.
 */

/** What these fields need from the server: the New Case config (`ReceptionController::intake`) or the full page's (`PatientController::create`). */
export interface RegistrationConfig {
  fields: IntakeConfig['fields'];
  catalogue: Pick<IntakeConfig['catalogue'], 'conditions' | 'allergies' | 'medications' | 'surgical_history' | 'family_history' | 'alerts'>;
  quick_care: Pick<IntakeConfig['quick_care'], 'allergy_status'>;
  can: Pick<IntakeConfig['can'], 'record_clinical'>;
}

export interface PatientForm {
  phone: string;
  first_name: string;
  middle_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  dob_unknown: boolean;
  age: string;
  conditions: ClinicalChoice[];
  allergies: ClinicalChoice[];
  medications: ClinicalChoice[];
  surgical_history: ClinicalChoice[];
  family_history: ClinicalChoice[];
  email: string;
  alternate_phone: string;
  blood_group: string;
  address: string;
  locality: string;
  city: string;
  state: string;
  pincode: string;
  country: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  /** "No known allergies", confirmed (distinct from not asked). */
  allergy_none: boolean;
  alert_keys: string[];
}

export const EMPTY_PATIENT: PatientForm = {
  phone: '',
  first_name: '',
  middle_name: '',
  last_name: '',
  gender: '',
  date_of_birth: '',
  dob_unknown: false,
  age: '',
  conditions: [],
  allergies: [],
  medications: [],
  surgical_history: [],
  family_history: [],
  email: '',
  alternate_phone: '',
  blood_group: '',
  address: '',
  locality: '',
  city: '',
  state: '',
  pincode: '',
  country: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  allergy_none: false,
  alert_keys: [],
};

export type PatientErrors = Partial<Record<keyof PatientForm | 'duplicate_reason' | 'form' | 'allergy_status' | 'alert_keys' | 'medical_conditions', string>>;
export type SetPatientField = <K extends keyof PatientForm>(key: K, value: PatientForm[K]) => void;

/** The override of a possible duplicate, as both surfaces hold it. */
export interface DuplicateOverrideState {
  open: boolean;
  confirmed: boolean;
  reason: string;
}

export const NO_OVERRIDE: DuplicateOverrideState = { open: false, confirmed: false, reason: '' };

export const GENDERS = [
  { value: 'female', label: 'Female' },
  { value: 'male', label: 'Male' },
  { value: 'other', label: 'Other' },
];
const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((group) => ({ value: group, label: group }));

/** A fresh request key: the server registers (or checks in) once per key (`IntakeSubmissions`). */
export function requestKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/** The server's rule (`App\Rules\PhoneNumber`): an optional +, digits grouped by spaces, hyphens or brackets, 7 to 15 digits. */
export function validPhone(value: string): boolean {
  const trimmed = value.trim();
  const digits = trimmed.replace(/\D/g, '').length;

  return /^\+?[0-9][0-9\s\-()]*$/.test(trimmed) && digits >= 7 && digits <= 15;
}

/** The optional fields kept behind "More details", in the clinic's order. */
export const MORE_FIELDS = ['email', 'alternate_phone', 'blood_group', 'address', 'emergency_contact'] as const;
type MoreField = (typeof MORE_FIELDS)[number];
/** The address setting for each address input (`PatientFieldConfigService::ADDRESS_PARTS`). */
export const ADDRESS_FIELDS = [
  ['address', 'address', 'Address line'],
  ['address_locality', 'locality', 'Locality / area'],
  ['address_city', 'city', 'City'],
  ['address_state', 'state', 'State'],
  ['address_pincode', 'pincode', 'PIN / postal code'],
  ['address_country', 'country', 'Country'],
] as const;
/** Which form fields each "More details" setting governs, for opening the section on an error. */
export const MORE_FIELD_KEYS = ['email', 'alternate_phone', 'blood_group', 'address', 'locality', 'city', 'state', 'pincode', 'country', 'emergency_contact_name', 'emergency_contact_phone'];
export const SAFETY_FIELD_KEYS = ['conditions', 'medical_conditions', 'medications', 'surgical_history', 'family_history', 'allergies', 'allergy_status', 'alert_keys'];
/** The clinical lists, by setting: what is recorded, under which request key, with which wording. */
const HISTORY_LISTS = [
  ['medications', 'medications', 'Current medications', 'Other medication', 'Record the current medications.'],
  ['surgical_history', 'surgical_history', 'Past surgery', 'Other surgery', 'Record the past surgery.'],
  ['family_history', 'family_history', 'Family history', 'Other condition', 'Record the family history.'],
] as const;

export function shown(config: RegistrationConfig, field: string): boolean {
  return (config.fields[field]?.state ?? 'hidden') !== 'hidden';
}

export function required(config: RegistrationConfig, field: string): boolean {
  return config.fields[field]?.state === 'required';
}

/** The clinic's own label for a field, else the form's. */
export function labelOf(config: RegistrationConfig, field: string, fallback: string): string {
  return config.fields[field]?.label || fallback;
}

/** The clinic's own help text for a field, if any (else the form's hint, if any). */
export function helpOf(config: RegistrationConfig, field: string, fallback?: string): string | undefined {
  return config.fields[field]?.help || fallback;
}

/**
 * The settings as they apply to one visit's module: a field the clinic
 * limited to other modules is hidden (the server applies the same rule to
 * the module the form was filled in for, and refuses a visit for another).
 * No module chosen yet: every field applies.
 */
export function forModule<T extends RegistrationConfig>(config: T, specialty: string): T {
  if (specialty === '') {
    return config;
  }
  const fields = Object.fromEntries(
    Object.entries(config.fields).map(([field, setting]) => [field, setting.specialties && !setting.specialties.includes(specialty) ? { ...setting, state: 'hidden' as const } : setting]),
  );

  return { ...config, fields };
}

export function showsAddress(config: RegistrationConfig): boolean {
  return ADDRESS_FIELDS.some(([setting]) => shown(config, setting));
}

export function moreFields(config: RegistrationConfig): MoreField[] {
  return MORE_FIELDS.filter((field) => (field === 'address' ? showsAddress(config) : shown(config, field))).sort((a, b) => (config.fields[a]?.order ?? 0) - (config.fields[b]?.order ?? 0));
}

export function showsSafety(config: RegistrationConfig): boolean {
  return config.can.record_clinical && (shown(config, 'medical_conditions') || shown(config, 'allergies') || HISTORY_LISTS.some(([setting]) => shown(config, setting)));
}

/** Whether the safety section must start open: something in it is required. */
export function safetyRequired(config: RegistrationConfig): boolean {
  return (
    showsSafety(config) &&
    (required(config, 'medical_conditions') || required(config, 'allergies') || HISTORY_LISTS.some(([setting]) => required(config, setting)) || (shown(config, 'allergies') && config.quick_care.allergy_status === 'required'))
  );
}

/** Any address part, whether set or required, made into the error messages the form shows. */
function addressErrors(config: RegistrationConfig, data: PatientForm, errors: PatientErrors): void {
  for (const [setting, key, label] of ADDRESS_FIELDS) {
    if (required(config, setting) && data[key].trim() === '') {
      errors[key] = `Enter the ${labelOf(config, setting, label).toLowerCase()}.`;
    }
  }
}

export function validatePatient(config: RegistrationConfig, data: PatientForm): PatientErrors {
  const errors: PatientErrors = {};
  if (!validPhone(data.phone)) {
    errors.phone = data.phone.trim() === '' ? 'Enter the mobile number.' : 'Enter a valid phone number: digits, optionally starting with + and a country code.';
  }
  if (data.first_name.trim() === '') {
    errors.first_name = 'Enter the first name.';
  }
  if (required(config, 'last_name') && data.last_name.trim() === '') {
    errors.last_name = 'Enter the last name.';
  }
  if (required(config, 'gender') && data.gender === '') {
    errors.gender = 'Choose a gender.';
  }
  if (data.dob_unknown) {
    const age = Number(data.age);
    if (data.age === '' || !Number.isInteger(age) || age < 0 || age > 120) {
      errors.age = 'Enter an approximate age (0–120).';
    }
  } else if (required(config, 'date_of_birth') && data.date_of_birth === '') {
    errors.date_of_birth = 'Enter the date of birth, or tick "not known".';
  } else if (data.date_of_birth !== '' && data.date_of_birth > toIsoDate(new Date())) {
    errors.date_of_birth = 'The date of birth cannot be in the future.';
  }
  if (required(config, 'email') && data.email.trim() === '') {
    errors.email = 'Enter an email address.';
  } else if (data.email.trim() !== '' && !/^\S+@\S+\.\S+$/.test(data.email.trim())) {
    errors.email = 'Enter a valid email address.';
  }
  if (required(config, 'alternate_phone') && data.alternate_phone.trim() === '') {
    errors.alternate_phone = 'Enter the alternate mobile.';
  } else if (data.alternate_phone.trim() !== '' && !validPhone(data.alternate_phone)) {
    errors.alternate_phone = 'Enter a valid phone number.';
  }
  if (data.emergency_contact_phone.trim() !== '' && !validPhone(data.emergency_contact_phone)) {
    errors.emergency_contact_phone = 'Enter a valid phone number.';
  }
  if (required(config, 'blood_group') && data.blood_group === '') {
    errors.blood_group = 'Choose the blood group.';
  }
  addressErrors(config, data, errors);
  if (required(config, 'emergency_contact') && data.emergency_contact_name.trim() === '') {
    errors.emergency_contact_name = 'Enter an emergency contact.';
  }
  if (config.can.record_clinical) {
    if (required(config, 'allergies') && data.allergies.length === 0) {
      errors.allergies = 'Record at least one allergy.';
    }
    if (required(config, 'medical_conditions') && data.conditions.length === 0) {
      errors.conditions = 'Record the medical history.';
    }
    for (const [setting, key, , , message] of HISTORY_LISTS) {
      if (required(config, setting) && data[key].length === 0) {
        errors[key] = message;
      }
    }
    if (shown(config, 'allergies') && config.quick_care.allergy_status === 'required' && data.allergies.length === 0 && !data.allergy_none) {
      errors.allergy_status = 'Record the allergies, or tick “No known allergies”.';
    }
  }

  return errors;
}

/** The patient part of the registration request (the same endpoint for both surfaces). */
export function patientPayload(config: RegistrationConfig, data: PatientForm): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    first_name: data.first_name.trim(),
    middle_name: data.middle_name.trim() || null,
    last_name: data.last_name.trim() || null,
    phone: data.phone.trim(),
    gender: data.gender || null,
    date_of_birth: data.dob_unknown ? null : data.date_of_birth || null,
    age: data.dob_unknown && data.age !== '' ? Number(data.age) : null,
  };
  if (config.can.record_clinical) {
    payload.medical_conditions = data.conditions.map((choice) => (choice.key ? { key: choice.key, label: choice.label } : { label: choice.label }));
    payload.allergies = data.allergies.map((choice) => (choice.key ? { key: choice.key, label: choice.label } : { label: choice.label }));
    for (const [setting, key] of HISTORY_LISTS) {
      payload[key] = shown(config, setting) ? data[key].map((choice) => (choice.key ? { key: choice.key, label: choice.label } : { label: choice.label })) : [];
    }
    payload.allergy_status = data.allergies.length === 0 && data.allergy_none && config.quick_care.allergy_status !== 'hidden' ? 'none_known' : null;
    payload.alert_keys = data.alert_keys;
  }
  for (const [field, keys] of [
    ['email', ['email']],
    ['alternate_phone', ['alternate_phone']],
    ['blood_group', ['blood_group']],
    ['emergency_contact', ['emergency_contact_name', 'emergency_contact_phone']],
    ...ADDRESS_FIELDS.map(([setting, key]) => [setting, [key]] as const),
  ] as const) {
    if (shown(config, field)) {
      for (const key of keys) {
        const value = (data[key] as string).trim();
        payload[key] = value === '' ? null : value;
      }
    }
  }

  return payload;
}

/** The acknowledgement the server needs to register over possible duplicates (`PatientRegistrar::gate`). */
export function overridePayload(matches: DuplicateMatch[], override: DuplicateOverrideState): Record<string, unknown> {
  return matches.length > 0 && override.confirmed ? { duplicate_acknowledged_ids: matches.map((match) => match.id), duplicate_reason: override.reason.trim() } : {};
}

/**
 * Possible existing patients for what is being typed, from the server
 * (`clinic.patients.check-duplicate`), once the mobile number or the name
 * and date of birth are enough to compare — debounced, and the in-flight
 * check is abandoned when the typing changes. Never blocks: the server
 * checks again on saving.
 */
export function useDuplicateSuggestions(data: PatientForm, enabled: boolean): [DuplicateMatch[], (matches: DuplicateMatch[]) => void] {
  const [matches, setMatches] = useState<DuplicateMatch[]>([]);
  const digits = data.phone.replace(/\D/g, '');
  const checkable = digits.length >= 10 || (data.first_name.trim().length >= 2 && data.last_name.trim().length >= 2 && data.date_of_birth !== '' && !data.dob_unknown);
  const checkKey = JSON.stringify([digits, data.first_name.trim().toLowerCase(), data.last_name.trim().toLowerCase(), data.dob_unknown ? '' : data.date_of_birth]);

  useEffect(() => {
    // Paused (the form is out of view): what was found stays, with any override begun on it.
    if (!enabled) {
      return;
    }
    if (!checkable) {
      setMatches([]);

      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      try {
        const response = await requestJson<{ matches: DuplicateMatch[] }>('POST', route('clinic.patients.check-duplicate'), {
          body: { phone: data.phone, first_name: data.first_name, last_name: data.last_name, date_of_birth: data.dob_unknown ? null : data.date_of_birth || null },
          signal: controller.signal,
        });
        setMatches(response.matches);
      } catch {
        // The live check never blocks; the server checks again on saving.
      }
    }, 400);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [checkKey, checkable, enabled]);

  return [matches, setMatches];
}

/** Mobile first (it is what finds an existing patient), then name, sex and age. */
export function PatientIdentityFields({ config, data, setField, errors, idPrefix, autoFocus = false }: { config: RegistrationConfig; data: PatientForm; setField: SetPatientField; errors: PatientErrors; idPrefix: string; autoFocus?: boolean }) {
  const derivedAge = data.dob_unknown ? null : ageFromDob(data.date_of_birth);

  return (
    <div className="grid grid-cols-1 gap-x-4 gap-y-3.5 sm:grid-cols-6">
      <Field label="Mobile" htmlFor={`${idPrefix}-phone`} required error={errors.phone} className="sm:col-span-3" hint="Checked against existing patients as you type.">
        <Input
          id={`${idPrefix}-phone`}
          type="tel"
          inputMode="tel"
          autoComplete="off"
          autoFocus={autoFocus}
          value={data.phone}
          onChange={(event) => setField('phone', event.target.value)}
          invalid={!!errors.phone}
          placeholder="98765 43210 or +44 20 7946 0958"
        />
      </Field>
      {shown(config, 'gender') ? (
        <div className="sm:col-span-3">
          <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">
            {labelOf(config, 'gender', 'Gender')}
            {required(config, 'gender') && (
              <span className="ml-0.5 text-clinic-danger" aria-hidden="true">
                *
              </span>
            )}
          </p>
          <ChoiceChips label={labelOf(config, 'gender', 'Gender')} id={`${idPrefix}-gender`} options={GENDERS} value={data.gender} onChange={(gender) => setField('gender', gender)} invalid={!!errors.gender} describedBy={errors.gender ? `${idPrefix}-gender-error` : undefined} />
          {errors.gender && (
            <p id={`${idPrefix}-gender-error`} role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
              {errors.gender}
            </p>
          )}
        </div>
      ) : (
        <div className="hidden sm:col-span-3 sm:block" aria-hidden="true" />
      )}
      <Field label="First name" htmlFor={`${idPrefix}-first_name`} required error={errors.first_name} className="sm:col-span-2">
        <Input id={`${idPrefix}-first_name`} autoComplete="off" value={data.first_name} onChange={(event) => setField('first_name', event.target.value)} invalid={!!errors.first_name} />
      </Field>
      <Field label="Middle name" htmlFor={`${idPrefix}-middle_name`} className="sm:col-span-2">
        <Input id={`${idPrefix}-middle_name`} autoComplete="off" value={data.middle_name} onChange={(event) => setField('middle_name', event.target.value)} />
      </Field>
      {shown(config, 'last_name') && (
        <Field label={labelOf(config, 'last_name', 'Last name')} htmlFor={`${idPrefix}-last_name`} required={required(config, 'last_name')} error={errors.last_name} hint={helpOf(config, 'last_name')} className="sm:col-span-2">
          <Input id={`${idPrefix}-last_name`} autoComplete="off" value={data.last_name} onChange={(event) => setField('last_name', event.target.value)} invalid={!!errors.last_name} />
        </Field>
      )}
      {shown(config, 'date_of_birth') && (
        <div className="space-y-2 sm:col-span-6">
          <div className="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
            {data.dob_unknown ? (
              <Field label="Approximate age" htmlFor={`${idPrefix}-age`} required error={errors.age}>
                <Input id={`${idPrefix}-age`} inputMode="numeric" value={data.age} onChange={(event) => setField('age', event.target.value.replace(/\D/g, '').slice(0, 3))} invalid={!!errors.age} placeholder="Years" />
              </Field>
            ) : (
              <Field label={labelOf(config, 'date_of_birth', 'Date of birth')} htmlFor={`${idPrefix}-date_of_birth`} required={required(config, 'date_of_birth')} error={errors.date_of_birth} hint={derivedAge !== null ? `Age ${derivedAge}` : helpOf(config, 'date_of_birth')}>
                <DatePicker id={`${idPrefix}-date_of_birth`} value={data.date_of_birth} onChange={(value) => setField('date_of_birth', value)} max={toIsoDate(new Date())} invalid={!!errors.date_of_birth} placeholder="Pick the date of birth" />
              </Field>
            )}
            <div className="flex items-end pb-2.5 max-sm:pt-2">
              <Checkbox
                checked={data.dob_unknown}
                onCheckedChange={(checked) => {
                  setField('dob_unknown', checked);
                  setField(checked ? 'date_of_birth' : 'age', '');
                }}
                label="Date of birth not known"
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * Possible existing patients, right under the fields that found them. A
 * match this member may open offers "Use this patient" (the host decides
 * what that does — New Case turns into Repeat Case; nothing is registered).
 * A match at a branch they cannot open is shown masked, with no way in.
 * "This is a different person" opens the audited, reasoned override.
 */
export function DuplicateSuggestions({
  matches,
  specialtyLabel,
  onUse,
  useLabel = 'Use this patient',
  busyId = null,
  override,
  onOverrideChange,
  reasonError,
  idPrefix,
}: {
  matches: DuplicateMatch[];
  specialtyLabel: (key: string) => string;
  /** Absent: matches are shown for information only. */
  onUse?: (match: DuplicateMatch) => void;
  useLabel?: string;
  busyId?: number | null;
  override: DuplicateOverrideState;
  onOverrideChange: (patch: Partial<DuplicateOverrideState>) => void;
  reasonError?: string;
  idPrefix: string;
}) {
  if (matches.length === 0) {
    return null;
  }

  return (
    <section aria-labelledby={`${idPrefix}-duplicate-heading`} className="rounded-2xl border border-amber-300 bg-amber-50/70 p-3.5">
      <h3 id={`${idPrefix}-duplicate-heading`} className="flex items-center gap-2 text-[13.5px] font-bold text-amber-950" role="status">
        <UserCheck className="h-4 w-4" aria-hidden="true" />
        {matches.length === 1 ? 'Possible existing patient' : `${matches.length} possible existing patients`}
      </h3>
      <ul className="mt-2.5 space-y-2">
        {matches.map((match) => (
          <li key={match.id} className="flex flex-col gap-2.5 rounded-xl border border-amber-200 bg-white p-3 sm:flex-row sm:items-center">
            {match.restricted ? (
              <div className="flex min-w-0 flex-1 items-center gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-clinic-inset text-clinic-secondary" aria-hidden="true">
                  <Lock className="h-4 w-4" />
                </span>
                <div className="min-w-0">
                  <p className="text-[13.5px] font-bold text-clinic-ink">{match.name} · registered at another branch</p>
                  <p className="text-[12.5px] text-clinic-secondary">
                    Same {match.matched_on.map((on) => (on === 'name_dob' ? 'name and date of birth' : on === 'phone' ? 'mobile' : on)).join(' and ') || 'details'}. Their record is not open to you here; a clinic admin can check it.
                  </p>
                </div>
              </div>
            ) : (
              <>
                <div className="flex min-w-0 flex-1 items-center gap-3">
                  <PersonAvatar name={match.name} />
                  <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 text-[13.5px] font-bold text-clinic-ink">
                      {match.name}
                      {/* Only a record marker a desk must not miss; there is no active / inactive state to show. */}
                      {match.status === 'merged' && <StatusBadge status={match.status} />}
                    </p>
                    <p className="text-[12.5px] text-clinic-secondary">
                      {[match.uhid, [match.age !== null ? `${match.age}` : null, match.gender ? humanize(match.gender) : null].filter(Boolean).join(' · '), match.phone_hint].filter(Boolean).join(' · ')}
                    </p>
                    {(match.specialties.length > 0 || match.last_seen) && (
                      <p className="mt-0.5 text-[12px] text-clinic-ink">{[match.specialties.map(specialtyLabel).join(', ') || null, match.last_seen ? `Last visit ${formatDay(match.last_seen.at)}` : null].filter(Boolean).join(' · ')}</p>
                    )}
                  </div>
                </div>
                {onUse && (
                  <Button tone="primary" size="sm" onClick={() => onUse(match)} loading={busyId === match.id} loadingLabel="Opening…" className="sm:shrink-0">
                    {useLabel}
                  </Button>
                )}
              </>
            )}
          </li>
        ))}
      </ul>

      {!override.open ? (
        <button
          type="button"
          onClick={() => onOverrideChange({ open: true })}
          className="mt-2.5 rounded text-[13px] font-semibold text-amber-950 underline underline-offset-2 hover:no-underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
        >
          This is a different person
        </button>
      ) : (
        <div className="mt-2.5">
          <DuplicateOverride count={matches.length} confirmed={override.confirmed} reason={override.reason} onChange={onOverrideChange} error={reasonError} idPrefix={idPrefix} />
        </div>
      )}
    </section>
  );
}

/** A section that folds away; it opens itself when it holds a required field or an error. `animated`: its body eases in when opened. */
export function Collapsible({ id, title, icon: Icon, summary, open, onOpenChange, children, animated = false, className }: { id: string; title: string; icon?: typeof ShieldAlert; summary: ReactNode; open: boolean; onOpenChange: (open: boolean) => void; children: ReactNode; animated?: boolean; className?: string }) {
  return (
    <section className={cn('rounded-2xl border border-clinic-border', className)}>
      <button
        type="button"
        aria-expanded={open}
        aria-controls={`${id}-body`}
        onClick={() => onOpenChange(!open)}
        className="flex min-h-11 w-full items-center justify-between gap-3 rounded-2xl px-4 py-2.5 text-left text-[13px] font-semibold text-clinic-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong"
      >
        <span className="flex items-center gap-1.5">
          {Icon && <Icon className="h-4 w-4 text-clinic-secondary" aria-hidden="true" />}
          {title}
        </span>
        <span className="flex items-center gap-2 text-[12px] font-medium text-clinic-secondary">
          {summary}
          <ChevronDown className={cn('h-4 w-4 motion-safe:transition-transform', open && 'rotate-180')} aria-hidden="true" />
        </span>
      </button>
      {open && (
        <div id={`${id}-body`} className={cn('border-t border-clinic-border px-4 py-4', animated && 'motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-top-1 motion-safe:duration-200')}>
          {children}
        </div>
      )}
    </section>
  );
}

/**
 * Medical history, allergies and operational alerts as taps, limited to the
 * specialty (and practice area) the visit is for: a physiotherapy clinic is
 * never offered dental items, nor a dental one physiotherapy items.
 */
export function MedicalSafetyFields({
  config,
  data,
  setField,
  errors,
  specialty,
  area,
  generalOnly = false,
}: {
  config: RegistrationConfig;
  data: PatientForm;
  setField: SetPatientField;
  errors: PatientErrors;
  specialty: string;
  area: string | null;
  /** A clinic practising several specialties before the visit's is known: only items for every specialty, never one module's. */
  generalOnly?: boolean;
}) {
  const offered = (items: RegistrationConfig['catalogue']['conditions']) => (generalOnly ? items.filter((item) => item.specialty_keys === null) : forSpecialtyContext(items, specialty, area));

  return (
    <div className="space-y-4">
      {shown(config, 'medical_conditions') && (
        <div>
          <ClinicalPicker
            label={`${labelOf(config, 'medical_conditions', 'Medical history')}${required(config, 'medical_conditions') ? ' *' : ''}`}
            items={offered(config.catalogue.conditions)}
            value={data.conditions}
            onChange={(conditions) => setField('conditions', conditions)}
            addLabel="Other condition"
          />
          {(errors.conditions || errors.medical_conditions) && (
            <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
              {errors.conditions ?? errors.medical_conditions}
            </p>
          )}
        </div>
      )}
      {HISTORY_LISTS.map(([setting, key, label, addLabel]) =>
        shown(config, setting) ? (
          <div key={setting}>
            <ClinicalPicker
              label={`${labelOf(config, setting, label)}${required(config, setting) ? ' *' : ''}`}
              items={offered(config.catalogue[key] ?? [])}
              value={data[key]}
              onChange={(choices) => setField(key, choices)}
              addLabel={addLabel}
            />
            {helpOf(config, setting) && <p className="mt-1 text-[12px] text-clinic-secondary">{helpOf(config, setting)}</p>}
            {errors[key] && (
              <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
                {errors[key]}
              </p>
            )}
          </div>
        ) : null,
      )}
      {shown(config, 'allergies') && (
        <div>
          <ClinicalPicker
            label={`${labelOf(config, 'allergies', 'Allergies')}${required(config, 'allergies') ? ' *' : ''}`}
            items={offered(config.catalogue.allergies)}
            value={data.allergies}
            onChange={(allergies) => setField('allergies', allergies)}
            quickCount={8}
            addLabel="Add allergy"
          />
          {config.quick_care.allergy_status !== 'hidden' && (
            <div className="mt-2.5">
              <Checkbox checked={data.allergy_none && data.allergies.length === 0} disabled={data.allergies.length > 0} onCheckedChange={(checked) => setField('allergy_none', checked)} label="No known allergies" />
            </div>
          )}
          {(errors.allergies || errors.allergy_status) && (
            <p role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
              {errors.allergies ?? errors.allergy_status}
            </p>
          )}
        </div>
      )}
      <AlertPicker items={offered(config.catalogue.alerts)} value={data.alert_keys} onChange={(keys) => setField('alert_keys', keys)} />
      {errors.alert_keys && (
        <p role="alert" className="text-[12px] font-medium text-clinic-danger">
          {errors.alert_keys}
        </p>
      )}
    </div>
  );
}

/** The clinic's optional contact and personal details, in its order. `exclude`: address parts the host shows elsewhere (by form key). */
export function MoreDetailsFields({ config, data, setField, errors, idPrefix, exclude = [] }: { config: RegistrationConfig; data: PatientForm; setField: SetPatientField; errors: PatientErrors; idPrefix: string; exclude?: string[] }) {
  return (
    <div className="grid grid-cols-1 gap-x-4 gap-y-3.5 sm:grid-cols-2">
      {moreFields(config).map((field) => (
        <MoreFieldInput key={field} field={field} config={config} data={data} setField={setField} errors={errors} idPrefix={idPrefix} exclude={exclude} />
      ))}
    </div>
  );
}

function MoreFieldInput({ field, config, data, setField, errors, idPrefix, exclude }: { field: MoreField; config: RegistrationConfig; data: PatientForm; setField: SetPatientField; errors: PatientErrors; idPrefix: string; exclude: string[] }) {
  const isRequired = required(config, field);

  switch (field) {
    case 'email':
      return (
        <Field label={labelOf(config, 'email', 'Email')} htmlFor={`${idPrefix}-email`} required={isRequired} error={errors.email} hint={helpOf(config, 'email')}>
          <Input id={`${idPrefix}-email`} type="email" autoComplete="off" value={data.email} onChange={(event) => setField('email', event.target.value)} invalid={!!errors.email} />
        </Field>
      );
    case 'alternate_phone':
      return (
        <Field label={labelOf(config, 'alternate_phone', 'Alternate mobile')} htmlFor={`${idPrefix}-alternate_phone`} required={isRequired} error={errors.alternate_phone} hint={helpOf(config, 'alternate_phone')}>
          <Input id={`${idPrefix}-alternate_phone`} type="tel" inputMode="tel" autoComplete="off" value={data.alternate_phone} onChange={(event) => setField('alternate_phone', event.target.value)} invalid={!!errors.alternate_phone} />
        </Field>
      );
    case 'blood_group':
      return (
        <Field label={labelOf(config, 'blood_group', 'Blood group')} htmlFor={`${idPrefix}-blood_group`} required={isRequired} error={errors.blood_group} hint={helpOf(config, 'blood_group')}>
          <Select id={`${idPrefix}-blood_group`} value={data.blood_group} onChange={(event) => setField('blood_group', event.target.value)} options={BLOOD_GROUPS} placeholder="Not known" invalid={!!errors.blood_group} />
        </Field>
      );
    case 'address':
      // Each part shows (and is required) on its own setting; the line spans the row.
      return (
        <>
          {ADDRESS_FIELDS.filter(([setting, key]) => shown(config, setting) && !exclude.includes(key)).map(([setting, key, label]) => (
            <Field
              key={key}
              label={labelOf(config, setting, label)}
              htmlFor={`${idPrefix}-${key}`}
              required={required(config, setting)}
              error={errors[key]}
              hint={helpOf(config, setting)}
              className={key === 'address' ? 'sm:col-span-2' : undefined}
            >
              {key === 'address' ? (
                <Textarea id={`${idPrefix}-address`} rows={2} value={data.address} onChange={(event) => setField('address', event.target.value)} invalid={!!errors.address} />
              ) : (
                <Input
                  id={`${idPrefix}-${key}`}
                  value={data[key]}
                  inputMode={key === 'pincode' ? 'numeric' : undefined}
                  maxLength={key === 'pincode' ? 10 : key === 'country' ? 80 : 120}
                  onChange={(event) => setField(key, event.target.value)}
                  invalid={!!errors[key]}
                />
              )}
            </Field>
          ))}
        </>
      );
    case 'emergency_contact':
      return (
        <>
          <Field label={labelOf(config, 'emergency_contact', 'Emergency contact')} htmlFor={`${idPrefix}-emergency_contact_name`} required={isRequired} error={errors.emergency_contact_name} hint={helpOf(config, 'emergency_contact')}>
            <Input id={`${idPrefix}-emergency_contact_name`} value={data.emergency_contact_name} onChange={(event) => setField('emergency_contact_name', event.target.value)} invalid={!!errors.emergency_contact_name} />
          </Field>
          <Field label="Emergency contact mobile" htmlFor={`${idPrefix}-emergency_contact_phone`} error={errors.emergency_contact_phone}>
            <Input id={`${idPrefix}-emergency_contact_phone`} type="tel" inputMode="tel" value={data.emergency_contact_phone} onChange={(event) => setField('emergency_contact_phone', event.target.value)} invalid={!!errors.emergency_contact_phone} />
          </Field>
        </>
      );
  }
}
