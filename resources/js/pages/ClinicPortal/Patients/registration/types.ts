import type { FormDataConvertible } from '@inertiajs/core';
/*
 * Shapes for the patient directory and the registration / edit forms, as the
 * server sends them (PatientController@index/create/edit). The server is the
 * contract: these mirror its payloads, they do not add to them.
 */

export type FieldState = 'required' | 'optional' | 'hidden';

/** The fields a clinic can configure (`PatientFieldConfigService`); first name and phone are always required. */
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
  | 'medical_conditions'
  | 'medications'
  | 'doctor_id';

export type PatientFieldConfig = Record<ConfigurableField, FieldState>;

export interface SpecialtyOption {
  key: string;
  label: string;
}

export interface PractitionerOption {
  id: number;
  name: string;
  specialty: string | null;
}

export interface AllergyOption {
  key: string;
  name: string;
  category: string | null;
  category_label: string;
  severity?: string | null;
  specialty_keys?: string[] | null;
}

/** Everything both forms choose from: bounded lists only. */
export interface FormOptions {
  practitioners: PractitionerOption[];
  allergyCatalogue: AllergyOption[];
  conditionCatalogue: AllergyOption[];
  fieldConfig: PatientFieldConfig;
  specialties: SpecialtyOption[];
  channels: string[];
  consentMethods: string[];
}

/** A possible existing patient, in the server's privacy-safe shape (`PatientDuplicateDetectionService::present`). */
export interface DuplicateMatch {
  id: number;
  uhid: string | null;
  name: string;
  gender: string | null;
  age: number | null;
  date_of_birth: string | null;
  phone_hint: string | null;
  status: string;
  registered_on: string | null;
  matched_on: string[];
  specialties: string[];
  last_seen: { branch: string; at: string } | null;
  /** A match at a branch this member cannot open: masked (initials only), and never usable here. */
  restricted?: boolean;
}

export interface PatientFormData {
  first_name: string;
  middle_name: string;
  last_name: string;
  gender: string;
  date_of_birth: string;
  /** True only when the date of birth is not known; then an approximate age may be given. */
  dob_unknown: boolean;
  age: string;
  blood_group: string;
  phone: string;
  alternate_phone: string;
  email: string;
  address: string;
  city: string;
  state: string;
  pincode: string;
  preferred_channel: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  allergies: string[];
  medical_alerts: string[];
  // Registration only:
  /** Condition keys from the clinic's catalogue. */
  medical_conditions: string[];
  specialties: string[];
  doctor_id: string;
  communication_consent: boolean;
  consent_capture_method: string;
}

export const EMPTY_PATIENT_FORM: PatientFormData = {
  first_name: '',
  middle_name: '',
  last_name: '',
  gender: '',
  date_of_birth: '',
  dob_unknown: false,
  age: '',
  blood_group: '',
  phone: '',
  alternate_phone: '',
  email: '',
  address: '',
  city: '',
  state: '',
  pincode: '',
  preferred_channel: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
  allergies: [],
  medical_alerts: [],
  medical_conditions: [],
  specialties: [],
  doctor_id: '',
  communication_consent: false,
  consent_capture_method: '',
};

export interface PatientEditRecord {
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
  city: string;
  state: string;
  pincode: string;
  preferred_channel: string;
  emergency_contact_name: string;
  emergency_contact_phone: string;
  allergies: string[];
  medical_alerts: string[];
  /** What any role that can open the patient may see (labels and severity only). */
  safety_flags: { kind: 'allergy' | 'condition' | 'alert'; label: string; severity: 'low' | 'moderate' | 'high' | 'critical' }[];
  specialties: string[];
  current_practitioner_name: string | null;
  registered_on: string | null;
}

/** A directory row (`PatientDirectory::page`). */
export interface DirectoryRow {
  id: number;
  uhid: string | null;
  name: string;
  phone: string | null;
  email: string | null;
  gender: string | null;
  age: number | null;
  status: string;
  specialties: string[];
  active_care: { id: number; title: string; specialty: string | null; status: string }[];
  last_activity_at: string | null;
  last_branch: string | null;
  critical_alerts: number;
  registered_on: string | null;
}

export interface DirectoryPage {
  data: DirectoryRow[];
  next_cursor: string | null;
  prev_cursor: string | null;
  per_page: number;
}

/** Whole years from a `yyyy-mm-dd` birth date, as of today; null if not a date. */
export function ageFromDob(dob: string): number | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dob);
  if (!match) {
    return null;
  }
  const birth = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) {
    age -= 1;
  }

  return age >= 0 && age <= 150 ? age : null;
}

/**
 * The request body: the date of birth is the age when it is known, so an
 * age is sent only when the birth date is marked unknown — the two never
 * disagree on the server.
 */
export function patientPayload(data: PatientFormData, mode: 'create' | 'edit'): Record<string, FormDataConvertible> {
  const { dob_unknown, specialties, doctor_id, communication_consent, consent_capture_method, medical_conditions, ...shared } = data;
  const body: Record<string, FormDataConvertible> = {
    ...shared,
    date_of_birth: dob_unknown ? null : shared.date_of_birth || null,
    age: dob_unknown && shared.age !== '' ? Number(shared.age) : null,
  };
  if (mode === 'create') {
    Object.assign(body, {
      medical_conditions,
      specialties,
      doctor_id: doctor_id || null,
      communication_consent,
      consent_capture_method: communication_consent ? consent_capture_method || null : null,
    });
  }

  return body;
}
