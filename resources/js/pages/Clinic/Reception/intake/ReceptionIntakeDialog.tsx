import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, ClipboardList, IndianRupee, LogIn, RefreshCw, ShieldAlert, Stethoscope, UserPlus, UserRound } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button, ButtonLink, duplicateOverrideProblem, ErrorState, humanize, LoadingState, Modal, Notice, PersonAvatar } from '@/clinic';
import { ClinicHttpError, errorKind, requestJson } from '@/clinic/http';
import { BillingResult, BookingFound, ChargeNotice, CollectPaymentDialog } from './CheckInPayment';
import {
  Collapsible,
  DuplicateSuggestions,
  EMPTY_PATIENT,
  forModule,
  MedicalSafetyFields,
  MORE_FIELD_KEYS,
  MoreDetailsFields,
  moreFields,
  NO_OVERRIDE,
  overridePayload,
  PatientIdentityFields,
  patientPayload,
  requestKey,
  required,
  SAFETY_FIELD_KEYS,
  safetyRequired,
  showsSafety,
  useDuplicateSuggestions,
  validatePatient,
  type DuplicateOverrideState,
  type PatientErrors,
  type PatientForm,
} from './PatientFields';
import { PatientSearchStep } from './PatientSearchStep';
import { SafetyFlags } from './SafetyFlags';
import { chargedAtCheckIn, contextOf, formatDay, reasonsFor, visitSpecialty, type BookingMatch, type CheckInResult, type DuplicateMatch, type IntakeConfig, type LookupPatient, type VisitForm } from './types';
import { useIntakeConfig } from './useIntakeConfig';
import { emptyVisit, validateVisit, VisitFields, visitPayload, type VisitErrors } from './VisitFields';

