/**
 * Reception intake payloads — mirror `ReceptionController::intake()`,
 * `ReceptionPatientLookup`, `IntakeOutcome` and
 * `PatientDuplicateDetectionService::present()`. The server is the contract:
 * change both together.
 */
import type { DuplicateMatch } from '@/pages/ClinicPortal/Patients/registration/types';

export type FieldState = 'required' | 'optional' | 'hidden';

export interface IntakeField {
  state: FieldState;
  order: number;
  editable: boolean;
  /** The modules (specialty keys) it applies to; null: all. */
  specialties: string[] | null;
  /** The clinic's own wording; null: the form's. */
  label?: string | null;
  help?: string | null;
}

export interface CatalogueItem {
  key: string;
  name: string;
  /** For a visit reason: the visit type it implies. */
  category: string | null;
  severity: 'low' | 'moderate' | 'high' | 'critical' | null;
  specialty_keys: string[] | null;
  /** Practice areas it is limited to (null: the whole specialty). */
  practice_area_keys?: string[] | null;
  required: boolean;
}

export interface IntakeConfig {
  branch: { id: number; name: string };
  fields: Record<string, IntakeField>;
  /** What this branch practises, for a visit with no doctor chosen. */
  specialties: { key: string; label: string }[];
  doctors: PractitionerOption[];
  catalogue: {
    conditions: CatalogueItem[];
    allergies: CatalogueItem[];
    medications: CatalogueItem[];
    surgical_history: CatalogueItem[];
    family_history: CatalogueItem[];
    visit_types: CatalogueItem[];
    visit_reasons: CatalogueItem[];
    alerts: CatalogueItem[];
  };
  quick_care: {
    allergy_status: FieldState;
  };
  /** `queue`: whether positions are shown (module available and switched on). `payment`: when the consultation fee is charged. */
  check_in: {
    visit_reason: FieldState;
    priority: boolean;
    priorities: string[];
    queue: boolean;
    show_fee: boolean;
    payment: 'after_consultation' | 'at_check_in';
    /** Modules that never charge at check-in (Physiotherapy bills its sessions and packages at their own stage). */
    no_charge_modules?: string[];
  };
  /** Paying at check-in: accepted methods, and what this member may do. */
  payment: { methods: { value: string; label: string }[]; can_charge: boolean; can_collect: boolean };
  /** The priorities this clinic uses, normal first. */
  priorities: { value: string; label: string; rank: number }[];
  /** A booking made before the patient was registered, that this New Case is for. */
  booking: { id: number; time: string | null; doctor_id: number | null; name: string | null; phone: string | null } | null;
  can: { register: boolean; record_clinical: boolean };
}

export interface SafetyFlag {
  kind: 'allergy' | 'condition' | 'alert';
  label: string;
  severity: 'low' | 'moderate' | 'high' | 'critical';
}

/** One Repeat Case result (`ReceptionPatientLookup::present()`). */
export interface LookupPatient {
  id: number;
  name: string;
  short_name: string;
  phone: string | null;
  uhid: string | null;
  age: number | null;
  gender: string | null;
  status: string;
  specialties: string[];
  last_seen: { at: string; branch: string } | null;
  safety_flags: SafetyFlag[];
  /** The address on file, one line, read-only; null when there is none or this member does not see addresses. */
  address: string | null;
  appointment: { id: number; time: string; doctor: { id: number; name: string } | null } | null;
  in_queue: { status: string; case_short: string | null } | null;
  assigned_doctor_id: number | null;
}

/** One way a practitioner can see a patient here: module, practice area and the specialty it is recorded under. */
import type { PractitionerContext, PractitionerOption } from '@/clinic/practitioners/types';

export type { PractitionerContext, PractitionerOption };

/** Today's visit, as both flows send it to check-in. `specialty` is used only when no doctor is chosen; a chosen doctor decides it. */
export interface VisitForm {
  specialty: string;
  doctor_id: string;
  /** Which of the doctor's practice areas, when they have more than one here. */
  area: string;
  visit_type: string;
  /** A catalogue reason key, or 'other' with words in `visit_reason`. */
  reason_key: string;
  visit_reason: string;
  priority: string;
}

/** What the desk shows after registering or checking in (`IntakeOutcome::present()`). */
export interface CheckInResult {
  message: string;
  patient: { id: number; name: string; uhid: string | null; url?: string };
  check_in: {
    case_number: string | null;
    case_short: string | null;
    doctor: string | null;
    /** Whether this clinic shows queue positions. */
    queue: boolean;
    position: number | null;
    priority: { value: string; label: string };
    reason: string | null;
    context?: ResolvedContext | null;
  } | null;
  links: { patient: string | null; consultation: string | null };
  /** The consultation charge raised at check-in (`CheckInCharges::present()`); null when nothing was charged. */
  billing: {
    id: number;
    billing_number: string;
    /** Each line of the bill, as the server raised it. */
    lines: { description: string; quantity: number; amount: number }[];
    /** Why it was charged, in words. */
    reason: string;
    /** Where to take a payment (the billing payment endpoint); null when nothing is owed or this member may not collect. */
    pay_url: string | null;
    total: number;
    paid: number;
    outstanding: number;
    status: string;
    receipt: {
      id: number;
      number: string;
      amount: number;
      method: string;
      /** The bill print route as a thermal roll receipt for this payment; null without billing view. */
      print_url: string | null;
      /** Where this payment's patient messages stand. */
      messages_url: string | null;
      /** Send its failed WhatsApp messages again; null when this member may not. */
      retry_url: string | null;
    } | null;
    print_url: string | null;
  } | null;
  payment_timing: 'after_consultation' | 'at_check_in';
  replayed?: boolean;
}

