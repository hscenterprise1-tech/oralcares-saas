import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { usePage, router } from '@inertiajs/react';
import {
  RotateCcw,
  Stethoscope,
  IndianRupee,
  CheckCircle2,
  AlertTriangle,
  UserCheck,
  CalendarClock,
  Clock,
  ShieldCheck,
  Building2,
  MapPin,
  HeartPulse,
  ArrowRight,
  ArrowLeft,
  UserRound,
  ClipboardList,
} from 'lucide-react';
import { Modal } from '@/clinic/ui/Modal';
import { ClinicHttpError, errorKind, requestJson } from '@/clinic/http';
import { cn } from '@/lib/utils';
import { useIntakeConfig } from './useIntakeConfig';
import { AddressLookupSection } from './AddressLookupSection';
import { MedicalHistorySection } from './MedicalHistorySection';
import { VisitComplaintSection } from './VisitComplaintSection';
import { IconChip } from './IconChip';
import { BillingPaymentSummary, type PaymentState } from './BillingPaymentSummary';
import { FinalConfirmationView } from './FinalConfirmationView';
import { defaultTherapist, RepeatCareChoice, RepeatDentalStep, RepeatPatientBar, RepeatPatientSearch, RepeatPhysioStep } from './RepeatCaseSteps';
import type { PhysioCare } from '@/clinic/patients/PhysioCare';
import {
  calculateDetailedAge,
  contextOf,
  formatApproximateAge,
  maskPhone,
  visitSpecialty,
  type CalculatedAgeInfo,
  type CheckInResult,
  type DuplicateMatch,
  type IntakeConfig,
  type LookupPatient,
  type PatientCaseSummary,
  type PractitionerOption,
  type VisitForm,
} from './types';
import { emptyVisit, visitPayload, type VisitErrors } from './VisitFields';
import { requestKey, useDuplicateSuggestions } from './PatientFields';

export type IntakeMode = 'new' | 'repeat';
export type PatientIdentity = { id: number; uhid: string | null; name: string };
export type DialogStage = 'details' | 'billing' | 'confirmed';

interface PatientFormData {
  first_name: string;
  middle_name: string;
  last_name: string;
  phone: string;
  gender: string;
  date_of_birth: string;
  dob_unknown: boolean;
  age: string;
  age_months: string;
  email: string;
  pincode: string;
  locality: string;
  city: string;
  state: string;
  address: string;
  conditions: Array<{ key?: string; label: string }>;
  notes: string;
}

const AVATAR_PASTEL_COLORS = [
  'bg-teal-50 text-teal-700 border-teal-200',
  'bg-blue-50 text-blue-700 border-blue-200',
  'bg-purple-50 text-purple-700 border-purple-200',
  'bg-indigo-50 text-indigo-700 border-indigo-200',
  'bg-rose-50 text-rose-700 border-rose-200',
  'bg-emerald-50 text-emerald-700 border-emerald-200',
];