export type IntakeMode = 'new' | 'repeat';
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
  const [moreOpen, setMoreOpen] = useState(false);
  const [safetyOpen, setSafetyOpen] = useState(false);
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
      setMoreOpen(config ? moreFields(config).some((field) => required(config, field)) : false);
      setSafetyOpen(config ? safetyRequired(config) : false);
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
    setMoreOpen((current) => current || moreFields(config).some((field) => required(config, field)));
    setSafetyOpen((current) => current || safetyRequired(config));
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
  const resolvedSpecialty = config ? visitSpecialty(config, visit) : '';
  const resolvedArea = config ? (contextOf(config, visit)?.area ?? null) : null;
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
  // A returning patient whose session package pays for this kind of visit is charged nothing at check-in (`CheckInCharges::dueAtCheckIn`).
  const packageCovered = flow === 'repeat' && !!patient && !!config && resolvedSpecialty !== '' && patient.package_covers.includes(resolvedSpecialty);
  const billedDoctor = packageCovered ? null : chosenDoctor;
  // What the desk shows will be charged at check-in; the server refuses the check-in if its own amount differs.
  // The visit's module (the chosen doctor's context): a module that never charges at check-in is never charged.
  const visitModule = config ? (contextOf(config, visit)?.module ?? null) : null;
  const expectedCharge = config && chargedAtCheckIn(config, billedDoctor, visitModule) ? (billedDoctor?.fee ?? null) : null;

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
    if (fields.some((field) => MORE_FIELD_KEYS.includes(field))) {
      setMoreOpen(true);
    }
    if (fields.some((field) => SAFETY_FIELD_KEYS.includes(field))) {
      setSafetyOpen(true);
    }
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
    if (checkIn && required(config, 'doctor_id') && visit.doctor_id === '') {
      foundVisit.doctor_id = 'Choose the doctor.';
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

  const footer = confirmDiscard ? (
    <div role="alertdialog" aria-label="Discard this case?" className="flex w-full flex-wrap items-center justify-between gap-2">
      <p className="text-[13px] font-semibold text-clinic-ink">Discard what you have entered?</p>
      <div className="flex gap-2">
        <Button onClick={() => setConfirmDiscard(false)} data-autofocus>
          Keep working
        </Button>
        <Button tone="danger" onClick={() => onOpenChange(false)}>
          Discard
        </Button>
      </div>
    </div>
  ) : done ? (
    <>
      <Button onClick={() => onOpenChange(false)}>Close</Button>
      <Button tone="primary" onClick={() => reset(mode === 'new' && config?.can.register ? 'new' : 'repeat')} data-autofocus>
        {registered ? 'Register another' : 'Next patient'}
      </Button>
    </>
  ) : !config || failed || (flow === 'new' && !config.can.register) ? (
    <Button onClick={() => requestClose(false)}>Cancel</Button>
  ) : flow === 'new' ? (
    <>
      <Button tone="ghost" onClick={() => requestClose(false)} disabled={busy !== null} className="mr-auto">
        Cancel
      </Button>
      <Button onClick={() => void register(false)} loading={busy === 'register_only'} loadingLabel="Saving…" disabled={busy !== null}>
        Register only
      </Button>
      <Button type="submit" form="reception-intake-form" tone="primary" icon={UserPlus} loading={busy === 'register'} loadingLabel="Registering…" disabled={busy !== null} className="max-sm:w-full">
        Register &amp; check in
      </Button>
    </>
  ) : (
    <>
      <Button tone="ghost" onClick={() => requestClose(false)} disabled={busy !== null} className="mr-auto">
        Cancel
      </Button>
      <Button type="submit" form="reception-intake-form" tone="primary" icon={LogIn} loading={busy === 'check_in'} loadingLabel="Checking in…" disabled={busy !== null || !patient || alreadyQueued}>
        Check in
      </Button>
    </>
  );


  return (
    <Modal
      open={open}
      onOpenChange={requestClose}
      size="wide"
      title={done ? (registered ? 'Patient registered' : 'Checked in') : flow === 'new' ? 'New case' : 'Repeat case'}
      description={config ? (done ? config.branch.name : `${flow === 'new' ? 'Register a new patient and check them in' : 'Check in a returning patient'} · ${config.branch.name}`) : undefined}
      footer={footer}
    >
      <div ref={bodyRef}>
        {failed ? (
          <ErrorState description="The registration settings could not be loaded." onRetry={retry} />
        ) : !config ? (
          <LoadingState label="Getting the desk ready…" />
        ) : done && result ? (
          <>
            <DonePanel result={result} registered={registered} onCollect={result.billing?.pay_url && result.billing.outstanding > 0 ? () => setPaying(true) : undefined} />
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
            {config.can.register && <FlowSwitch value={flow} onChange={switchFlow} disabled={busy !== null} />}

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

            <form id="reception-intake-form" noValidate onSubmit={onSubmit} className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.08fr)_minmax(0,1fr)] lg:gap-0 lg:divide-x lg:divide-clinic-border">
              <Column title={flow === 'new' || patient ? 'Patient' : 'Find the patient'} icon={UserRound} className="lg:pr-6">
                {flow === 'new' ? (
                  <div className="space-y-3.5">
                    <PatientIdentityFields config={forModule(config, resolvedSpecialty)} data={data} setField={setField} errors={errors} idPrefix="nc" autoFocus />
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
                    {showsSafety(forModule(config, resolvedSpecialty)) && (
                      <Collapsible
                        id="nc-safety"
                        title="Medical safety"
                        icon={ShieldAlert}
                        open={safetyOpen}
                        onOpenChange={setSafetyOpen}
                        summary={safetySummary(data) ?? (safetyRequired(forModule(config, resolvedSpecialty)) ? 'Required' : 'Optional')}
                      >
                        <MedicalSafetyFields config={forModule(config, resolvedSpecialty)} data={data} setField={setField} errors={errors} specialty={resolvedSpecialty} area={resolvedArea} generalOnly={config.specialties.length > 1 && resolvedSpecialty === ''} />
                      </Collapsible>
                    )}
                    {moreFields(forModule(config, resolvedSpecialty)).length > 0 && (
                      <Collapsible id="nc-more" title="Contact details" icon={ClipboardList} open={moreOpen} onOpenChange={setMoreOpen} summary={moreFields(forModule(config, resolvedSpecialty)).some((field) => required(forModule(config, resolvedSpecialty), field)) ? 'Some are required' : 'Optional'}>
                        <MoreDetailsFields config={forModule(config, resolvedSpecialty)} data={data} setField={setField} errors={errors} idPrefix="nc" />
                      </Collapsible>
                    )}
                  </div>
                ) : patient ? (
                  <ChosenPatient
                    patient={patient}
                    specialtyLabel={specialtyLabel}
                    onChange={() => {
                      setPatient(null);
                      setFormError(null);
                    }}
                    disabled={busy !== null}
                  />
                ) : (
                  <PatientSearchStep config={config} query={query} onQueryChange={setQuery} onSelect={selectPatient} />
                )}
              </Column>

              <Column title="Today’s visit" icon={Stethoscope} className="lg:pl-6">
                <div className="space-y-4">
                  {/* Keyed by the request: each new case starts with doctor auto-selection fresh. */}
                  <VisitFields key={key} config={config} value={visit} onChange={patchVisit} errors={visitErrors} idPrefix={flow === 'new' ? 'nc-visit' : 'rc-visit'} showPriority />
                  <ChargeNotice config={config} doctor={billedDoctor} module={visitModule} />
                  {packageCovered && chargedAtCheckIn(config, chosenDoctor, visitModule) && (
                    <p role="status" className="rounded-xl bg-clinic-inset/60 px-3 py-2.5 text-[12.5px] text-clinic-ink">
                      <span className="font-semibold">Paid by their session package</span> — nothing is charged at check-in.
                    </p>
                  )}
                  {flow === 'new' && (
                    <p className="text-[12px] leading-relaxed text-clinic-secondary">
                      <span className="font-semibold text-clinic-ink">Register &amp; check in</span> adds them to today&apos;s {config.check_in.queue ? 'queue' : 'list'}
                      {expectedCharge !== null ? ` and charges the consultation shown above` : ''}. <span className="font-semibold text-clinic-ink">Register only</span> saves the patient without a visit and charges nothing.
                    </p>
                  )}
                </div>
              </Column>
            </form>
          </>
        )}
      </div>
    </Modal>
  );
}