/** A booking made with this mobile number, found while registering (`ReceptionController::bookingMatches()`). */
export interface BookingMatch {
  id: number;
  time: string | null;
  name: string | null;
  doctor: { id: number; name: string; practice: string | null } | null;
}

/** Money taken at check-in, as the dialog holds it. */
export interface PaymentForm {
  collect: boolean;
  method: string;
  amount: string;
  reference: string;
}

export type { DuplicateMatch };

/** Items that apply to the chosen specialty (null on the item: every specialty). */
export function forSpecialty<T extends { specialty_keys: string[] | null }>(items: T[], specialty: string): T[] {
  return items.filter((item) => item.specialty_keys === null || specialty === '' || item.specialty_keys.includes(specialty));
}

/** Reasons offered for a visit: its specialty, and its practice area when one is known. */
export function reasonsFor(config: IntakeConfig, specialty: string, area: string | null): CatalogueItem[] {
  return forSpecialty(config.catalogue.visit_reasons, specialty).filter((item) => !area || !item.practice_area_keys?.length || item.practice_area_keys.includes(area));
}

/**
 * Whether this visit is charged when the patient checks in: the clinic's
 * policy, the doctor's fee, this member's rights, and the visit's module
 * (`module`: the chosen context's; a module that never charges at check-in
 * is never charged). The server decides again (`CheckInCharges`).
 */
export function chargedAtCheckIn(config: IntakeConfig, doctor: PractitionerOption | null, module: string | null = null): boolean {
  const visitModule = module ?? doctor?.contexts[0]?.module ?? null;

  return config.check_in.payment === 'at_check_in' && !!doctor?.fee && config.payment.can_charge && !(visitModule && config.check_in.no_charge_modules?.includes(visitModule));
}

/** How the consultation fee is billed, in reception's words. */
export function feeTiming(config: IntakeConfig, doctor: PractitionerOption | null, module: string | null = null): string {
  return chargedAtCheckIn(config, doctor, module) ? 'charged at check-in' : 'billed after the visit';
}

/** Catalogue items for a specialty and, when one is known, a practice area. */
export function forContext<T extends { specialty_keys: string[] | null; practice_area_keys?: string[] | null }>(items: T[], specialty: string, area: string | null): T[] {
  return forSpecialty(items, specialty).filter((item) => !area || !item.practice_area_keys?.length || item.practice_area_keys.includes(area));
}

/**
 * What the desk calls the practitioner for a visit: "Therapist" for
 * Physiotherapy, "Doctor" for anything else, "Doctor / Therapist" in a
 * clinic offering both before the visit's module is known. Wording only.
 */
export function practitionerNoun(config: Pick<IntakeConfig, 'specialties'>, specialty: string): string {
  const keys = config.specialties.map((option) => option.key);
  const module = specialty !== '' ? specialty : keys.length === 1 ? keys[0] : '';
  if (module !== '') {
    return module === 'physiotherapy' ? 'Therapist' : 'Doctor';
  }

  return keys.includes('physiotherapy') ? 'Doctor / Therapist' : 'Doctor';
}

/** A mobile number for a search result: only its last four digits, enough to tell two patients apart. */
export function maskPhone(phone: string | null): string | null {
  const digits = (phone ?? '').replace(/\D/g, '');

  return digits.length >= 4 ? `••••••${digits.slice(-4)}` : null;
}

export function formatDay(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }
  const date = new Date(`${value.slice(0, 10)}T00:00:00`);

  return Number.isNaN(date.getTime()) ? null : date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

/** The server's resolved context (`ClinicalContext::present()`). */
export interface ResolvedContext {
  source: string;
  practitioner: { id: number; name: string } | null;
  module: { key: string; label: string } | null;
  specialty: { key: string; label: string } | null;
  area: { key: string; label: string } | null;
}

/** The context a visit form resolves to: the chosen doctor's (chosen area, else their primary), else none. */
export function contextOf(config: IntakeConfig, value: VisitForm): PractitionerContext | null {
  const doctor = config.doctors.find((option) => String(option.id) === value.doctor_id);
  if (!doctor) {
    return null;
  }

  return doctor.contexts.find((context) => context.area === value.area) ?? doctor.contexts[0] ?? null;
}

/** The specialty a visit is for: the chosen doctor's context, else the specialty chosen by hand. */
export function visitSpecialty(config: IntakeConfig, value: VisitForm): string {
  const doctor = config.doctors.find((option) => String(option.id) === value.doctor_id);

  return doctor ? (contextOf(config, value)?.specialty ?? '') : value.specialty;
}
