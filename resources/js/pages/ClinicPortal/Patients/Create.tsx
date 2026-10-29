import { useCallback, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, ShieldAlert, Stethoscope, UserPlus, UserRound } from 'lucide-react';
import { ChoiceChips, duplicateOverrideProblem, Field, humanize, Notice, Select } from '@/clinic';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { useIntake } from '@/clinic/shell/IntakeContext';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { Card } from '@/clinic/ui/Card';
import { PageHeader } from '@/clinic/ui/PageHeader';
import {
  DuplicateSuggestions,
  EMPTY_PATIENT,
  forModule,
  MedicalSafetyFields,
  MoreDetailsFields,
  moreFields,
  NO_OVERRIDE,
  overridePayload,
  PatientIdentityFields,
  patientPayload,
  requestKey,
  required,
  shown,
  showsSafety,
  useDuplicateSuggestions,
  validatePatient,
  type DuplicateOverrideState,
  type PatientErrors,
  type PatientForm,
  type RegistrationConfig,
} from '@/pages/Clinic/Reception/intake/PatientFields';
import type { DuplicateMatch, PractitionerOption, SpecialtyOption } from './registration/types';

interface CreateProps {
  practitioners: PractitionerOption[];
  specialties: SpecialtyOption[];
  branch: { id: number; name: string } | null;
  /** The shared registration fields' configuration (`PatientController::registrationForm`). */
  form: RegistrationConfig | null;
  links: { index: string };
}

/**
 * Patients → Add patient: the full registration form, for a clinic admin or
 * anyone registering away from the front desk (the desk itself uses the New
 * Case popup). The same fields, rules and duplicate review as New Case
 * (`PatientFields`), laid out on a page; no visit is created here.
 *
 * Saving is an ordinary Inertia form post with a request key made when the
 * page opened, so a double click or a resubmitted form registers once
 * (`IntakeSubmissions::onceRedirect`). Leaving with unsaved work asks first.
 */
export default function PatientsCreate(props: CreateProps) {
  return (
    <ClinicPortalShell title="Add patient">
      <Registration {...props} />
    </ClinicPortalShell>
  );
}

/** The server's field names that are not the form's own. */
function patientErrorsFrom(errors: Record<string, string>): PatientErrors {
  return { ...errors, conditions: errors.medical_conditions ?? errors['medical_conditions.0'], allergies: errors.allergies ?? errors['allergies.0'] } as PatientErrors;
}