function safetySummary(data: PatientForm): string | null {
  const parts = [data.conditions.length > 0 ? `${data.conditions.length} condition${data.conditions.length === 1 ? '' : 's'}` : null, data.allergies.length > 0 ? `${data.allergies.length} allerg${data.allergies.length === 1 ? 'y' : 'ies'}` : data.allergy_none ? 'No known allergies' : null].filter(Boolean);

  return parts.length > 0 ? parts.join(' · ') : null;
}

/** New patient or returning patient: a two-way switch at the top of the dialog. */
function FlowSwitch({ value, onChange, disabled }: { value: IntakeMode; onChange: (mode: IntakeMode) => void; disabled: boolean }) {
  const options: { mode: IntakeMode; label: string; icon: typeof UserPlus }[] = [
    { mode: 'new', label: 'New patient', icon: UserPlus },
    { mode: 'repeat', label: 'Returning patient', icon: RefreshCw },
  ];

  return (
    <div role="group" aria-label="Case type" className="mb-5 inline-flex w-full rounded-full border border-clinic-border bg-clinic-inset/60 p-1 sm:w-auto">
      {options.map((option) => (
        <button
          key={option.mode}
          type="button"
          aria-pressed={value === option.mode}
          disabled={disabled}
          onClick={() => onChange(option.mode)}
          className={cn(
            'inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-full px-4 text-[13px] font-semibold motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong sm:flex-none',
            value === option.mode ? 'bg-clinic-surface text-clinic-ink shadow-sm' : 'text-clinic-secondary hover:text-clinic-ink',
          )}
        >
          <option.icon className="h-4 w-4" aria-hidden="true" />
          {option.label}
        </button>
      ))}
    </div>
  );
}

function Column({ title, icon: Icon, className, children }: { title: string; icon: typeof UserRound; className?: string; children: ReactNode }) {
  const id = `intake-col-${title.replace(/\W+/g, '-').toLowerCase()}`;

  return (
    <section aria-labelledby={id} className={cn('min-w-0', className)}>
      <h3 id={id} className="mb-3.5 flex items-center gap-1.5 text-[12px] font-bold uppercase tracking-wide text-clinic-secondary">
        <Icon className="h-4 w-4" aria-hidden="true" />
        {title}
      </h3>
      {children}
    </section>
  );
}

