import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, IndianRupee, LogIn, Stethoscope, UserPlus } from 'lucide-react';
import { ButtonLink, duplicateOverrideProblem, ErrorState, humanize, Modal, Notice } from '@/clinic';
import { DsButton } from '@/clinic/ds';
import { ClinicHttpError, errorKind, requestJson } from '@/clinic/http';
import { BillingResult, BookingFound, CollectPaymentDialog } from './CheckInPayment';
import {
  DuplicateSuggestions,
  EMPTY_PATIENT,
  forModule,
  NO_OVERRIDE,
  overridePayload,
  patientPayload,
  requestKey,
  required,
  useDuplicateSuggestions,
  validatePatient,
  type DuplicateOverrideState,
  type PatientErrors,
  type PatientForm,
} from './PatientFields';
import { RepeatCaseForm } from './RepeatCaseForm';
import { FeeSummary, NewCaseForm } from './NewCaseForm';
import { chargedAtCheckIn, contextOf, practitionerNoun, reasonsFor, visitSpecialty, type BookingMatch, type CheckInResult, type DuplicateMatch, type IntakeConfig, type LookupPatient, type VisitForm } from './types';
import { useIntakeConfig } from './useIntakeConfig';
import { doctorsFor, emptyVisit, validateVisit, visitPayload, type VisitErrors } from './VisitFields';

export type IntakeMode = 'new' | 'repeat';

/** Registration fields a clinic may require that New Case's quick form does not ask (the full page does). */
const NOT_IN_NEW_CASE: [string, string][] = [
  ['email', 'email'],
  ['alternate_phone', 'an alternate mobile'],
  ['blood_group', 'blood group'],
  ['emergency_contact', 'an emergency contact'],
  ['address_locality', 'locality'],
  ['address_city', 'city'],
  ['address_state', 'state'],
  ['address_pincode', 'PIN code'],
  ['address_country', 'country'],
  ['medications', 'current medications'],
  ['surgical_history', 'past surgery'],
  ['family_history', 'family history'],
];
/** Enough of a patient to find them for check-in. */
export type PatientIdentity = { id: number; uhid: string | null; name: string };


/**
 * Reception's two everyday workflows on one screen, so the desk never
 * leaves the queue and never steps through a wizard:
 *
 * - New Case: the patient (mobile first, then name, sex, age; medical
 *   safety and the clinic's optional details folded away unless required)
 *   beside today's visit (doctor, reason, priority, payment). Then
 *   Register & check in, or Register only.
 * - Repeat Case: find the patient beside the same visit, then Check in.
 * - Possible existing patients appear under the fields as they are typed.
 *   "Use this patient" turns the dialog into Repeat Case for them — no
 *   registration is sent — and "This is a different person" is the
 *   audited, reasoned override. A patient of a branch this member cannot
 *   open is shown masked, with no way in.
 * - Switching between New and Repeat keeps everything typed in both.
 * - Opened for a doctor (their card on the desk), a booking made before the
 *   patient was registered, or a known patient, those come preselected.
 *
 * Both submit to the shared backend with a request key made when the dialog
 * opened, so a double click or retry never registers or checks in twice.
 * Closing with unsaved work (Escape, ×, Cancel) asks first. Desktop shows
 * the two halves side by side; a tablet stacks them; a phone gets a
 * full-height sheet.
 */