function Registration({ practitioners, specialties, branch, form, links }: CreateProps) {
  const page = usePage<{ flash?: { possible_duplicates?: DuplicateMatch[] | null } }>();
  const intake = useIntake();
  const [data, setData] = useState<PatientForm>(EMPTY_PATIENT);
  const [specialty, setSpecialty] = useState(specialties.length === 1 ? specialties[0].key : '');
  const [doctorId, setDoctorId] = useState('');
  const [doctorError, setDoctorError] = useState<string | null>(null);
  const [errors, setErrors] = useState<PatientErrors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [override, setOverride] = useState<DuplicateOverrideState>(NO_OVERRIDE);
  const [saving, setSaving] = useState(false);
  const key = useRef(requestKey());
  const leaving = useRef(false);

  const [matches, setMatches] = useDuplicateSuggestions(data, form !== null);

  // The server's own duplicate check, when it stopped a save the live check had not.
  const flashed = page.props.flash?.possible_duplicates;
  useEffect(() => {
    if (flashed && flashed.length > 0) {
      setMatches(flashed);
    }
  }, [flashed, setMatches]);
  useEffect(() => {
    if (matches.length === 0) {
      setOverride(NO_OVERRIDE);
    }
  }, [matches.length]);

  const dirty = JSON.stringify(data) !== JSON.stringify(EMPTY_PATIENT);

  // Leaving with unsaved work asks first: another page in the portal, or closing the tab.
  useEffect(() => {
    if (!dirty) {
      return;
    }
    const removeBefore = router.on('before', (event) => {
      if (leaving.current || event.detail.visit.method !== 'get') {
        return;
      }
      if (!window.confirm('Leave without registering this patient? What you entered will be lost.')) {
        event.preventDefault();
      }
    });
    const onUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
    };
    window.addEventListener('beforeunload', onUnload);

    return () => {
      removeBefore();
      window.removeEventListener('beforeunload', onUnload);
    };
  }, [dirty]);

  const setField = useCallback(<K extends keyof PatientForm>(field: K, value: PatientForm[K]) => {
    setData((current) => ({ ...current, [field]: value }));
    setErrors((current) => (field in current || current.form ? { ...current, [field]: undefined, form: undefined } : current));
  }, []);

  if (!form) {
    return <Notice tone="warning">Choose a clinic before registering a patient.</Notice>;
  }

  const specialtyLabel = (value: string) => specialties.find((option) => option.key === value)?.label ?? humanize(value);
  const doctors = practitioners.filter((doctor) => !specialty || !doctor.specialty || doctor.specialty === specialty);

  const reveal = () =>
    window.requestAnimationFrame(() => {
      const target = document.querySelector<HTMLElement>('main [aria-invalid="true"], main [role=alert], main [id$="-duplicate-heading"]');
      target?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (target?.matches('input, select, textarea')) {
        target.focus();
      }
    });

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (saving) {
      return;
    }
    setFormError(null);
    // The settings as they apply to the chosen module (the server applies the same).
    const found = validatePatient(forModule(form, specialty), data);
    const doctorProblem = shown(form, 'doctor_id') && required(form, 'doctor_id') && doctorId === '' ? 'Choose the assigned doctor.' : null;
    setDoctorError(doctorProblem);
    if (matches.length > 0) {
      if (!override.open) {
        found.form = 'A possible existing patient was found. Check the matches, or confirm this is a different person.';
      } else {
        const problem = duplicateOverrideProblem(override.confirmed, override.reason);
        if (problem) {
          found.duplicate_reason = problem;
        }
      }
    }
    setErrors(found);
    if (Object.values(found).some(Boolean) || doctorProblem) {
      reveal();

      return;
    }

    router.post(
      route('clinic.patients.store'),
      {
        surface: 'full_page',
        idempotency_key: key.current,
        ...patientPayload(forModule(form, specialty), data),
        specialties: specialty ? [specialty] : [],
        doctor_id: shown(form, 'doctor_id') && doctorId ? Number(doctorId) : null,
        ...overridePayload(matches, override),
      } as never,
      {
        preserveScroll: true,
        onStart: () => {
          setSaving(true);
          leaving.current = true;
        },
        onFinish: () => {
          setSaving(false);
          leaving.current = false;
        },
        onError: (serverErrors) => {
          const fieldErrors = serverErrors as Record<string, string>;
          setErrors({ ...patientErrorsFrom(fieldErrors), form: fieldErrors.duplicate });
          setDoctorError(fieldErrors.doctor_id ?? null);
          setFormError(fieldErrors.duplicate ? null : (fieldErrors.idempotency_key ?? fieldErrors.specialties ?? 'Some details need attention.'));
          reveal();
        },
        onSuccess: () => {
          // The server redirected away (the directory, with its notice); a new visit to this page gets a new key.
          key.current = requestKey();
        },
        onNetworkError: () => {
          setFormError('The patient was not saved. Check the connection and try again; nothing you entered was lost.');

          return false;
        },
        onHttpException: () => {
          setFormError('The patient was not saved. Try again; nothing you entered was lost.');

          return false;
        },
      },
    );
  };

  return (
    <>
      <PageHeader
        eyebrow={<span className="text-[11px] font-bold uppercase tracking-[0.08em] text-cx-nav-teal-strong">Patients</span>}
        title="Add patient"
        description={`Register a patient${branch ? ` at ${branch.name}` : ''}. The patient number is assigned on saving.`}
        actions={
          <ButtonLink href={links.index} tone="ghost" icon={ArrowLeft}>
            Patient directory
          </ButtonLink>
        }
      />

      <Card as="div" className="overflow-hidden">
        <form noValidate onSubmit={submit}>
          {formError && (
            <div className="px-5 pt-5 sm:px-6">
              <Notice tone="danger">{formError}</Notice>
            </div>
          )}
          <div className="grid grid-cols-1 gap-8 px-5 py-5 sm:px-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] lg:gap-0 lg:divide-x lg:divide-cx-line">
            <Section title="Patient" icon={UserRound} className="lg:pr-8">
              <div className="space-y-4">
                <PatientIdentityFields config={forModule(form, specialty)} data={data} setField={setField} errors={errors} idPrefix="pr" autoFocus />
                {errors.form && (
                  <p role="alert" className="text-[12.5px] font-semibold text-amber-900">
                    {errors.form}
                  </p>
                )}
                <DuplicateSuggestions
                  matches={matches}
                  specialtyLabel={specialtyLabel}
                  // At the front desk, a returning patient is checked in with Repeat Case instead; nothing is registered.
                  onUse={intake.available ? (match) => intake.open('repeat', { id: match.id, uhid: match.uhid, name: match.name }) : undefined}
                  useLabel="Check in instead"
                  override={override}
                  onOverrideChange={(patch) => {
                    setOverride((current) => ({ ...current, ...patch }));
                    setErrors((current) => ({ ...current, duplicate_reason: undefined, form: undefined }));
                  }}
                  reasonError={errors.duplicate_reason}
                  idPrefix="pr"
                />
                {moreFields(forModule(form, specialty)).length > 0 && (
                  <div className="space-y-3 border-t border-cx-line pt-4">
                    <p className="text-[12px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">Contact &amp; details</p>
                    <MoreDetailsFields config={forModule(form, specialty)} data={data} setField={setField} errors={errors} idPrefix="pr" />
                  </div>
                )}
              </div>
            </Section>

            <Section title="Care & safety" icon={Stethoscope} className="lg:pl-8">
              <div className="space-y-5">
                {specialties.length > 1 && (
                  <div>
                    <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Care</p>
                    <ChoiceChips label="Care" id="pr-specialty" options={specialties.map((option) => ({ value: option.key, label: option.label }))} value={specialty} onChange={setSpecialty} />
                    <p className="mt-1.5 text-[12px] text-clinic-secondary">Narrows the medical history and allergies offered. Optional.</p>
                  </div>
                )}
                {shown(form, 'doctor_id') && doctors.length > 0 && (
                  <Field label="Assigned doctor" htmlFor="pr-doctor" required={required(form, 'doctor_id')} error={doctorError ?? undefined}>
                    <Select
                      id="pr-doctor"
                      value={doctorId}
                      onChange={(event) => {
                        setDoctorId(event.target.value);
                        setDoctorError(null);
                      }}
                      options={doctors.map((doctor) => ({ value: String(doctor.id), label: doctor.name }))}
                      placeholder="Not assigned"
                      invalid={!!doctorError}
                    />
                  </Field>
                )}
                {showsSafety(forModule(form, specialty)) ? (
                  <div className="space-y-3">
                    <p className="flex items-center gap-1.5 text-[12px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">
                      <ShieldAlert className="h-4 w-4" aria-hidden="true" />
                      Medical safety
                    </p>
                    <MedicalSafetyFields config={forModule(form, specialty)} data={data} setField={setField} errors={errors} specialty={specialty} area={null} generalOnly={specialties.length > 1 && specialty === ''} />
                  </div>
                ) : (
                  <p className="rounded-xl bg-clinic-inset/60 px-3 py-2.5 text-[12.5px] text-clinic-secondary">Medical history and allergies are recorded by clinical staff.</p>
                )}
              </div>
            </Section>
          </div>

          <div className="flex flex-wrap items-center justify-end gap-2 border-t border-cx-line px-5 py-4 sm:px-6">
            <ButtonLink href={links.index} tone="ghost" className="mr-auto">
              Cancel
            </ButtonLink>
            <Button type="submit" tone="primary" icon={UserPlus} loading={saving} loadingLabel="Registering…" disabled={saving}>
              Register patient
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}

function Section({ title, icon: Icon, className, children }: { title: string; icon: typeof UserRound; className?: string; children: ReactNode }) {
  const id = `register-${title.replace(/\W+/g, '-').toLowerCase()}`;

  return (
    <section aria-labelledby={id} className={className}>
      <h2 id={id} className="mb-4 flex items-center gap-2 text-[13px] font-bold uppercase tracking-[0.06em] text-cx-nav-teal-strong">
        <span className="grid h-5 w-5 place-items-center rounded-md bg-gradient-to-br from-[#E3F6F2] to-[#E6F0FB] text-[#0F7C6E]" aria-hidden="true">
          <Icon className="h-3.5 w-3.5" />
        </span>
        {title}
      </h2>
      {children}
    </section>
  );
}