/** Repeat Case: who they are at a glance and their safety flags; today's visit is beside it. */
function ChosenPatient({ patient, specialtyLabel, onChange, disabled }: { patient: LookupPatient; specialtyLabel: (key: string) => string; onChange: () => void; disabled: boolean }) {
  return (
    <div className="space-y-3.5">
      <div className="flex items-start gap-3 rounded-2xl border border-clinic-border bg-clinic-inset/40 p-4">
        <PersonAvatar name={patient.name} size="lg" />
        <div className="min-w-0 flex-1">
          <p className="text-[17px] font-bold tracking-tight text-clinic-ink">{patient.name}</p>
          <p className="text-[12.5px] text-clinic-secondary">
            {[patient.uhid, [patient.age !== null ? `${patient.age}` : null, patient.gender ? humanize(patient.gender) : null].filter(Boolean).join(' · '), patient.phone].filter(Boolean).join(' · ')}
          </p>
          <p className="mt-1 text-[12.5px] text-clinic-ink">
            {[patient.specialties.map(specialtyLabel).join(', ') || null, patient.last_seen ? `Last visit ${formatDay(patient.last_seen.at)} at ${patient.last_seen.branch}` : 'No visits yet'].filter(Boolean).join(' · ')}
          </p>
          {patient.address && (
            <p className="mt-1 break-words text-[12.5px] text-clinic-secondary">
              <span className="font-semibold text-clinic-ink">Address:</span> {patient.address}
            </p>
          )}
          {patient.safety_flags.length > 0 && (
            <div className="mt-2.5">
              <p className="mb-1 text-[11.5px] font-bold uppercase tracking-wide text-clinic-secondary">Safety</p>
              <SafetyFlags flags={patient.safety_flags} />
            </div>
          )}
        </div>
        <Button tone="ghost" size="sm" onClick={onChange} disabled={disabled} className="shrink-0">
          Change
        </Button>
      </div>

      {patient.in_queue && (
        <Notice tone="warning" title="Already in today’s queue">
          {patient.in_queue.case_short ? `Case ${patient.in_queue.case_short}, ` : ''}status {humanize(patient.in_queue.status).toLowerCase()}. They do not need checking in again.
        </Notice>
      )}
      {patient.appointment && !patient.in_queue && (
        <Notice tone="info" title={`Booked for ${patient.appointment.time.slice(11, 16)} today`}>
          <span className="inline-flex items-center gap-1">
            <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" />
            Checking in links the appointment{patient.appointment.doctor ? ` with ${patient.appointment.doctor.name}` : ''}.
          </span>
        </Notice>
      )}
    </div>
  );
}

/** After saving: who, for whom, what for, where in the queue, and the next actions this member may take (only to pages that are built). */
function DonePanel({ result, registered, onCollect }: { result: CheckInResult; registered: boolean; onCollect?: () => void }) {
  const checkIn = result.check_in;
  const context = checkIn?.context;
  const care = context ? [context.module?.label, context.area && context.area.label !== context.module?.label ? context.area.label : null].filter(Boolean).join(' · ') : '';
  const rows: [string, string][] = checkIn
    ? [
        ['Doctor', checkIn.doctor ?? 'Next available doctor'],
        ...(care ? [['Care', care] as [string, string]] : []),
        ...(checkIn.reason ? [['Reason', checkIn.reason] as [string, string]] : []),
        ['Priority', checkIn.priority.label],
        ...(checkIn.case_number ? [['Case', checkIn.case_number] as [string, string]] : []),
        ...(checkIn.queue && checkIn.position !== null ? [['Queue', `Position ${checkIn.position} today`] as [string, string]] : []),
      ]
    : [];

  return (
    <div role="status" className="flex flex-col items-center gap-4 py-4 text-center">
      <CheckCircle2 className="h-12 w-12 text-clinic-success" aria-hidden="true" />
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
        <Button tone="primary" icon={IndianRupee} onClick={onCollect}>
          Collect payment
        </Button>
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