export function ReceptionIntakeDialog({
  open,
  mode,
  onOpenChange,
  branchKey,
  initialQuery = '',
  initialDoctorId = null,
  appointmentId = null,
  initialPatient = null,
  initialSpecialty = null,
  onCheckedIn,
}: {
  open: boolean;
  mode: IntakeMode;
  onOpenChange: (open: boolean) => void;
  branchKey: string;
  /** Repeat Case opened from a search result starts with that query. */
  initialQuery?: string;
  /** Opened from a doctor's card: that doctor is preselected. */
  initialDoctorId?: number | null;
  /** New Case for a booking made before the patient was registered. */
  appointmentId?: number | null;
  /** Repeat Case for a known patient (a booking on the calendar): opens with them chosen, today's booking linked. */
  initialPatient?: PatientIdentity | null;
  /** Opened from a module's own page (Physiotherapy, Dental): that module is preselected when this branch offers it. */
  initialSpecialty?: string | null;
  /** A patient was checked in: the host refreshes the figures that changed. */
  onCheckedIn?: () => void;
}) {
  const page = usePage();
  const { config, failed, retry } = useIntakeConfig(open, branchKey, mode === 'new' ? appointmentId : null);
  const [flow, setFlow] = useState<IntakeMode>(mode);
  const [done, setDone] = useState(false);
  const [data, setData] = useState<PatientForm>(EMPTY_PATIENT);
  const [errors, setErrors] = useState<PatientErrors>({});
  const [visit, setVisit] = useState<VisitForm>(emptyVisit(null));
  const [visitErrors, setVisitErrors] = useState<VisitErrors>({});
  const [query, setQuery] = useState('');
  const [patient, setPatient] = useState<LookupPatient | null>(null);
  const [override, setOverride] = useState<DuplicateOverrideState>(NO_OVERRIDE);
  const [busy, setBusy] = useState<'register' | 'register_only' | 'check_in' | null>(null);
  const [openingId, setOpeningId] = useState<number | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [result, setResult] = useState<CheckInResult | null>(null);
  const [registered, setRegistered] = useState(false);
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const [key, setKey] = useState(requestKey);
  // After a check-in that charged something owing: the separate Collect Payment step.
  const [paying, setPaying] = useState(false);
  // A booking found by the mobile number being typed (New Case only); used only when reception says so.
  const [bookings, setBookings] = useState<BookingMatch[]>([]);
  const [bookingId, setBookingId] = useState<number | null>(null);
  const [bookingDismissed, setBookingDismissed] = useState(false);
  const bodyRef = useRef<HTMLDivElement>(null);
  // Guards a second submit fired before React re-renders with `busy`.
  const submitting = useRef(false);

  const [matches, setMatches] = useDuplicateSuggestions(data, open && flow === 'new' && !done && !!config?.can.register);

  const preselect = useCallback((loaded: IntakeConfig | null) => {
    const doctorId = initialDoctorId ?? loaded?.booking?.doctor_id ?? null;

    return emptyVisit(loaded, doctorId ? String(doctorId) : '', initialSpecialty ?? '');
  }, [initialDoctorId, initialSpecialty]);

  const reset = useCallback(
    (nextMode: IntakeMode) => {
      setFlow(nextMode);
      setDone(false);
      setData(EMPTY_PATIENT);
      setErrors({});
      setVisit(preselect(config));
      setVisitErrors({});
      setQuery(nextMode === 'repeat' ? initialQuery : '');
      setPatient(null);
      setOverride(NO_OVERRIDE);
      setFormError(null);
      setResult(null);
      setRegistered(false);
      setConfirmDiscard(false);
      setKey(requestKey());
      setPaying(false);
      setBookings([]);
      setBookingId(null);
      setBookingDismissed(false);
    },
    [config, initialQuery, preselect],
  );

  // Each opening starts the requested workflow afresh.
  useEffect(() => {
    if (open) {
      reset(mode);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, mode]);

  // Defaults that need the configuration (the preselected doctor, the only
  // specialty, a booking's name and mobile, required sections open), once it arrives.
  useEffect(() => {
    if (!config) {
      return;
    }
    setVisit((current) => (current.doctor_id === '' && current.specialty === '' && current.reason_key === '' ? preselect(config) : current));
    if (config.booking) {
      const [first, ...rest] = (config.booking.name ?? '').trim().split(/\s+/);
      setData((current) => (current.phone === '' && current.first_name === '' ? { ...current, phone: config.booking?.phone ?? '', first_name: first ?? '', last_name: rest.join(' ') } : current));
    }
    // A member who may not register works in Repeat Case only.
    if (!config.can.register) {
      setFlow('repeat');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [config]);

  // Opened for a known patient: choose them, once.
  const openedFor = useRef<number | null>(null);
  useEffect(() => {
    if (!open) {
      openedFor.current = null;

      return;
    }
    if (config && initialPatient && mode === 'repeat' && openedFor.current !== initialPatient.id) {
      openedFor.current = initialPatient.id;
      void useExisting(initialPatient);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, config, initialPatient?.id, mode]);

  // No matches any more: the override has nothing to acknowledge.
  useEffect(() => {
    if (matches.length === 0) {
      setOverride(NO_OVERRIDE);
    }
  }, [matches.length]);

  // After saving, focus the next action; the in-dialog "discard?" prompt takes focus too.
  useEffect(() => {
    if (done || confirmDiscard) {
      window.requestAnimationFrame(() => bodyRef.current?.closest<HTMLElement>('[role=dialog]')?.querySelector<HTMLElement>(confirmDiscard ? '[role=alertdialog] [data-autofocus]' : '[data-autofocus]')?.focus());
    }
  }, [done, confirmDiscard]);

  const setField = useCallback(<K extends keyof PatientForm>(field: K, value: PatientForm[K]) => {
    setData((current) => ({ ...current, [field]: value }));
    setErrors((current) => (field in current || current.form ? { ...current, [field]: undefined, form: undefined } : current));
  }, []);
  // Several fields from the form as it is now (quick taps never work from an older copy); their errors clear.
  const patchData = useCallback((change: (current: PatientForm) => Partial<PatientForm>) => {
    setData((current) => ({ ...current, ...change(current) }));
    setErrors((current) => ({ ...current, conditions: undefined, allergies: undefined, allergy_status: undefined, medical_conditions: undefined }));
  }, []);
  const resolvedSpecialty = config ? visitSpecialty(config, visit) : '';
  // The field settings as they apply to this visit's module (the server applies the same); read only once loaded.
  const newCaseFields = config ? forModule(config, resolvedSpecialty) : null;
  const noun = config ? practitionerNoun(config, resolvedSpecialty) : 'Doctor';
  const patchVisit = (patch: Partial<VisitForm>) => {
    setVisit((current) => ({ ...current, ...patch }));
    setVisitErrors((current) => {
      const next = { ...current };
      for (const field of Object.keys(patch)) {
        delete next[field as keyof VisitForm];
      }

      return next;
    });
  };

  // Today's bookings made with this mobile number, for a New Case not already opened for a booking.
  const digits = data.phone.replace(/\D/g, '');
  useEffect(() => {
    if (!open || flow !== 'new' || done || digits.length < 10 || config?.booking || bookingDismissed) {
      if (digits.length < 10) {
        setBookings([]);
      }

      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      try {
        const response = await requestJson<{ bookings: BookingMatch[] }>('GET', route('clinic.reception.bookings.match'), { query: { phone: digits }, signal: controller.signal });
        setBookings(response.bookings);
      } catch {
        // Finding a booking is a convenience; registering works without it.
      }
    }, 400);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, digits, flow, done, bookingDismissed]);

  const useBooking = (match: BookingMatch) => {
    setBookingId(match.id);
    if (config && match.doctor && config.doctors.some((doctor) => doctor.id === match.doctor?.id)) {
      setVisit((current) => ({ ...current, doctor_id: String(match.doctor?.id), area: '', reason_key: '', visit_reason: '' }));
    }
  };
  const chosenDoctor = config?.doctors.find((doctor) => String(doctor.id) === visit.doctor_id) ?? null;
  // A session package never waives a check-in charge; it pays only for its own treatment's completed session (`SessionPackages::paidFor`).
  const billedDoctor = chosenDoctor;
  // What the desk shows will be charged at check-in; the server refuses the check-in if its own amount differs.
  // The visit's module (the chosen doctor's context): a module that never charges at check-in is never charged.
  const visitModule = config ? (contextOf(config, visit)?.module ?? null) : null;
  const expectedCharge = config && chargedAtCheckIn(config, billedDoctor, visitModule) ? (billedDoctor?.fee ?? null) : null;
  // Charged at check-in by a practitioner who could take this visit: the fee depends on who is assigned,
  // and the server assigns "next available" itself — so check-in asks reception to choose, and shows that fee.
  const feeNeedsDoctor = !!config && visit.doctor_id === '' && doctorsFor(config, resolvedSpecialty).some((doctor) => chargedAtCheckIn(config, doctor));
  // New Case asks only its quick fields; a field this clinic makes required beyond them is named, never silently skipped.
  const missingRequired = newCaseFields ? NOT_IN_NEW_CASE.filter(([field]) => required(newCaseFields, field)).map(([, label]) => label) : [];
  const createUrl = (() => {
    try {
      return route('clinic.patients.create');
    } catch {
      return null;
    }
  })();

  const dirty = !done && (JSON.stringify(data) !== JSON.stringify(EMPTY_PATIENT) || patient !== null || query.trim() !== '');

  const requestClose = (nextOpen: boolean) => {
    if (nextOpen) {
      onOpenChange(true);

      return;
    }
    if (busy) {
      return;
    }
    if (dirty && !confirmDiscard) {
      setConfirmDiscard(true);

      return;
    }
    onOpenChange(false);
  };

  /** New ↔ Repeat: what was typed in either stays. A mobile typed for New Case seeds the Repeat search. */
  const switchFlow = (next: IntakeMode) => {
    if (next === flow || busy) {
      return;
    }
    setFormError(null);
    if (next === 'repeat' && query.trim() === '' && data.phone.trim() !== '') {
      setQuery(data.phone.trim());
    }
    // A number searched for in Repeat Case that found nobody starts New Case's mobile.
    if (next === 'new' && data.phone.trim() === '' && /^\+?[\d\s-]{7,}$/.test(query.trim())) {
      setField('phone', query.trim());
    }
    setFlow(next);
  };

  /** Use an existing patient: the same dialog becomes Repeat Case for them. Nothing is registered. */
  const useExisting = async (match: PatientIdentity | DuplicateMatch) => {
    setOpeningId(match.id);
    try {
      const response = await requestJson<{ patients: LookupPatient[] }>('GET', route('clinic.reception.check-in.search'), { query: { q: match.uhid ?? match.name } });
      const found = response.patients.find((candidate) => candidate.id === match.id);
      if (!found) {
        setFormError('That patient could not be opened for check-in here. Search for them in Repeat Case.');

        return;
      }
      selectPatient(found);
    } catch {
      setFormError('That patient could not be opened. Check the connection and try again.');
    } finally {
      setOpeningId(null);
    }
  };

  const selectPatient = (selected: LookupPatient) => {
    if (!config) {
      return;
    }
    // A doctor the case was started for wins, then the booking's, then the patient's own.
    const preselected = initialDoctorId && config.doctors.some((doctor) => doctor.id === initialDoctorId) ? initialDoctorId : null;
    const known = preselected ?? selected.appointment?.doctor?.id ?? selected.assigned_doctor_id;
    const doctorId = known && config.doctors.some((doctor) => doctor.id === known) ? String(known) : visit.doctor_id;
    // The patient's own specialty matters only if no doctor is known.
    const specialty = selected.specialties.find((option) => config.specialties.some((candidate) => candidate.key === option)) ?? (visit.specialty || emptyVisit(config).specialty);
    setPatient(selected);
    setFlow('repeat');
    setVisit((current) => {
      const next = { ...current, specialty, doctor_id: doctorId, area: doctorId === current.doctor_id ? current.area : '' };
      const offered = reasonsFor(config, visitSpecialty(config, next), contextOf(config, next)?.area ?? null);

      return { ...next, reason_key: offered.some((item) => item.key === current.reason_key) ? current.reason_key : '' };
    });
    setFormError(null);
  };

  const fieldErrorsFrom = (error: unknown): Record<string, string> => {
    const body = error instanceof ClinicHttpError ? (error.body as { errors?: Record<string, string[]>; message?: string } | null) : null;

    return Object.fromEntries(Object.entries(body?.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
  };

  const visitErrorsFrom = (fieldErrors: Record<string, string>): VisitErrors => ({
    reason_key: fieldErrors.visit_reason_key,
    visit_reason: fieldErrors.visit_reason,
    doctor_id: fieldErrors.doctor_id ?? fieldErrors.practitioner_id ?? fieldErrors.visit_area ?? fieldErrors.appointment_id,
    specialty: fieldErrors.visit_specialty ?? fieldErrors.specialties,
    priority: fieldErrors.priority,
  });

  /** Folded sections open when they hold an error, then the first invalid field takes focus. */
  const revealErrors = (fields: string[]) => {
    window.requestAnimationFrame(() => {
      const target = bodyRef.current?.querySelector<HTMLElement>('[aria-invalid="true"], [role=alert]');
      target?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (target?.matches('input, select, textarea, button')) {
        target.focus();
      }
    });
  };

  const showDuplicates = () => {
    window.requestAnimationFrame(() => bodyRef.current?.querySelector<HTMLElement>('[id$="-duplicate-heading"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' }));
  };

  /** New Case: Register & check in (`checkIn`), or Register only. */
  const register = async (checkIn: boolean) => {
    if (!config || submitting.current) {
      return;
    }
    setFormError(null);
    // The settings as they apply to this visit's module (the server applies the same).
    const found: PatientErrors = validatePatient(forModule(config, resolvedSpecialty), data);
    if (matches.length > 0) {
      if (!override.open) {
        found.form = 'A possible existing patient was found. Use them, or confirm this is a different person.';
      } else {
        found.duplicate_reason = duplicateOverrideProblem(override.confirmed, override.reason) ?? undefined;
        if (!found.duplicate_reason) {
          delete found.duplicate_reason;
        }
      }
    }
    const foundVisit: VisitErrors = checkIn ? validateVisit(config, visit) : {};
    if (checkIn && (required(config, 'doctor_id') || feeNeedsDoctor) && visit.doctor_id === '') {
      foundVisit.doctor_id = feeNeedsDoctor ? `Choose the ${noun.toLowerCase()}: the consultation fee is charged at check-in.` : `Choose the ${noun.toLowerCase()}.`;
    }
    setErrors(found);
    setVisitErrors(foundVisit);
    if (Object.keys(found).length > 0 || Object.keys(foundVisit).length > 0) {
      if (found.form) {
        showDuplicates();
      } else {
        revealErrors(Object.keys(found));
      }

      return;
    }

    submitting.current = true;
    setBusy(checkIn ? 'register' : 'register_only');
    try {
      const response = await requestJson<CheckInResult>('POST', route('clinic.patients.store'), {
        body: {
          surface: 'new_case',
          ...patientPayload(forModule(config, resolvedSpecialty), data),
          idempotency_key: `${key}:${checkIn ? 'in' : 'only'}`,
          // The patient joins the specialty the visit resolved to (the server checks the doctor practises it).
          specialties: config.specialties.some((option) => option.key === resolvedSpecialty) ? [resolvedSpecialty] : [],
          ...(checkIn
            ? { check_in: true, doctor_id: visit.doctor_id ? Number(visit.doctor_id) : null, appointment_id: config.booking?.id ?? bookingId, ...visitPayload(config, visit), expected_charge: expectedCharge }
            : {}),
          ...overridePayload(matches, override),
        },
      });
      finish(response, true);
    } catch (error) {
      const kind = errorKind(error);
      const body = error instanceof ClinicHttpError ? (error.body as { possible_duplicates?: DuplicateMatch[]; message?: string } | null) : null;
      if (kind === 'conflict' && body?.possible_duplicates) {
        // The server found a match the live check had not.
        setMatches(body.possible_duplicates);
        setOverride(NO_OVERRIDE);
        setErrors({ form: 'A possible existing patient was found while saving. Use them, or confirm this is a different person.' });
        showDuplicates();
      } else if (kind === 'validation') {
        const fieldErrors = fieldErrorsFrom(error);
        if (body?.possible_duplicates) {
          setMatches(body.possible_duplicates);
        }
        setErrors(fieldErrors as PatientErrors);
        setVisitErrors(visitErrorsFrom(fieldErrors));
        setFormError(fieldErrors.expected_charge ?? fieldErrors.patient_id ?? fieldErrors.idempotency_key ?? 'Some details need attention.');
        revealErrors(Object.keys(fieldErrors));
      } else if (kind === 'conflict') {
        setFormError(body?.message ?? 'This is still being saved. Wait a moment.');
      } else if (kind === 'forbidden') {
        setFormError(body?.message && body.message !== 'This action is unauthorized.' ? body.message : 'You do not have permission to do this.');
      } else {
        setFormError('The patient was not saved. Check the connection and try again; nothing you entered was lost.');
      }
    } finally {
      submitting.current = false;
      setBusy(null);
    }
  };

  /** Repeat Case: check the chosen patient in. */
  const checkInExisting = async () => {
    if (!config || submitting.current) {
      return;
    }
    if (!patient) {
      setFormError('Find the patient first.');
      bodyRef.current?.querySelector<HTMLElement>('input[role=combobox]')?.focus();

      return;
    }
    const found = validateVisit(config, visit);
    if (feeNeedsDoctor) {
      found.doctor_id = `Choose the ${noun.toLowerCase()}: the consultation fee is charged at check-in.`;
    }
    setVisitErrors(found);
    if (Object.keys(found).length > 0) {
      revealErrors(Object.keys(found));

      return;
    }
    submitting.current = true;
    setBusy('check_in');
    setFormError(null);
    try {
      const response = await requestJson<CheckInResult>('POST', route('clinic.reception.check-in'), {
        body: {
          idempotency_key: key,
          patient_id: patient.id,
          appointment_id: patient.appointment?.id ?? null,
          practitioner_id: visit.doctor_id ? Number(visit.doctor_id) : null,
          ...visitPayload(config, visit),
          expected_charge: expectedCharge,
        },
      });
      finish(response, false);
    } catch (error) {
      const message = error instanceof ClinicHttpError ? ((error.body as { message?: string } | null)?.message ?? null) : null;
      if (errorKind(error) === 'validation') {
        const fieldErrors = fieldErrorsFrom(error);
        setVisitErrors(visitErrorsFrom(fieldErrors));
        setFormError(fieldErrors.expected_charge ?? fieldErrors.patient_id ?? fieldErrors.appointment_id ?? message);
        revealErrors(Object.keys(fieldErrors));
      } else if (errorKind(error) === 'conflict') {
        setFormError(message ?? 'This is still being saved. Wait a moment.');
      } else if (errorKind(error) === 'forbidden') {
        setFormError(message && message !== 'This action is unauthorized.' ? message : 'You do not have permission to check this patient in.');
      } else {
        setFormError('The check-in did not go through. Check the connection and try again.');
      }
    } finally {
      submitting.current = false;
      setBusy(null);
    }
  };

  const finish = (response: CheckInResult, wasRegistration: boolean) => {
    setResult(response);
    setRegistered(wasRegistration);
    setDone(true);
    // Only after a successful check-in, and only when something is owing and this member may collect it.
    setPaying(!!response.billing?.pay_url && (response.billing?.outstanding ?? 0) > 0);
    onCheckedIn?.();
    // The Front Desk's own list, where this page has one; other hosts refresh through `onCheckedIn`.
    if ('desk' in page.props) {
      router.reload({ only: ['desk'] });
    }
  };

  // Enter submits the primary action of the flow on screen.
  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (busy) {
      return;
    }
    if (flow === 'new') {
      void register(true);
    } else {
      void checkInExisting();
    }
  };

  const specialtyLabel = (specialty: string) => config?.specialties.find((option) => option.key === specialty)?.label ?? humanize(specialty);
  const alreadyQueued = flow === 'repeat' && patient?.in_queue != null;

  // What Check in is waiting for, said beside it while it cannot be pressed.
  const checkInBlocked = !patient ? 'Choose the patient first.' : alreadyQueued ? 'Already in today’s queue.' : feeNeedsDoctor ? `Choose the ${noun.toLowerCase()} first.` : null;

  const footer = confirmDiscard ? (
    <div role="alertdialog" aria-label="Discard this case?" className="flex w-full flex-wrap items-center justify-between gap-2 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-150">
      <p className="text-[13px] font-semibold text-clinic-ink">Discard what you have entered?</p>
      <div className="flex gap-2">
        <DsButton onClick={() => setConfirmDiscard(false)} data-autofocus>
          Keep working
        </DsButton>
        <DsButton variant="danger" onClick={() => onOpenChange(false)}>
          Discard
        </DsButton>
      </div>
    </div>
  ) : done ? (
    <>
      <DsButton onClick={() => onOpenChange(false)}>Close</DsButton>
      <DsButton variant="primary" onClick={() => reset(mode === 'new' && config?.can.register ? 'new' : 'repeat')} data-autofocus>
        {registered ? 'Register another' : 'Next patient'}
      </DsButton>
    </>
  ) : !config || failed || (flow === 'new' && !config.can.register) ? (
    <DsButton onClick={() => requestClose(false)}>Cancel</DsButton>
  ) : flow === 'new' ? (
    <>
      {/* The fee, said once, beside the actions it applies to; the × closes (with the discard prompt). */}
      <div className="mr-auto min-w-0 flex-1 max-sm:basis-full">
        <FeeSummary config={config!} doctor={chosenDoctor} module={visitModule} noun={noun} needsDoctor={feeNeedsDoctor} />
      </div>
      <DsButton onClick={() => void register(false)} loading={busy === 'register_only'} disabled={busy !== null} className="max-sm:flex-1">
        {busy === 'register_only' ? 'Saving…' : 'Register only'}
      </DsButton>
      <DsButton type="submit" form="reception-intake-form" variant="primary" icon={UserPlus} loading={busy === 'register'} disabled={busy !== null} className="max-sm:flex-1">
        {busy === 'register' ? 'Registering…' : 'Register & check in'}
      </DsButton>
    </>
  ) : (
    <>
      {/* The fee, said once, as in New Case; the × closes (with the discard prompt). */}
      <div className="mr-auto min-w-0 flex-1 max-sm:basis-full">
        <FeeSummary config={config!} doctor={chosenDoctor} module={visitModule} noun={noun} needsDoctor={feeNeedsDoctor} />
      </div>
      {checkInBlocked && (
        <p id="intake-check-in-hint" className="text-[12.5px] font-medium text-[#64748B] motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-150">
          {checkInBlocked}
        </p>
      )}
      <DsButton
        type="submit"
        form="reception-intake-form"
        variant="primary"
        icon={LogIn}
        loading={busy === 'check_in'}
        disabled={busy !== null || checkInBlocked !== null}
        aria-describedby={checkInBlocked ? 'intake-check-in-hint' : undefined}
      >
        {busy === 'check_in' ? 'Checking in…' : 'Check in'}
      </DsButton>
    </>
  );


  return (
    <Modal
      open={open}
      onOpenChange={requestClose}
      // New Case and Repeat Case: the dialog itself is the one white surface the form sits on.
      size={!done ? 'case' : 'form'}
      variant="desk"
      bodyClassName={!done ? 'bg-none bg-white px-4 py-4 sm:px-7' : undefined}
      title={done ? (registered ? 'Patient registered' : 'Checked in') : flow === 'new' ? 'New case' : 'Repeat case'}
      description={config ? (done ? config.branch.name : `${flow === 'new' ? 'Register a new patient and check them in' : 'Check in a returning patient'} · ${config.branch.name}`) : undefined}
      footer={footer}
    >
      <div ref={bodyRef}>
        {failed ? (
          <ErrorState description="The registration settings could not be loaded." onRetry={retry} />
        ) : !config ? (
          <FormSkeleton />
        ) : done && result ? (
          <>
            <DonePanel result={result} registered={registered} noun={noun} onCollect={result.billing?.pay_url && result.billing.outstanding > 0 ? () => setPaying(true) : undefined} />
            {result.billing && config && (
              <CollectPaymentDialog
                open={paying}
                billing={result.billing}
                methods={config.payment.methods}
                onPaid={(billing) => {
                  setResult((current) => (current ? { ...current, billing } : current));
                  setPaying(false);
                  onCheckedIn?.();
                }}
                onClose={() => setPaying(false)}
              />
            )}
          </>
        ) : (
          <>
            {formError && (
              <Notice tone="danger" className="mb-4">
                {formError}
              </Notice>
            )}
            {config.booking && flow === 'new' && (
              <Notice tone="info" className="mb-4" title={`Booked${config.booking.time ? ` for ${config.booking.time}` : ''} today`}>
                <span className="inline-flex items-center gap-1">
                  <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" />
                  Registering links this booking{config.doctors.find((doctor) => doctor.id === config.booking?.doctor_id) ? ` with ${config.doctors.find((doctor) => doctor.id === config.booking?.doctor_id)?.name}` : ''}; no second visit is created.
                </span>
              </Notice>
            )}

            {flow === 'new' ? (
              // New Case: its own presentation (`NewCaseForm`); the state, rules and request stay here.
              <form id="reception-intake-form" noValidate onSubmit={onSubmit}>
                <NewCaseForm
                  config={config}
                  fields={newCaseFields!}
                  data={data}
                  setField={setField}
                  patchData={patchData}
                  errors={errors}
                  matches={
                    matches.length > 0 || errors.form || (!config.booking && !bookingDismissed && bookings.length > 0) ? (
                      <div className="space-y-3 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-top-1 motion-safe:duration-200">
                        {errors.form && (
                          <p role="alert" className="text-[12.5px] font-semibold text-amber-900">
                            {errors.form}
                          </p>
                        )}
                        <DuplicateSuggestions
                          matches={matches}
                          specialtyLabel={specialtyLabel}
                          onUse={(match) => void useExisting(match)}
                          busyId={openingId}
                          override={override}
                          onOverrideChange={(patch) => {
                            setOverride((current) => ({ ...current, ...patch }));
                            setErrors((current) => ({ ...current, duplicate_reason: undefined, form: undefined }));
                          }}
                          reasonError={errors.duplicate_reason}
                          idPrefix="nc"
                        />
                        {!config.booking && !bookingDismissed && (
                          <BookingFound
                            matches={bookings}
                            chosen={bookingId}
                            onUse={useBooking}
                            onWalkIn={() => {
                              setBookingId(null);
                              setBookingDismissed(true);
                            }}
                          />
                        )}
                      </div>
                    ) : null
                  }
                  visit={visit}
                  onVisit={patchVisit}
                  visitErrors={visitErrors}
                  noun={noun}
                  missingRequired={missingRequired}
                  createUrl={createUrl}
                />
              </form>
            ) : (
              // Repeat Case: its own search-first presentation (`RepeatCaseForm`) on the same single surface.
              <form id="reception-intake-form" noValidate onSubmit={onSubmit}>
                <RepeatCaseForm
                  key={key}
                  config={config}
                  patient={patient}
                  query={query}
                  onQueryChange={setQuery}
                  onSelect={selectPatient}
                  onChangePatient={() => {
                    setPatient(null);
                    setFormError(null);
                  }}
                  busy={busy !== null}
                  visit={visit}
                  onVisit={patchVisit}
                  visitErrors={visitErrors}
                  noun={noun}
                  specialtyLabel={specialtyLabel}
                  onRegisterNew={config.can.register ? () => switchFlow('new') : null}
                />
              </form>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}

/** The sheet's outline while the settings load for the first time at this branch (later openings show the kept copy at once). */
function FormSkeleton() {
  return (
    <div role="status" aria-label="Loading the form" className="space-y-3">
      {[0, 1, 2].map((row) => (
        <div key={row} className="grid gap-3 sm:grid-cols-2">
          <div className="h-10 rounded-xl bg-[#EEF2F8] motion-safe:animate-pulse" />
          <div className="h-10 rounded-xl bg-[#EEF2F8] motion-safe:animate-pulse" />
        </div>
      ))}
      <div className="h-11 rounded-xl bg-[#EEF2F8] motion-safe:animate-pulse" />
    </div>
  );
}

/** After saving: who, for whom, what for, where in the queue, and the next actions this member may take (only to pages that are built). */
function DonePanel({ result, registered, noun, onCollect }: { result: CheckInResult; registered: boolean; noun: string; onCollect?: () => void }) {
  const checkIn = result.check_in;
  const context = checkIn?.context;
  const care = context ? [context.module?.label, context.area && context.area.label !== context.module?.label ? context.area.label : null].filter(Boolean).join(' · ') : '';
  const rows: [string, string][] = checkIn
    ? [
        [noun, checkIn.doctor ?? `Next available ${noun.toLowerCase()}`],
        ...(care ? [['Care', care] as [string, string]] : []),
        ...(checkIn.reason ? [['Reason', checkIn.reason] as [string, string]] : []),
        ['Priority', checkIn.priority.label],
        ...(checkIn.case_number ? [['Case', checkIn.case_number] as [string, string]] : []),
        ...(checkIn.queue && checkIn.position !== null ? [['Queue', `Position ${checkIn.position} today`] as [string, string]] : []),
      ]
    : [];

  return (
    // Shown only once the server has confirmed; the tick settles in once, quietly (none with reduced motion).
    <div role="status" className="mx-auto flex max-w-xl flex-col items-center gap-4 rounded-2xl border border-[#E6EBF1] bg-white px-4 py-6 text-center shadow-[0_1px_2px_rgba(15,23,42,0.04),0_8px_20px_-16px_rgba(23,59,96,0.35)] motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-200">
      <span className="grid h-16 w-16 place-items-center rounded-full bg-[#E3F6EC] motion-safe:animate-in motion-safe:zoom-in-75 motion-safe:duration-300" aria-hidden="true">
        <CheckCircle2 className="h-9 w-9 text-clinic-success" />
      </span>
      <div>
        <p className="text-[18px] font-bold tracking-tight text-clinic-ink">
          {registered ? 'Patient registered' : 'Checked in'} — {result.patient.name}
        </p>
        {result.patient.uhid && <p className="mt-0.5 text-[14px] font-semibold text-clinic-ink">{result.patient.uhid}</p>}
        {!checkIn && <p className="mt-1 text-[13px] text-clinic-secondary">Registered without checking in.</p>}
        {result.replayed && <p className="mt-1 text-[12.5px] text-clinic-secondary">This was already saved; nothing was saved twice.</p>}
      </div>
      {rows.length > 0 && (
        <dl className="w-full max-w-md divide-y divide-clinic-border rounded-2xl border border-clinic-border text-left">
          {rows.map(([label, value]) => (
            <div key={label} className="grid grid-cols-[96px_minmax(0,1fr)] gap-3 px-4 py-2 text-[13px]">
              <dt className="font-semibold text-clinic-secondary">{label}</dt>
              <dd className="min-w-0 break-words font-semibold text-clinic-ink">{value}</dd>
            </div>
          ))}
        </dl>
      )}
      <BillingResult result={result} />
      {onCollect && (
        <DsButton variant="primary" icon={IndianRupee} onClick={onCollect}>
          Collect payment
        </DsButton>
      )}
      {(result.links.patient || result.links.consultation) && (
        <div className="flex flex-wrap justify-center gap-2">
          {result.links.patient && (
            <Link href={result.links.patient} className="inline-flex min-h-10 items-center rounded-full border border-clinic-border px-4 text-[13px] font-semibold text-clinic-ink hover:border-clinic-border-strong">
              Open patient
            </Link>
          )}
          {result.links.consultation && (
            <ButtonLink href={result.links.consultation} icon={Stethoscope} size="sm">
              Go to Today
            </ButtonLink>
          )}
        </div>
      )}
    </div>
  );
}