const EMPTY_PATIENT_FORM: PatientFormData = {
  first_name: '',
  middle_name: '',
  last_name: '',
  phone: '',
  gender: 'male',
  date_of_birth: '',
  dob_unknown: false,
  age: '',
  age_months: '',
  email: '',
  pincode: '',
  locality: '',
  city: '',
  state: '',
  address: '',
  conditions: [],
  notes: '',
};

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
  initialQuery?: string;
  initialDoctorId?: number | null;
  appointmentId?: number | null;
  initialPatient?: PatientIdentity | null;
  initialSpecialty?: string | null;
  onCheckedIn?: (result?: CheckInResult) => void;
}) {
  const page = usePage();
  const { config, failed, retry } = useIntakeConfig(open, branchKey, appointmentId);

  // Workflow Stage: 'details' -> 'billing' -> 'confirmed'
  const [stage, setStage] = useState<DialogStage>('details');

  // Active Flow Mode ('new' | 'repeat') - strictly set from props
  const [currentMode, setCurrentMode] = useState<IntakeMode>(mode);

  // Patient Registration Form Data
  const [formData, setFormData] = useState<PatientFormData>(EMPTY_PATIENT_FORM);
  const [patientErrors, setPatientErrors] = useState<Record<string, string>>({});

  // Duplicate Detection & Overrides
  const [duplicateMatches, setDuplicateMatches] = useDuplicateSuggestions(
    {
      ...formData,
      allergies: [],
      medications: [],
      allergy_none: false,
      alternate_phone: '',
      blood_group: '',
      emergency_contact_name: '',
      emergency_contact_phone: '',
      country: '',
      alert_keys: [],
      surgical_history: [],
      family_history: [],
    },
    open && currentMode === 'new'
  );
  const [duplicateOverride, setDuplicateOverride] = useState({
    confirmed: false,
    reason: 'Family member sharing phone',
  });

  // Returning Patient Search State
  const [searchQuery, setSearchQuery] = useState(initialQuery);
  const [searchResults, setSearchResults] = useState<LookupPatient[]>([]);
  const [recentPatients, setRecentPatients] = useState<LookupPatient[]>([]);
  const [isSearching, setIsSearching] = useState(false);
  const [selectedPatient, setSelectedPatient] = useState<LookupPatient | null>(null);

  // Module Awareness
  const hasDental = Boolean(config?.specialties?.some((s) => s.key === 'dental' || s.key === 'orthodontics'));
  const hasPhysio = Boolean(config?.specialties?.some((s) => s.key === 'physiotherapy'));
  const [activeModuleTab, setActiveModuleTab] = useState<'dental' | 'physio'>(hasPhysio && !hasDental ? 'physio' : 'dental');
  const [multiSpecialtyChoiceNeeded, setMultiSpecialtyChoiceNeeded] = useState(false);

  // Physio Care & Package Telemetry State
  const [physioSummary, setPhysioSummary] = useState<PhysioCare | null>(null);
  const [loadingPhysio, setLoadingPhysio] = useState(false);
  // Which of the patient's physiotherapy treatments today's visit continues (the first until the desk picks another).
  const [physioTreatmentId, setPhysioTreatmentId] = useState<number | null>(null);

  // Visit Details Form State
  const [visit, setVisit] = useState<VisitForm>(() =>
    emptyVisit(config, initialDoctorId ? String(initialDoctorId) : '', initialSpecialty || '')
  );
  const [visitErrors, setVisitErrors] = useState<VisitErrors>({});

  // Payment State
  const [paymentState, setPaymentState] = useState<PaymentState>({
    mode: 'full',
    method: 'cash',
    amount: '',
    reference: '',
  });

  // Submission & Confirmation State
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [paymentWarning, setPaymentWarning] = useState<string | null>(null);
  const [checkInResult, setCheckInResult] = useState<CheckInResult | null>(null);
  const [confirmDiscard, setConfirmDiscard] = useState(false);
  const idempotencyKey = useRef<string>(requestKey());

  const prevOpenRef = useRef(false);

  // Initialize or reset when dialog opens
  useEffect(() => {
    if (open && !prevOpenRef.current) {
      setCurrentMode(mode);
      setStage('details');
      idempotencyKey.current = requestKey();
      setSubmitError(null);
      setPaymentWarning(null);
      setCheckInResult(null);
      setConfirmDiscard(false);

      // Initialize visit immediately without waiting for config API
      const defaultDoctorId = initialDoctorId
        ? String(initialDoctorId)
        : mode === 'new'
          ? ''
          : config && config.doctors.length > 0
            ? String(config.doctors[0].id)
            : '';

      setVisit(emptyVisit(config, defaultDoctorId, initialSpecialty || ''));

      if (config?.payment.methods && config.payment.methods.length > 0) {
        setPaymentState((prev) => ({
          ...prev,
          method: config.payment.methods[0].value,
        }));
      }

      // Fetch recent patients for returning search asynchronously
      if (mode === 'repeat') {
        requestJson<{ patients: LookupPatient[] }>('GET', route('clinic.reception.check-in.recent'))
          .then((res) => setRecentPatients(res.patients || []))
          .catch(() => setRecentPatients([]));
      }
    }
    prevOpenRef.current = open;
  }, [open, mode, initialDoctorId, initialSpecialty, config]);

  // Synchronize asynchronous config reference data smoothly once loaded
  useEffect(() => {
    if (open && config) {
      if (config.payment.methods.length > 0) {
        setPaymentState((prev) => ({
          ...prev,
          method: prev.method || config.payment.methods[0].value,
        }));
      }
      if (config.specialties.length === 1) {
        setVisit((prev) => (prev.specialty ? prev : { ...prev, specialty: config.specialties[0].key }));
      }
    }
  }, [open, config]);

  // Live debounced search for Returning Patient
  useEffect(() => {
    if (!open || currentMode !== 'repeat') return;
    const trimmed = searchQuery.trim();
    if (trimmed.length < 3) {
      setSearchResults([]);
      setIsSearching(false);
      return;
    }

    const abort = new AbortController();
    setIsSearching(true);
    const timer = window.setTimeout(async () => {
      try {
        const res = await requestJson<{ patients: LookupPatient[] }>(
          'GET',
          route('clinic.reception.check-in.search'),
          { query: { q: trimmed }, signal: abort.signal }
        );
        setSearchResults(res.patients || []);
      } catch {
        // Search error handled gracefully
      } finally {
        setIsSearching(false);
      }
    }, 220);

    return () => {
      window.clearTimeout(timer);
      abort.abort();
    };
  }, [searchQuery, open, currentMode]);

  // Fetch Physio Summary when returning patient is selected
  useEffect(() => {
    if (!open || currentMode !== 'repeat' || !selectedPatient || !hasPhysio) {
      setPhysioSummary(null);
      return;
    }
    setLoadingPhysio(true);
    requestJson<{ physio: PhysioCare | null }>(
      'GET',
      route('clinic.reception.physio-summary', selectedPatient.id)
    )
      .then((res) => {
        setPhysioSummary(res.physio);
        setLoadingPhysio(false);
        if (hasDental && hasPhysio) {
          const hasPhysioTreatments = (res.physio?.treatments || []).length > 0;
          const hasDentalCases = (selectedPatient.cases || []).some(
            (c) => c.specialty === 'dental' || c.specialty === 'orthodontics'
          );
          if (hasPhysioTreatments && !hasDentalCases) {
            setActiveModuleTab('physio');
          }
        }
      })
      .catch(() => {
        setPhysioSummary(null);
        setLoadingPhysio(false);
      });
  }, [open, currentMode, selectedPatient?.id, hasPhysio, hasDental]);

  // Handle selected patient from repeat search
  const handleSelectPatient = (p: LookupPatient) => {
    setSelectedPatient(p);
    setSearchQuery('');
    setSearchResults([]);
    setSubmitError(null);

    // Auto-resolve case and specialty
    const dentalCase = (p.cases || []).find((c) => c.specialty === 'dental' || c.specialty === 'orthodontics');
    const physioCase = (p.cases || []).find((c) => c.specialty === 'physiotherapy');

    // In combined clinics where patient has BOTH active dental and physio cases, prompt 1-click choice
    if (hasDental && hasPhysio && dentalCase && physioCase) {
      setMultiSpecialtyChoiceNeeded(true);
      setStage('details');
      return;
    }

    setMultiSpecialtyChoiceNeeded(false);

    let initialTab: 'dental' | 'physio' = 'dental';
    if (hasPhysio && !hasDental) {
      initialTab = 'physio';
    } else if (hasDental && !hasPhysio) {
      initialTab = 'dental';
    } else if (physioCase && !dentalCase) {
      initialTab = 'physio';
    } else {
      initialTab = 'dental';
    }
    setActiveModuleTab(initialTab);

    if (initialTab === 'physio') {
      const relevantCase = physioCase || p.cases?.[0] || null;
      // Today's therapist is offered from the physiotherapists on the roster (on shift first), never a dentist.
      const onShiftDoc = defaultTherapist(config);
      setVisit((prev) => ({
        ...prev,
        case_id: relevantCase?.id || null,
        new_case: false,
        specialty: 'physiotherapy',
        visit_type: 'procedure',
        // Left empty for the optional "Session focus"; check-in still sends "Physiotherapy Session" when it stays empty.
        visit_reason: '',
        doctor_id: onShiftDoc ? String(onShiftDoc.id) : prev.doctor_id,
        priority: 'normal',
      }));
      setStage('details');
    } else {
      const relevantCase = dentalCase || p.cases?.[0] || null;
      const assignedDoctorId =
        relevantCase?.doctor?.id && config?.doctors.some((d) => d.id === relevantCase.doctor?.id)
          ? String(relevantCase.doctor.id)
          : config?.doctors?.[0]?.id
            ? String(config.doctors[0].id)
            : '';

      setVisit((prev) => ({
        ...prev,
        case_id: relevantCase?.id || null,
        new_case: false,
        specialty: relevantCase?.specialty || 'dental',
        visit_type: 'follow_up',
        visit_reason: relevantCase?.title ? `Follow-up: ${relevantCase.title}` : 'Follow-up Consultation',
        doctor_id: assignedDoctorId,
        priority: 'normal',
      }));

      // Dental Repeat Case: Continue with assigned doctor directly to the payment step!
      setStage('billing');
    }
  };

  // Handle care stream selection in multi-specialty clinics when patient has both
  const handleChooseCareStream = (stream: 'dental' | 'physio') => {
    setMultiSpecialtyChoiceNeeded(false);
    setActiveModuleTab(stream);
    if (!selectedPatient) return;

    const dentalCase = (selectedPatient.cases || []).find((c) => c.specialty === 'dental' || c.specialty === 'orthodontics');
    const physioCase = (selectedPatient.cases || []).find((c) => c.specialty === 'physiotherapy');

    if (stream === 'physio') {
      const relevantCase = physioCase || selectedPatient.cases?.[0] || null;
      const onShiftDoc = defaultTherapist(config);
      setVisit((prev) => ({
        ...prev,
        case_id: relevantCase?.id || null,
        new_case: false,
        specialty: 'physiotherapy',
        visit_type: 'procedure',
        visit_reason: '',
        doctor_id: onShiftDoc ? String(onShiftDoc.id) : prev.doctor_id,
        priority: 'normal',
      }));
      setStage('details');
    } else {
      const relevantCase = dentalCase || selectedPatient.cases?.[0] || null;
      const assignedDoctorId =
        relevantCase?.doctor?.id && config?.doctors.some((d) => d.id === relevantCase.doctor?.id)
          ? String(relevantCase.doctor.id)
          : config?.doctors?.[0]?.id
            ? String(config.doctors[0].id)
            : '';

      setVisit((prev) => ({
        ...prev,
        case_id: relevantCase?.id || null,
        new_case: false,
        specialty: relevantCase?.specialty || 'dental',
        visit_type: 'follow_up',
        visit_reason: relevantCase?.title ? `Follow-up: ${relevantCase.title}` : 'Follow-up Consultation',
        doctor_id: assignedDoctorId,
        priority: 'normal',
      }));
      setStage('billing');
    }
  };

  // Repeat Case: back to the search, with nothing of the previous patient kept
  const handleChangePatient = () => {
    setSelectedPatient(null);
    setPhysioSummary(null);
    setPhysioTreatmentId(null);
    setMultiSpecialtyChoiceNeeded(false);
    setVisitErrors({});
    setSubmitError(null);
    setStage('details');
  };

  // Repeat Case, no match: register them with New Case, carrying what was typed (a mobile number, else the name)
  const handleRegisterFromSearch = () => {
    const typed = searchQuery.trim();
    const looksLikeMobile = /^\+?[\d\s-]{7,}$/.test(typed);
    setCurrentMode('new');
    setFormData((prev) => (looksLikeMobile ? { ...prev, phone: typed } : { ...prev, first_name: typed }));
  };

  // Derived age for exact DOB mode
  const calculatedAge = useMemo(() => {
    if (formData.dob_unknown || !formData.date_of_birth) return null;
    return calculateDetailedAge(formData.date_of_birth);
  }, [formData.dob_unknown, formData.date_of_birth]);

  // Derived age summary for approximate age mode
  const approximateAgeSummary = useMemo(() => {
    if (!formData.dob_unknown) return '';
    return formatApproximateAge(formData.age, formData.age_months);
  }, [formData.dob_unknown, formData.age, formData.age_months]);

  // Active module mode for Repeat Case
  const isPhysioSelected = currentMode === 'repeat' && hasPhysio && (activeModuleTab === 'physio' || !hasDental);

  // Active physio package telemetry
  const activePhysioTreatment = physioSummary?.treatments?.find((treatment) => treatment.id === physioTreatmentId) || physioSummary?.treatments?.[0] || null;
  const activePackage = activePhysioTreatment?.packages?.find((pkg) => pkg.remaining > 0) || activePhysioTreatment?.packages?.[0] || null;
  const plannedSessions = activePhysioTreatment?.sessions_planned ?? activePackage?.sessions_total ?? null;
  const completedSessions = activePhysioTreatment?.sessions_completed ?? 0;
  const remainingSessions = activePackage ? activePackage.remaining : 0;
  const nextSessionNumber = completedSessions + 1;
  const hasPackageEntitlement = Boolean(activePackage && activePackage.remaining > 0);

  // Get active doctor details and fee calculation
  const chosenDoctor = config?.doctors.find((d) => String(d.id) === visit.doctor_id) || null;
  const visitModule = isPhysioSelected ? 'physiotherapy' : (visit.specialty || config?.specialties[0]?.key || '');
  // A physiotherapy visit speaks of the therapist and bills after the session (the treatments given, or a package).
  const physioVisit = visitModule.toLowerCase().includes('physio');
  const isNoCharge = isPhysioSelected || (config?.check_in.no_charge_modules || []).includes(visitModule.toLowerCase());
  const rawDoctorFee = chosenDoctor?.fee ?? null;
  const fee =
    config?.check_in.payment === 'at_check_in' && !isNoCharge && rawDoctorFee !== null && rawDoctorFee > 0
      ? rawDoctorFee
      : 0;

  // Validation before advancing to billing stage
  const validateDetails = (): boolean => {
    setPatientErrors({});
    setVisitErrors({});
    setSubmitError(null);
    let valid = true;

    if (currentMode === 'new') {
      const errs: Record<string, string> = {};
      if (!formData.first_name.trim()) {
        errs.first_name = 'First name is required.';
        valid = false;
      }
      if (!formData.last_name.trim()) {
        errs.last_name = 'Last name is required.';
        valid = false;
      }
      const digits = formData.phone.replace(/\D/g, '');
      if (digits.length < 10) {
        errs.phone = 'Valid 10-digit mobile number required.';
        valid = false;
      }

      // Age & Date of Birth Validation
      if (formData.dob_unknown) {
        const yStr = formData.age.trim();
        const mStr = (formData.age_months || '').trim();
        if (yStr === '' && mStr === '') {
          errs.age = 'Enter approximate age in years or months.';
          valid = false;
        } else {
          if (yStr !== '') {
            const y = Number(yStr);
            if (!Number.isInteger(y) || y < 0 || y > 120) {
              errs.age = 'Years must be between 0 and 120.';
              valid = false;
            }
          }
          if (mStr !== '') {
            const m = Number(mStr);
            if (!Number.isInteger(m) || m < 0 || m > 11) {
              errs.age_months = 'Months must be between 0 and 11.';
              valid = false;
            }
          }
        }
      } else {
        const dobRequired = config?.fields?.date_of_birth?.state === 'required';
        if (!formData.date_of_birth) {
          if (dobRequired) {
            errs.date_of_birth = 'Date of birth is required, or select Exact DOB Unknown.';
            valid = false;
          }
        } else {
          const todayIso = new Date().toISOString().split('T')[0];
          if (formData.date_of_birth > todayIso) {
            errs.date_of_birth = 'Date of birth cannot be in the future.';
            valid = false;
          } else {
            const calc = calculateDetailedAge(formData.date_of_birth);
            if (!calc) {
              errs.date_of_birth = 'Enter a valid date of birth.';
              valid = false;
            }
          }
        }
      }

      if (duplicateMatches.length > 0 && !duplicateOverride.confirmed) {
        errs.duplicate = 'Acknowledge matching record or use existing patient.';
        valid = false;
      }
      setPatientErrors(errs);
    } else {
      if (!selectedPatient) {
        setSubmitError('Select a returning patient to proceed.');
        valid = false;
      }
    }

    const vErrs: VisitErrors = {};
    if (!visit.visit_reason.trim() && !visit.reason_key) {
      vErrs.visit_reason = 'Select or describe the visit complaint.';
      valid = false;
    }
    // Only require doctor_id in details stage if NOT new case (for New Case, doctor is selected at Billing stage)
    if (currentMode !== 'new' && !visit.doctor_id) {
      vErrs.doctor_id = 'Select an attending doctor.';
      valid = false;
    }
    setVisitErrors(vErrs);

    return valid;
  };

  // Proceed to Billing Stage
  const handleProceedToBilling = () => {
    if (!validateDetails()) return;
    // With only one practitioner for this visit today, they are the choice: nothing to pick.
    const specialty = visit.specialty || config?.specialties?.[0]?.key || '';
    const offered = (config?.doctors || []).filter((doc) => !specialty || doc.contexts.some((ctx) => !ctx.specialty || ctx.specialty === specialty));
    if (!visit.doctor_id && offered.length === 1) {
      setVisit((prev) => ({ ...prev, doctor_id: String(offered[0].id) }));
    }
    setStage('billing');
  };

  // Perform Final Check-In Submission
  const handleSubmit = async (e?: FormEvent) => {
    if (e) e.preventDefault();
    if (!config) {
      setSubmitError('Intake settings are still synchronizing. Please retry in a moment.');
      return;
    }
    if (submitting) return;

    // Doctor selection is required to complete check-in
    if (!visit.doctor_id) {
      setVisitErrors((prev) => ({ ...prev, doctor_id: 'Please select an attending doctor.' }));
      setSubmitError('Please select an attending doctor to complete check-in.');
      return;
    }

    setSubmitting(true);
    setSubmitError(null);
    setPaymentWarning(null);

    // Payment first: the payment travels with the check-in and is taken in the same
    // transaction, so a refused payment checks nobody in. Pay later is the explicit unpaid check-in.
    const settlement: Record<string, unknown> = {};
    if (fee > 0) {
      if (paymentState.mode === 'pay_later' || !config.payment.can_collect) {
        settlement.pay_later = true;
      } else {
        settlement.payment = {
          method: paymentState.method || 'cash',
          amount: paymentState.mode === 'full' ? fee : Number(paymentState.amount) || fee,
          reference: paymentState.reference.trim() || null,
        };
      }
    }

    try {
      let outcome: CheckInResult;

      if (currentMode === 'new') {
        const isDobUnknown = Boolean(formData.dob_unknown);
        const yVal = formData.age.trim();
        const mVal = (formData.age_months || '').trim();

        let ageToSend: number | null = null;
        if (isDobUnknown) {
          if (yVal !== '') {
            ageToSend = Number(yVal);
          } else if (mVal !== '') {
            ageToSend = 0; // infant under 1 year
          }
        }

        let notesToSend = formData.notes.trim() || null;
        if (isDobUnknown && mVal !== '' && Number(mVal) > 0) {
          const ageNote = yVal && Number(yVal) > 0
            ? `Approx. age: ${yVal} yrs ${mVal} mos`
            : `Approx. age: ${mVal} mos`;
          if (!notesToSend) {
            notesToSend = ageNote;
          } else if (!notesToSend.includes(ageNote)) {
            notesToSend = `${notesToSend} (${ageNote})`;
          }
        }

        // 1. New Patient Registration & Check-in
        const payload: Record<string, unknown> = {
          surface: 'new_case',
          first_name: formData.first_name.trim(),
          middle_name: formData.middle_name.trim() || null,
          last_name: formData.last_name.trim(),
          phone: formData.phone.trim(),
          gender: formData.gender,
          date_of_birth: isDobUnknown ? null : formData.date_of_birth || null,
          age: isDobUnknown ? ageToSend : null,
          email: formData.email.trim() || null,
          pincode: formData.pincode.trim() || null,
          locality: formData.locality.trim() || null,
          city: formData.city.trim() || null,
          state: formData.state.trim() || null,
          address: formData.address.trim() || null,
          medical_conditions: formData.conditions,
          notes: notesToSend,
          check_in: true,
          doctor_id: Number(visit.doctor_id),
          visit_reason: visit.visit_reason.trim() || visit.reason_key || 'General Consultation',
          visit_reason_key: visit.reason_key || 'other',
          visit_specialty: visit.specialty || null,
          priority: visit.priority || 'normal',
          case_id: visit.case_id || null,
          new_case: visit.new_case !== false,
          expected_charge: fee,
          ...settlement,
          idempotency_key: idempotencyKey.current,
        };

        if (duplicateMatches.length > 0 && duplicateOverride.confirmed) {
          payload.duplicate_acknowledged_ids = duplicateMatches.map((m) => m.id);
          payload.duplicate_reason = duplicateOverride.reason;
        }

        outcome = await requestJson<CheckInResult>('POST', route('clinic.patients.store'), {
          body: payload,
        });
      } else {
        // 2. Existing Patient Check-in
        const payload = {
          patient_id: selectedPatient!.id,
          case_id: visit.case_id || null,
          new_case: Boolean(visit.new_case),
          practitioner_id: Number(visit.doctor_id),
          visit_reason:
            visit.visit_reason.trim() ||
            visit.reason_key ||
            (isPhysioSelected ? 'Physiotherapy Session' : 'Follow-up Consultation'),
          visit_reason_key: visit.reason_key || 'other',
          visit_specialty: isPhysioSelected ? 'physiotherapy' : (visit.specialty || null),
          priority: visit.priority || 'normal',
          expected_charge: fee,
          ...settlement,
          idempotency_key: idempotencyKey.current,
        };

        outcome = await requestJson<CheckInResult>('POST', route('clinic.reception.check-in'), {
          body: payload,
        });
      }

      setCheckInResult(outcome);
      setStage('confirmed');
      onCheckedIn?.(outcome);

      // Refresh desk queue if on front desk page
      if ('desk' in page.props) {
        router.reload({ only: ['desk'] });
      }
    } catch (err: unknown) {
      if (err instanceof ClinicHttpError) {
        const body = err.body as { message?: string; errors?: Record<string, string[]> } | null;
        if (body?.errors) {
          const firstErr = Object.values(body.errors)[0]?.[0];
          setSubmitError(firstErr || body.message || 'Validation error occurred.');
        } else {
          setSubmitError(body?.message || 'Check-in request failed. Please check inputs.');
        }
      } else {
        setSubmitError('Failed to complete check-in. Please verify network connection.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  // Reset form for next patient
  const handleReset = () => {
    setFormData(EMPTY_PATIENT_FORM);
    setSelectedPatient(null);
    setMultiSpecialtyChoiceNeeded(false);
    setSearchQuery('');
    setSearchResults([]);
    setCheckInResult(null);
    setStage('details');
    setSubmitError(null);
    setPaymentWarning(null);
    idempotencyKey.current = requestKey();
    if (config) {
      const defaultDoc = config.doctors.length > 0 ? String(config.doctors[0].id) : '';
      setVisit(emptyVisit(config, defaultDoc, initialSpecialty || ''));
    }
  };

  // Handle request to close dialog
  const handleRequestClose = () => {
    const isDirty =
      currentMode === 'new'
        ? !!formData.first_name || !!formData.last_name || !!formData.phone
        : !!selectedPatient;

    if (stage !== 'confirmed' && isDirty && !confirmDiscard) {
      setConfirmDiscard(true);
      return;
    }
    setConfirmDiscard(false);
    onOpenChange(false);
  };

  // Check whether we are on the initial repeat search view
  const isRepeatSearchOnly = currentMode === 'repeat' && !selectedPatient;

  // Footer Content for Modal
  const modalFooter = stage === 'confirmed' ? null : stage === 'billing' ? (
    <div className="flex w-full items-center justify-between">
      <button
        type="button"
        disabled={submitting}
        onClick={() => {
          if (currentMode === 'repeat') {
            handleChangePatient();
          } else {
            setStage('details');
          }
        }}
        className="inline-flex items-center gap-1.5 rounded-xl border border-[#DCE6F3] bg-white px-4 py-2 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
      >
        <ArrowLeft className="h-4 w-4" />
        <span>{currentMode === 'repeat' ? 'Change Patient' : 'Back to Details'}</span>
      </button>

      <div className="flex items-center gap-3">
        <span className="hidden sm:inline text-[12.5px] font-semibold text-slate-600">
          {fee > 0
            ? paymentState.mode === 'pay_later'
              ? 'Authorized Pay Later'
              : `Collecting ₹${(paymentState.mode === 'full' ? fee : Number(paymentState.amount) || fee).toLocaleString('en-IN')} (${paymentState.method.toUpperCase()})`
            : physioVisit
              ? 'Billed after the session'
              : 'No Fee Required'}
        </span>
        <button
          type="button"
          disabled={submitting || !visit.doctor_id}
          onClick={() => handleSubmit()}
          className="inline-flex items-center gap-2 rounded-xl bg-[#1769AA] px-5 py-2.5 text-[13.5px] font-semibold text-white shadow-xs hover:bg-[#135A93] transition-colors disabled:opacity-50"
        >
          {submitting ? (
            <span className="flex items-center gap-1.5">
              <span className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
              <span>Processing...</span>
            </span>
          ) : (
            <>
              <CheckCircle2 className="h-4 w-4" />
              <span>{fee > 0 ? `Complete Payment & Check In (₹${fee})` : 'Confirm & Check In Patient'}</span>
            </>
          )}
        </button>
      </div>
    </div>
  ) : isRepeatSearchOnly ? (
    <div className="flex w-full items-center justify-start">
      <button
        type="button"
        disabled={submitting}
        onClick={handleRequestClose}
        className="rounded-xl border border-[#DCE6F3] bg-white px-4 py-2 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
      >
        Cancel
      </button>
    </div>
  ) : currentMode === 'repeat' && selectedPatient ? (
    /* Repeat Case (Physio / Selected Patient) Footer: nothing to check in while today's care is still being chosen */
    <div className="flex w-full items-center justify-between gap-3">
      <button
        type="button"
        disabled={submitting}
        onClick={handleChangePatient}
        className="rounded-xl border border-[#DCE6F3] bg-white px-4 py-2 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
      >
        Change Patient
      </button>

      <div className={cn('flex items-center gap-3', multiSpecialtyChoiceNeeded && 'hidden')}>
        {!visit.doctor_id && (
          <span className="hidden text-[12.5px] font-semibold text-slate-500 sm:inline">
            {activeModuleTab === 'physio' ? 'Choose today’s therapist first.' : 'No doctor available today.'}
          </span>
        )}
        <button
          type="button"
          disabled={submitting || !visit.doctor_id || multiSpecialtyChoiceNeeded}
          onClick={() => handleSubmit()}
          className="inline-flex items-center gap-2 rounded-xl bg-[#1769AA] px-5 py-2.5 text-[13.5px] font-semibold text-white shadow-xs hover:bg-[#135A93] transition-colors disabled:opacity-50"
        >
          {submitting ? (
            <>
              <span className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
              <span>Checking In...</span>
            </>
          ) : (
            <>
              <UserCheck className="h-4 w-4" />
              <span>Confirm & Check In Patient</span>
            </>
          )}
        </button>
      </div>
    </div>
  ) : (
    <div className="flex w-full items-center justify-between">
      <button
        type="button"
        disabled={submitting}
        onClick={handleRequestClose}
        className="rounded-xl border border-[#DCE6F3] bg-white px-4 py-2 text-[13px] font-semibold text-slate-700 hover:bg-slate-50 transition-colors"
      >
        Cancel
      </button>

      <div className="flex items-center gap-3">
        <div className={cn('hidden text-right', !physioVisit && 'sm:block')}>
          <span className="text-[12px] font-semibold text-slate-500 block">
            Consultation Charge:
          </span>
          <span className="text-[13px] font-semibold text-cx-nav-ink">
            {fee > 0
              ? `₹${fee.toLocaleString('en-IN')}`
              : currentMode === 'new' && !chosenDoctor
                ? 'Calculated at check-in'
                : '₹0 at Check-in'}
          </span>
        </div>

        <button
          type="button"
          disabled={submitting}
          onClick={handleProceedToBilling}
          className="inline-flex items-center gap-1.5 rounded-xl bg-[#1769AA] px-5 py-2.5 text-[13.5px] font-semibold text-white shadow-xs hover:bg-[#135A93] transition-colors"
        >
          <span>{physioVisit ? 'Next: therapist & check-in' : 'Proceed to Billing & Check-in'}</span>
          <ArrowRight className="h-4 w-4" />
        </button>
      </div>
    </div>
  );

  return (
    <Modal
      open={open}
      onOpenChange={(next) => {
        if (!next) handleRequestClose();
        else onOpenChange(true);
      }}
      // Repeat Case is one column (find, then confirm): the 880px case width at every step, so it never jumps between
      // steps and its content fills the dialog instead of sitting in a narrow column. New Case keeps its own sizes.
      size={currentMode === 'repeat' ? 'case' : stage === 'billing' ? 'form' : stage === 'confirmed' ? 'form' : 'wide'}
      variant="desk"
      bodyClassName={
        stage !== 'confirmed'
          ? '!bg-none !bg-white px-6 py-6 sm:px-8 sm:py-7'
          : undefined
      }
      title={
        stage === 'confirmed'
          ? currentMode === 'new'
            ? 'Patient Registered & Checked In'
            : 'Patient Checked In'
          : stage === 'billing'
            ? currentMode === 'repeat'
              ? 'Repeat Case · Payment & Check-in'
              : physioVisit
                ? 'Check-in'
                : 'Consultation Fee & Check-In'
            : currentMode === 'new'
              ? physioVisit ? 'Registration' : 'New Case'
              : physioVisit ? 'Repeat' : 'Repeat Case'
      }
      description={
        stage === 'confirmed'
          ? config?.branch.name
          : stage === 'billing'
            ? currentMode === 'repeat'
              ? `Review fee and complete check-in for existing patient${config?.branch.name ? ` · ${config.branch.name}` : ''}`
              : `${physioVisit ? 'Choose the therapist and check the patient in' : 'Review consultation fee and confirm check-in'}${config?.branch.name ? ` · ${config.branch.name}` : ''}`
            : currentMode === 'new'
              ? `Register a new patient and check them in${config?.branch.name ? ` · ${config.branch.name}` : ''}`
              : `${
                  !selectedPatient
                    ? 'Find the patient by name, mobile or UHID'
                    : multiSpecialtyChoiceNeeded
                      ? 'Choose today’s visit'
                      : activeModuleTab === 'physio'
                        ? 'Today’s physiotherapy session'
                        : 'Check in a returning patient'
                }${config?.branch.name ? ` · ${config.branch.name}` : ''}`
      }
      footer={modalFooter}
    >
      {/* Discard Confirmation Banner */}
      {confirmDiscard && (
        <div className="mb-4 bg-amber-50 border border-amber-200 rounded-xl p-3 flex items-center justify-between text-[12.5px] text-amber-900">
          <div className="flex items-center gap-2">
            <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0" />
            <span>Unsaved registration details will be discarded. Close anyway?</span>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setConfirmDiscard(false)}
              className="text-slate-600 hover:text-cx-nav-ink font-semibold text-xs"
            >
              Keep Editing
            </button>
            <button
              type="button"
              onClick={() => {
                setConfirmDiscard(false);
                onOpenChange(false);
              }}
              className="bg-amber-600 text-white rounded-lg px-2.5 py-1 font-semibold text-xs hover:bg-amber-700"
            >
              Discard & Close
            </button>
          </div>
        </div>
      )}

      {/* Configuration Error Notice (Non-blocking) */}
      {failed && !config && (
        <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50/90 p-3 flex items-center justify-between text-[12.5px] text-amber-900">
          <span>Intake configuration could not be loaded. Some clinic defaults may be unavailable.</span>
          <button
            type="button"
            onClick={retry}
            className="rounded-lg bg-amber-600 px-3 py-1 font-semibold text-xs text-white hover:bg-amber-700 transition-colors"
          >
            Retry
          </button>
        </div>
      )}

      {/* STAGE 3: FINAL CONFIRMATION VIEW */}
      {stage === 'confirmed' && checkInResult && (
        <FinalConfirmationView
          result={checkInResult}
          registered={currentMode === 'new'}
          onClose={() => onOpenChange(false)}
          onReset={handleReset}
        />
      )}

      {/* STAGES 1 & 2: INTAKE DETAILS & BILLING CHECK-IN */}
      {stage !== 'confirmed' && (
        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Global Submit Error Banner */}
          {submitError && (
            <div className="rounded-xl border border-rose-200 bg-rose-50/90 p-3.5 flex items-start gap-2.5 text-rose-900 text-[13px]">
              <AlertTriangle className="h-4 w-4 text-rose-600 shrink-0 mt-0.5" />
              <span className="font-medium">{submitError}</span>
            </div>
          )}

          {/* Payment Warning Banner */}
          {paymentWarning && (
            <div className="rounded-xl border border-amber-200 bg-amber-50/90 p-3.5 flex items-start gap-2.5 text-amber-900 text-[13px]">
              <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0 mt-0.5" />
              <span className="font-medium">{paymentWarning}</span>
            </div>
          )}

          {/* STAGE 2: CHECK-IN & BILLING VIEW */}
          {stage === 'billing' && currentMode === 'repeat' && selectedPatient ? (
            /* Repeat Case, Dental: who, the assigned doctor continuing, and the payment; check in from the footer */
            <div className="space-y-5">
              <RepeatPatientBar patient={selectedPatient} onChange={handleChangePatient} disabled={submitting} />
              <RepeatDentalStep
                config={config}
                doctor={chosenDoctor}
                caseTitle={selectedPatient.cases?.find((c) => c.id === visit.case_id)?.title ?? null}
                module={visitModule}
                paymentState={paymentState}
                onPayment={(patch) => setPaymentState((prev) => ({ ...prev, ...patch }))}
                disabled={submitting}
              />
            </div>
          ) : stage === 'billing' ? (
            <div className="space-y-5">
              {/* Patient & Visit Context Header Strip */}
              <div className="rounded-2xl bg-gradient-to-r from-teal-50/70 via-slate-50 to-blue-50/50 px-4 py-3.5 ring-1 ring-inset ring-slate-200/80 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div
                    className={cn(
                      'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl font-semibold text-[14px] border shadow-2xs',
                      currentMode === 'repeat' && selectedPatient
                        ? AVATAR_PASTEL_COLORS[selectedPatient.id % AVATAR_PASTEL_COLORS.length]
                        : 'bg-blue-50 text-blue-700 border-blue-200'
                    )}
                  >
                    {currentMode === 'new'
                      ? `${formData.first_name[0] || 'N'}${formData.last_name[0] || 'P'}`.toUpperCase()
                      : (selectedPatient?.name || 'PT')
                        .split(' ')
                        .map((s) => s[0])
                        .slice(0, 2)
                        .join('')
                        .toUpperCase()}
                  </div>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-cx-nav-ink text-[15px]">
                        {currentMode === 'new'
                          ? `${formData.first_name} ${formData.last_name}`
                          : selectedPatient?.name}
                      </span>
                      {selectedPatient?.uhid ? (
                        <span className="rounded-md bg-teal-50 border border-teal-200 px-2 py-0.5 text-[11px] font-semibold text-teal-800">
                          {selectedPatient.uhid}
                        </span>
                      ) : (
                        <span className="rounded-md bg-blue-50 border border-blue-200 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                          New Registration
                        </span>
                      )}
                    </div>
                    <p className="text-[12px] text-slate-500 mt-0.5">
                      {currentMode === 'new' ? formData.phone : maskPhone(selectedPatient?.phone || null)} ·{' '}
                      {chosenDoctor ? chosenDoctor.name : physioVisit ? 'Choose the therapist below' : 'Select doctor below'} ·{' '}
                      {visit.visit_reason || visit.reason_key || 'Consultation'}
                    </p>
                  </div>
                </div>

                <div className="flex items-center gap-2.5">
                  {currentMode === 'repeat' && (
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedPatient(null);
                        setStage('details');
                      }}
                      className="rounded-xl border border-[#DCE6F3] bg-white px-3 py-1.5 text-[12px] font-semibold text-slate-700 hover:bg-slate-50 transition-colors shadow-2xs"
                    >
                      Change Patient
                    </button>
                  )}
                </div>
              </div>

              {/* Attending Doctor Selection: Dropdown for New Case; Locked Assigned Doctor for Repeat Case */}
              {currentMode === 'new' ? (
                <div className="rounded-2xl border border-cx-line/80 bg-white p-4 sm:p-5 shadow-cx-card space-y-3">
                  <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div className="flex items-center gap-3">
                      <IconChip icon={Stethoscope} tone="blue" size="md" variant="solid" />
                      <div>
                        <h4 className="text-[15px] font-semibold text-cx-nav-ink">
                          {physioVisit ? 'Therapist' : 'Attending Doctor'} <span className="text-rose-500">*</span>
                        </h4>
                        <p className="text-[12px] text-slate-500">
                          {physioVisit ? 'Who gives today’s session' : 'Assign attending practitioner for this visit'}
                        </p>
                      </div>
                    </div>
                    {chosenDoctor && (
                      <span className="text-[11.5px] font-semibold text-teal-700">
                        {chosenDoctor.shift === 'on_shift' ? '● On Shift Now' : 'Scheduled Today'}
                      </span>
                    )}
                  </div>

                  <div>
                    <select
                      disabled={submitting}
                      value={visit.doctor_id}
                      onChange={(e) => {
                        setVisit((prev) => ({ ...prev, doctor_id: e.target.value }));
                        if (visitErrors.doctor_id) {
                          setVisitErrors((prev) => ({ ...prev, doctor_id: undefined }));
                        }
                        if (submitError) {
                          setSubmitError(null);
                        }
                      }}
                      className={cn(
                        'h-10 w-full rounded-xl border bg-white px-3 text-[13px] font-semibold text-cx-nav-ink transition-colors focus:bg-white focus:outline-none focus:ring-2',
                        visitErrors.doctor_id
                          ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
                          : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
                      )}
                    >
                      <option value="" disabled>
                        {physioVisit ? 'Select the therapist…' : 'Select attending doctor for this consultation...'}
                      </option>
                      {(config?.doctors || [])
                        .filter((doc) => {
                          const currentSpecialty = visit.specialty || config?.specialties?.[0]?.key || '';
                          if (!currentSpecialty) return true;
                          return doc.contexts.some((ctx) => !ctx.specialty || ctx.specialty === currentSpecialty);
                        })
                        .map((doc) => {
                          const specName = doc.contexts.map((c) => c.specialty).filter(Boolean).join(', ');
                          const feeText = doc.fee !== null && doc.fee > 0 ? ` · ₹${doc.fee}` : physioVisit ? '' : ' · ₹0';
                          return (
                            <option key={doc.id} value={String(doc.id)}>
                              {doc.name} {specName ? `(${specName})` : ''} {feeText}
                            </option>
                          );
                        })}
                    </select>
                    {visitErrors.doctor_id && (
                      <p className="mt-1.5 text-[11px] font-medium text-rose-600">
                        {visitErrors.doctor_id}
                      </p>
                    )}
                  </div>
                </div>
              ) : (
                /* Dental Repeat Case: Continue with Assigned Doctor (No dropdown, no changing allowed) */
                <div className="rounded-2xl border border-teal-200/80 bg-teal-50/40 p-4 sm:p-5 shadow-xs flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600 text-white font-semibold shadow-xs">
                      <Stethoscope className="h-5 w-5" />
                    </span>
                    <div>
                      <span className="text-[11px] font-semibold text-teal-700 uppercase tracking-wider block">
                        Assigned Doctor · Continuing Ongoing Treatment
                      </span>
                      <h4 className="text-[15px] font-semibold text-cx-nav-ink">
                        Dr. {chosenDoctor?.name || 'Assigned Lead Doctor'}
                      </h4>
                      <p className="text-[11.5px] text-slate-500 mt-0.5">
                        Patient will be queued for their existing assigned doctor. Doctor changes are not permitted in Repeat Case.
                      </p>
                    </div>
                  </div>
                  <span className="rounded-full bg-white border border-teal-200 px-3 py-1 text-[11px] font-semibold text-teal-800 shadow-2xs shrink-0 ml-3">
                    Assigned Doctor
                  </span>
                </div>
              )}

              {/* Billing and Payment Collection Component */}
              <BillingPaymentSummary
                config={config}
                doctor={chosenDoctor}
                module={visitModule}
                paymentState={paymentState}
                onChange={(patch) => setPaymentState((prev) => ({ ...prev, ...patch }))}
                disabled={submitting}
              />
            </div>
          ) : (
            /* STAGE 1: INTAKE DETAILS VIEW (SIDE-BY-SIDE ON DESKTOP, MINIMAL SCROLLING) */
            <div>
              {/* ----------------- FLOW 1: NEW CASE ----------------- */}
              {currentMode === 'new' && (
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:divide-x lg:divide-slate-200/80 items-start">
                  {/* LEFT COLUMN: Patient Details */}
                  <div className="space-y-3.5 lg:pr-8">
                    <div className="flex items-center gap-3 border-b border-slate-100 pb-3">
                      <IconChip icon={UserRound} tone="blue" size="md" variant="solid" />
                      <div>
                        <h3 className="text-[15px] font-semibold text-cx-nav-ink tracking-tight">
                          Patient Details
                        </h3>
                        <p className="text-[12px] text-slate-500">
                          Primary identity and contact information
                        </p>
                      </div>
                    </div>

                    {/* Row 1: First Name | Middle Name | Last Name */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                      <div>
                        <label className="block text-[12px] font-semibold text-slate-700 mb-1.5 leading-none">
                          First Name <span className="text-rose-500">*</span>
                        </label>
                        <input
                          type="text"
                          required
                          disabled={submitting}
                          placeholder="e.g. Rahul"
                          value={formData.first_name}
                          onChange={(e) =>
                            setFormData((prev) => ({ ...prev, first_name: e.target.value }))
                          }
                          className={cn(
                            'h-[50px] w-full rounded-xl border bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] focus:bg-white focus:outline-none focus:ring-2',
                            patientErrors.first_name
                              ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
                              : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
                          )}
                        />
                        {patientErrors.first_name && (
                          <p className="mt-1 text-[11px] font-medium text-rose-600">
                            {patientErrors.first_name}
                          </p>
                        )}
                      </div>

                      <div>
                        <label className="block text-[12px] font-semibold text-slate-700 mb-1.5 leading-none">
                          Middle Name <span className="text-slate-400 font-normal">(Opt)</span>
                        </label>
                        <input
                          type="text"
                          disabled={submitting}
                          placeholder="e.g. Kumar"
                          value={formData.middle_name}
                          onChange={(e) =>
                            setFormData((prev) => ({ ...prev, middle_name: e.target.value }))
                          }
                          className="h-[50px] w-full rounded-xl border border-[#DCE6F3] bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20"
                        />
                      </div>

                      <div>
                        <label className="block text-[12px] font-semibold text-slate-700 mb-1.5 leading-none">
                          Last Name <span className="text-rose-500">*</span>
                        </label>
                        <input
                          type="text"
                          required
                          disabled={submitting}
                          placeholder="e.g. Sharma"
                          value={formData.last_name}
                          onChange={(e) =>
                            setFormData((prev) => ({ ...prev, last_name: e.target.value }))
                          }
                          className={cn(
                            'h-[50px] w-full rounded-xl border bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] focus:bg-white focus:outline-none focus:ring-2',
                            patientErrors.last_name
                              ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
                              : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
                          )}
                        />
                        {patientErrors.last_name && (
                          <p className="mt-1 text-[11px] font-medium text-rose-600">
                            {patientErrors.last_name}
                          </p>
                        )}
                      </div>
                    </div>

                    {/* Row 2: Mobile Number | Gender */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 items-start">
                      {/* Mobile Number with Real-time Duplicate Scan */}
                      <div>
                        <label className="block text-[12px] font-semibold text-slate-700 mb-1.5 leading-none">
                          Mobile Number <span className="text-rose-500">*</span>
                        </label>
                        <div
                          className={cn(
                            'flex h-[50px] w-full items-center rounded-xl border bg-white px-3.5 transition-colors focus-within:bg-white focus-within:ring-2',
                            patientErrors.phone
                              ? 'border-rose-400 focus-within:border-rose-500 focus-within:ring-rose-500/15'
                              : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus-within:border-[#1769AA] focus-within:ring-[#1769AA]/20'
                          )}
                        >
                          <span className="shrink-0 text-[13.5px] font-semibold text-[#173B60] select-none mr-2.5 leading-none">
                            +91
                          </span>
                          <input
                            type="tel"
                            required
                            maxLength={10}
                            disabled={submitting}
                            placeholder="98765 43210"
                            value={formData.phone}
                            onChange={(e) => {
                              const digits = e.target.value.replace(/\D/g, '').slice(0, 10);
                              setFormData((prev) => ({ ...prev, phone: digits }));
                            }}
                            className="h-full w-full min-w-0 flex-1 border-0 bg-transparent p-0 text-[13.5px] font-semibold text-[#173B60] placeholder:text-[#64748B] focus:outline-none focus:ring-0 leading-none"
                          />
                        </div>
                        {patientErrors.phone && (
                          <p className="mt-1 text-[11px] font-medium text-rose-600">
                            {patientErrors.phone}
                          </p>
                        )}
                      </div>

                      {/* Gender Selector */}
                      <div>
                        <label className="block text-[12px] font-semibold text-slate-700 mb-1.5 leading-none">
                          Gender <span className="text-rose-500">*</span>
                        </label>
                        {/* The Owner Dashboard's pill switch: white, a hairline, the choice in teal. */}
                        <div className="grid h-[50px] grid-cols-3 gap-1 rounded-full bg-white p-1 shadow-[inset_0_0_0_1px_rgba(213,219,230,0.9)]">
                          {['male', 'female', 'other'].map((g) => (
                            <button
                              key={g}
                              type="button"
                              disabled={submitting}
                              onClick={() => setFormData((prev) => ({ ...prev, gender: g }))}
                              className={cn(
                                'flex h-full w-full items-center justify-center rounded-full text-[13px] font-semibold capitalize motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/30',
                                formData.gender === g
                                  ? 'bg-cx-teal-bg text-cx-teal-ink'
                                  : 'text-cx-text-2 hover:text-cx-nav-ink'
                              )}
                            >
                              {g}
                            </button>
                          ))}
                        </div>
                      </div>
                    </div>

                    {/* Row 3: Date of Birth (~67%) | Age (~33%) */}
                    <div className="grid grid-cols-12 gap-3 items-start">
                      {/* Left: Date of Birth (approx 65-70% width) */}
                      <div className="col-span-8">
                        <div className="flex items-center justify-between mb-1.5 leading-none">
                          <label className="text-[12px] font-semibold text-slate-700 leading-none">
                            Date of Birth
                            {config?.fields?.date_of_birth?.state === 'required' && !formData.dob_unknown && (
                              <span className="text-rose-500 ml-0.5">*</span>
                            )}
                          </label>
                        </div>
                        {formData.dob_unknown ? (
                          <div className="flex h-[50px] w-full items-center rounded-xl border border-[#DCE6F3] bg-slate-100/70 px-3.5 text-[13px] font-medium text-slate-400 select-none">
                            <span>Not known (Approximate age)</span>
                          </div>
                        ) : (
                          <div>
                            <input
                              type="date"
                              disabled={submitting}
                              max={new Date().toISOString().split('T')[0]}
                              value={formData.date_of_birth}
                              onChange={(e) => {
                                const val = e.target.value;
                                setFormData((prev) => ({ ...prev, date_of_birth: val }));
                                if (patientErrors.date_of_birth) {
                                  setPatientErrors((prev) => {
                                    const next = { ...prev };
                                    delete next.date_of_birth;
                                    return next;
                                  });
                                }
                              }}
                              className={cn(
                                'h-[50px] w-full rounded-xl border bg-white px-3.5 text-[13.5px] font-semibold text-[#173B60] transition-colors hover:border-[#B9C6D8] focus:bg-white focus:outline-none focus:ring-2',
                                patientErrors.date_of_birth
                                  ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
                                  : 'border-[#DCE6F3] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
                              )}
                            />
                            {patientErrors.date_of_birth && (
                              <p className="mt-1 text-[11px] font-medium text-rose-600">
                                {patientErrors.date_of_birth}
                              </p>
                            )}
                          </div>
                        )}
                      </div>

                      {/* Right: Age (approx 30-35% width, compact numeric field) */}
                      <div className="col-span-4">
                        <div className="mb-1.5 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 leading-none">
                          <label className="text-[12px] font-semibold text-slate-700 leading-none">
                            {formData.dob_unknown ? 'Approx. Age' : 'Age'}
                            {formData.dob_unknown && <span className="text-rose-500 ml-0.5">*</span>}
                          </label>
                          <button
                            type="button"
                            onClick={() =>
                              setFormData((prev) => ({
                                ...prev,
                                dob_unknown: !prev.dob_unknown,
                                age: '',
                                age_months: '',
                                date_of_birth: '',
                              }))
                            }
                            className="text-[11px] font-semibold text-blue-600 hover:underline leading-none shrink-0"
                          >
                            {formData.dob_unknown ? 'Know DOB?' : 'Exact DOB Unknown?'}
                          </button>
                        </div>

                        {formData.dob_unknown ? (
                          <div>
                            <div
                              className={cn(
                                'flex h-[50px] w-full items-center rounded-xl border bg-white px-3.5 transition-colors focus-within:bg-white focus-within:ring-2',
                                patientErrors.age
                                  ? 'border-rose-400 focus-within:border-rose-500 focus-within:ring-rose-500/15'
                                  : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus-within:border-[#1769AA] focus-within:ring-[#1769AA]/20'
                              )}
                            >
                                <input
                                  type="number"
                                  min={0}
                                  max={120}
                                  disabled={submitting}
                                  placeholder="0"
                                  value={formData.age}
                                  onChange={(e) => {
                                    const val = e.target.value.replace(/\D/g, '').slice(0, 3);
                                    setFormData((prev) => ({ ...prev, age: val }));
                                    if (patientErrors.age) {
                                      setPatientErrors((prev) => {
                                        const next = { ...prev };
                                        delete next.age;
                                        return next;
                                      });
                                    }
                                  }}
                                  className="h-full w-full min-w-0 flex-1 border-0 bg-transparent p-0 text-[13.5px] font-semibold text-[#173B60] placeholder:text-[#64748B] focus:outline-none focus:ring-0 leading-none"
                                />
                                <span className="shrink-0 text-[12px] font-medium text-slate-400 select-none ml-1.5 leading-none">
                                  Yrs
                                </span>
                              </div>
                              {patientErrors.age && (
                                <p className="mt-1 text-[11px] font-medium text-rose-600">
                                  {patientErrors.age}
                                </p>
                              )}
                              {(formData.age === '0' || formData.age_months !== '') && (
                                <div className="mt-1.5 flex items-center justify-between text-[11px] font-medium text-slate-600 px-0.5">
                                  <span>Months (0-11):</span>
                                  <input
                                    type="number"
                                    min={0}
                                    max={11}
                                    placeholder="0"
                                    value={formData.age_months}
                                    onChange={(e) => {
                                      const val = e.target.value.replace(/\D/g, '').slice(0, 2);
                                      setFormData((prev) => ({ ...prev, age_months: val }));
                                    }}
                                    className="w-12 h-6 rounded border border-[#DCE6F3] bg-white px-1 text-[11px] font-semibold text-[#173B60] text-center"
                                  />
                                </div>
                              )}
                            </div>
                        ) : (
                          <div>
                            <div className="flex h-[50px] w-full items-center rounded-xl border border-[#DCE6F3] bg-white px-3.5 select-none transition-colors">
                              <input
                                type="text"
                                readOnly
                                tabIndex={-1}
                                placeholder=""
                                value={calculatedAge !== null ? String(calculatedAge.years) : ''}
                                className="h-full w-full min-w-0 flex-1 border-0 bg-transparent p-0 text-[13.5px] font-semibold text-[#173B60] placeholder:text-[#64748B] focus:outline-none focus:ring-0 leading-none cursor-default"
                              />
                              {calculatedAge !== null && (
                                <span className="shrink-0 text-[12px] font-semibold text-slate-400 select-none ml-1 leading-none">
                                  Yrs
                                </span>
                              )}
                            </div>
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Row 4: Email Address (Optional) */}
                    <div>
                      <label className="block text-[12px] font-semibold text-slate-700 mb-1.5 leading-none">
                        Email Address <span className="text-slate-400 font-normal">(Optional)</span>
                      </label>
                      <input
                        type="email"
                        disabled={submitting}
                        placeholder="patient@example.com"
                        value={formData.email}
                        onChange={(e) =>
                          setFormData((prev) => ({ ...prev, email: e.target.value }))
                        }
                        className="h-[50px] w-full rounded-xl border border-[#DCE6F3] bg-white px-3.5 text-[13.5px] text-[#173B60] hover:border-[#B9C6D8] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20"
                      />
                    </div>

                    {/* Duplicate Patient Alert Banner */}
                    {duplicateMatches.length > 0 && (
                      <div className="rounded-xl border border-amber-200 bg-amber-50/90 p-3 space-y-2.5">
                        <div className="flex items-center gap-2 text-amber-900 font-semibold text-[12px]">
                          <AlertTriangle className="h-4 w-4 text-amber-600 shrink-0" />
                          <span>
                            {duplicateMatches.length} existing patient record(s) match this mobile:
                          </span>
                        </div>

                        <div className="space-y-1.5">
                          {duplicateMatches.map((match) => (
                            <div
                              key={match.id}
                              className="flex items-center justify-between rounded-lg border border-amber-200/80 bg-white p-2.5 shadow-2xs text-[12px]"
                            >
                              <div>
                                <span className="font-semibold text-cx-nav-ink">{match.name}</span>
                                {match.uhid && (
                                  <span className="ml-1.5 rounded bg-teal-50 border border-teal-200 px-1.5 py-0.2 text-[10px] font-semibold text-teal-800">
                                    {match.uhid}
                                  </span>
                                )}
                                <p className="text-slate-500 text-[10.5px]">
                                  {match.phone_hint || ''} · {match.gender || ''}
                                </p>
                              </div>

                              <button
                                type="button"
                                onClick={() => {
                                  setCurrentMode('repeat');
                                  handleSelectPatient({
                                    id: match.id,
                                    name: match.name,
                                    short_name: match.name,
                                    phone: formData.phone,
                                    uhid: match.uhid || null,
                                    age: match.age || null,
                                    gender: match.gender || null,
                                    status: 'active',
                                    specialties: [],
                                    cases: [],
                                    last_seen: null,
                                    safety_flags: [],
                                    address: null,
                                    appointment: null,
                                    in_queue: null,
                                    assigned_doctor_id: null,
                                  });
                                }}
                                className="rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1 text-[11px] font-semibold text-blue-700 hover:bg-blue-600 hover:text-white transition-colors"
                              >
                                Use Patient →
                              </button>
                            </div>
                          ))}
                        </div>

                        {/* Audited Override Checkbox */}
                        <div className="space-y-1.5 pt-1.5 border-t border-amber-200/70">
                          <label className="flex items-center gap-2 text-[11.5px] font-semibold text-amber-900 cursor-pointer">
                            <input
                              type="checkbox"
                              checked={duplicateOverride.confirmed}
                              onChange={(e) =>
                                setDuplicateOverride((prev) => ({
                                  ...prev,
                                  confirmed: e.target.checked,
                                }))
                              }
                              className="rounded border-amber-300 text-blue-600 focus:ring-[#1769AA] h-3.5 w-3.5"
                            />
                            <span>Confirm this is a different individual (Audited Override)</span>
                          </label>

                          {duplicateOverride.confirmed && (
                            <select
                              value={duplicateOverride.reason}
                              onChange={(e) =>
                                setDuplicateOverride((prev) => ({
                                  ...prev,
                                  reason: e.target.value,
                                }))
                              }
                              className="h-8 w-full rounded-lg border border-amber-200 bg-white px-2.5 text-[11.5px] font-medium text-slate-800 focus:outline-none focus:ring-2 focus:ring-amber-500/20"
                            >
                              <option value="Family member sharing phone">
                                Family member sharing phone
                              </option>
                              <option value="Different individual with verified identity">
                                Different individual with verified identity
                              </option>
                              <option value="Phone number reassigned to new owner">
                                Phone number reassigned to new owner
                              </option>
                            </select>
                          )}
                        </div>
                      </div>
                    )}

                    {/* Address Details (Integrated directly within Patient Details) */}
                    <AddressLookupSection
                      data={formData}
                      onChange={(patch) => setFormData((prev) => ({ ...prev, ...patch }))}
                      disabled={submitting}
                    />
                  </div>

                  {/* RIGHT COLUMN: Medical History & Visit Complaint */}
                  <div className="space-y-4 lg:pl-8">
                    {/* Medical History Section */}
                    <MedicalHistorySection
                      data={formData}
                      onChange={(patch) => setFormData((prev) => ({ ...prev, ...patch }))}
                      disabled={submitting}
                    />

                    {/* Visit Complaint / Reason */}
                    <VisitComplaintSection
                      config={config}
                      value={visit}
                      onChange={(patch) => setVisit((prev) => ({ ...prev, ...patch }))}
                      errors={visitErrors}
                      disabled={submitting}
                      hideDoctor={true}
                      hidePriority={true}
                    />
                  </div>
                </div>
              )}

              {/* ----------------- FLOW 2: REPEAT CASE ----------------- */}
              {/* Search first; once chosen, the patient once at the top, then today's care: which visit (combined
                  clinic, under both), the physiotherapy session, or — rarely, before billing — the dental visit. */}
              {currentMode === 'repeat' &&
                (!selectedPatient ? (
                  <RepeatPatientSearch
                    query={searchQuery}
                    onQueryChange={setSearchQuery}
                    results={searchResults}
                    recent={recentPatients}
                    searching={isSearching}
                    onSelect={handleSelectPatient}
                    onRegisterNew={config?.can.register === false ? null : handleRegisterFromSearch}
                  />
                ) : (
                  <div className="space-y-5">
                    <RepeatPatientBar patient={selectedPatient} onChange={handleChangePatient} disabled={submitting} />
                    {multiSpecialtyChoiceNeeded ? (
                      <RepeatCareChoice onChoose={handleChooseCareStream} />
                    ) : activeModuleTab === 'physio' ? (
                      <RepeatPhysioStep
                        config={config}
                        care={physioSummary}
                        treatmentId={activePhysioTreatment?.id ?? null}
                        onTreatment={setPhysioTreatmentId}
                        loadingCare={loadingPhysio}
                        therapistId={visit.doctor_id}
                        onTherapist={(id) => {
                          setVisit((prev) => ({ ...prev, doctor_id: id }));
                          setVisitErrors((prev) => ({ ...prev, doctor_id: undefined }));
                          setSubmitError(null);
                        }}
                        focus={visit.visit_reason}
                        onFocus={(value) => setVisit((prev) => ({ ...prev, visit_reason: value }))}
                        error={visitErrors.doctor_id}
                        disabled={submitting}
                      />
                    ) : (
                      <RepeatDentalStep
                        config={config}
                        doctor={chosenDoctor}
                        caseTitle={selectedPatient.cases?.find((c) => c.id === visit.case_id)?.title ?? null}
                        module={visitModule}
                        paymentState={paymentState}
                        onPayment={(patch) => setPaymentState((prev) => ({ ...prev, ...patch }))}
                        disabled={submitting}
                      />
                    )}
                  </div>
                ))}
            </div>
          )}
        </form>
      )}
    </Modal>
  );
}
