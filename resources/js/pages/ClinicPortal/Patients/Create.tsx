import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ArrowLeft, UserPlus } from 'lucide-react';
import { ClinicPortalShell } from '@/clinic/shell/ClinicPortalShell';
import { Button, ButtonLink } from '@/clinic/ui/Button';
import { Notice } from '@/clinic/ui/Notice';
import { PageHeader } from '@/clinic/ui/PageHeader';
import {
  requestKey,
  validPhone,
  useDuplicateSuggestions,
} from '@/pages/Clinic/Reception/intake/PatientFields';
import type { DuplicateMatch, PractitionerOption, SpecialtyOption } from './registration/types';
import { PersonalInfoSection, type PersonalInfoData } from './components/form/PersonalInfoSection';
import { ContactInfoSection, type ContactInfoData } from './components/form/ContactInfoSection';
import { MedicalHistorySection, type MedicalHistoryData, type MedicalChoice } from './components/form/MedicalHistorySection';
import { ModuleSpecificSection, type ModuleSpecificData } from './components/form/ModuleSpecificSection';
import { DocumentsSection, type PendingDocument } from './components/form/DocumentsSection';
import { RegistrationSummaryPanel } from './components/form/RegistrationSummaryPanel';

interface RegistrationConfig {
  fields: Record<string, { state: 'required' | 'optional' | 'hidden'; order?: number; label?: string; help?: string }>;
  catalogue: {
    conditions: { key: string; name: string; category?: string | null }[];
    allergies: { key: string; name: string }[];
    medications: { key: string; name: string }[];
    alerts?: { key: string; name: string }[];
  };
  quick_care: { allergy_status: string };
  can: { record_clinical: boolean };
}

interface CreateProps {
  practitioners: PractitionerOption[];
  specialties: SpecialtyOption[];
  branch: { id: number; name: string } | null;
  form: RegistrationConfig | null;
  links: { index: string };
}

export default function PatientsCreate(props: CreateProps) {
  return (
    <ClinicPortalShell title="Add Patient">
      <RegistrationForm {...props} />
    </ClinicPortalShell>
  );
}

function RegistrationForm({ practitioners, specialties, branch, form, links }: CreateProps) {
  const page = usePage<{ flash?: { possible_duplicates?: DuplicateMatch[] | null } }>();

  // 1. Personal Information State
  const [personal, setPersonal] = useState<PersonalInfoData>({
    first_name: '',
    middle_name: '',
    last_name: '',
    gender: 'female',
    date_of_birth: '',
    dob_unknown: false,
    age: '',
    blood_group: '',
  });

  // 2. Contact Information State
  const [contact, setContact] = useState<ContactInfoData>({
    phone: '',
    email: '',
    address: '',
    city: '',
    state: '',
    pincode: '',
  });

  // 3. Medical History State
  const [medical, setMedical] = useState<MedicalHistoryData>({
    conditions: [],
    allergies: [],
    allergy_none: false,
    medications: [],
    medical_notes: '',
  });

  // 4. Module-Specific State
  const [moduleData, setModuleData] = useState<ModuleSpecificData>({
    dental_history: '',
    dental_treatments: [],
    physio_pain_area: '',
    physio_pain_duration: '',
    physio_mobility_limitation: '',
    physio_notes: '',
  });

  // 5. Document Attachments
  const [pendingDocs, setPendingDocs] = useState<PendingDocument[]>([]);

  // Duplicate Override State
  const [overrideConfirmed, setOverrideConfirmed] = useState(false);
  const [overrideReason, setOverrideReason] = useState('');
  const [overrideReasonError, setOverrideReasonError] = useState<string | undefined>();

  // Validation & Form Error States
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const key = useRef(requestKey());
  const leaving = useRef(false);

  // Debounced duplicate detection
  const duplicateInput = {
    phone: contact.phone,
    first_name: personal.first_name,
    last_name: personal.last_name,
    date_of_birth: personal.dob_unknown ? '' : personal.date_of_birth,
    dob_unknown: personal.dob_unknown,
  };
  const [matches, setMatches] = useDuplicateSuggestions(duplicateInput as any, true);

  // Server-flashed duplicate override
  const flashed = page.props.flash?.possible_duplicates;
  useEffect(() => {
    if (flashed && flashed.length > 0) {
      setMatches(flashed);
    }
  }, [flashed, setMatches]);

  useEffect(() => {
    if (matches.length === 0) {
      setOverrideConfirmed(false);
      setOverrideReason('');
      setOverrideReasonError(undefined);
    }
  }, [matches.length]);

  // Unsaved changes prompt
  const isDirty = Boolean(
    personal.first_name ||
      personal.last_name ||
      contact.phone ||
      contact.email ||
      contact.address ||
      medical.conditions.length > 0 ||
      pendingDocs.length > 0,
  );

  useEffect(() => {
    if (!isDirty) return;

    const removeBefore = router.on('before', (event) => {
      if (leaving.current || event.detail.visit.method !== 'get') return;
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
  }, [isDirty]);

  const setPersonalField = useCallback(
    <K extends keyof PersonalInfoData>(field: K, value: PersonalInfoData[K]) => {
      setPersonal((prev) => ({ ...prev, [field]: value }));
      setErrors((prev) => ({ ...prev, [field]: '' }));
    },
    [],
  );

  const setContactField = useCallback(
    <K extends keyof ContactInfoData>(field: K, value: ContactInfoData[K]) => {
      setContact((prev) => ({ ...prev, [field]: value }));
      setErrors((prev) => ({ ...prev, [field]: '' }));
    },
    [],
  );

  const setMedicalField = useCallback(
    <K extends keyof MedicalHistoryData>(field: K, value: MedicalHistoryData[K]) => {
      setMedical((prev) => ({ ...prev, [field]: value }));
      setErrors((prev) => ({ ...prev, [field]: '' }));
    },
    [],
  );

  const setModuleField = useCallback(
    <K extends keyof ModuleSpecificData>(field: K, value: ModuleSpecificData[K]) => {
      setModuleData((prev) => ({ ...prev, [field]: value }));
      setErrors((prev) => ({ ...prev, [field]: '' }));
    },
    [],
  );

  const handleAddPendingDocs = (docs: PendingDocument[]) => {
    setPendingDocs((prev) => [...prev, ...docs]);
  };

  const handleRemovePendingDoc = (id: string) => {
    setPendingDocs((prev) => prev.filter((d) => d.id !== id));
  };

  // Scroll to first error
  const scrollToError = () => {
    window.requestAnimationFrame(() => {
      const target = document.querySelector<HTMLElement>('main [aria-invalid="true"], main [role=alert]');
      target?.scrollIntoView({ block: 'center', behavior: 'smooth' });
      if (target?.matches('input, select, textarea')) {
        target.focus();
      }
    });
  };

  const handleSubmit = (e?: FormEvent) => {
    if (e) e.preventDefault();
    if (saving) return;

    setFormError(null);
    setOverrideReasonError(undefined);
    const newErrors: Record<string, string> = {};

    // Validate Personal Info
    if (!personal.first_name.trim()) {
      newErrors.first_name = 'First name is required.';
    }
    const lastNameSetting = form?.fields?.last_name?.state;
    if (lastNameSetting === 'required' && !personal.last_name.trim()) {
      newErrors.last_name = 'Last name is required.';
    }
    if (!personal.gender) {
      newErrors.gender = 'Choose a gender.';
    }
    if (personal.dob_unknown) {
      const parsedAge = Number(personal.age);
      if (!personal.age || isNaN(parsedAge) || parsedAge < 0 || parsedAge > 150) {
        newErrors.age = 'Enter an age between 0 and 150.';
      }
    }

    // Validate Contact Info
    if (!contact.phone.trim()) {
      newErrors.phone = 'Mobile number is required.';
    } else if (!validPhone(contact.phone)) {
      newErrors.phone = 'Enter a valid mobile number (e.g. 98765 43210).';
    }
    if (contact.email.trim() && !/^\S+@\S+\.\S+$/.test(contact.email.trim())) {
      newErrors.email = 'Enter a valid email address.';
    }

    // Validate Duplicate Override
    if (matches.length > 0) {
      if (!overrideConfirmed) {
        newErrors.form = 'A possible existing patient was found. Confirm this is a different person to continue.';
      } else if (overrideReason.trim().length < 3) {
        setOverrideReasonError('Provide a brief reason (e.g. Family member sharing mobile).');
        newErrors.form = 'Please provide an override explanation for duplicate confirmation.';
      }
    }

    setErrors(newErrors);

    if (Object.keys(newErrors).length > 0) {
      scrollToError();
      return;
    }

    // Construct form data for multipart upload
    const formData = new FormData();
    formData.append('surface', 'full_page');
    formData.append('idempotency_key', key.current);

    // Personal fields
    formData.append('first_name', personal.first_name.trim());
    if (personal.middle_name.trim()) formData.append('middle_name', personal.middle_name.trim());
    if (personal.last_name.trim()) formData.append('last_name', personal.last_name.trim());
    if (personal.gender) formData.append('gender', personal.gender);
    if (!personal.dob_unknown && personal.date_of_birth) {
      formData.append('date_of_birth', personal.date_of_birth);
    }
    if (personal.dob_unknown && personal.age) {
      formData.append('age', personal.age);
    }
    if (personal.blood_group) formData.append('blood_group', personal.blood_group);

    // Contact fields
    formData.append('phone', contact.phone.trim());
    if (contact.email.trim()) formData.append('email', contact.email.trim());
    if (contact.address.trim()) formData.append('address', contact.address.trim());
    if (contact.city.trim()) formData.append('city', contact.city.trim());
    if (contact.state.trim()) formData.append('state', contact.state.trim());
    if (contact.pincode.trim()) formData.append('pincode', contact.pincode.trim());

    // Medical conditions
    medical.conditions.forEach((cond, idx) => {
      if (cond.key) formData.append(`medical_conditions[${idx}][key]`, cond.key);
      formData.append(`medical_conditions[${idx}][label]`, cond.label);
    });

    // Allergies
    medical.allergies.forEach((allergy, idx) => {
      if (allergy.key) formData.append(`allergies[${idx}][key]`, allergy.key);
      formData.append(`allergies[${idx}][label]`, allergy.label);
    });
    if (medical.allergies.length === 0 && medical.allergy_none) {
      formData.append('allergy_status', 'none_known');
    }

    // Medications
    medical.medications.forEach((med, idx) => {
      formData.append(`medications[${idx}][label]`, med.label);
    });

    // Medical notes combined with module notes
    const combinedNotes = [
      medical.medical_notes.trim(),
      moduleData.dental_history ? `[Dental History]: ${moduleData.dental_history.trim()}` : null,
      moduleData.dental_treatments?.length ? `[Dental Treatments]: ${moduleData.dental_treatments.join(', ')}` : null,
      moduleData.physio_pain_area ? `[Physio Pain Area]: ${moduleData.physio_pain_area}` : null,
      moduleData.physio_pain_duration ? `[Physio Duration]: ${moduleData.physio_pain_duration}` : null,
      moduleData.physio_mobility_limitation ? `[Physio Mobility]: ${moduleData.physio_mobility_limitation}` : null,
      moduleData.physio_notes ? `[Physio Notes]: ${moduleData.physio_notes.trim()}` : null,
    ]
      .filter(Boolean)
      .join('\n');

    if (combinedNotes) {
      formData.append('notes', combinedNotes);
    }

    // Specialties
    const activeSpecialtyKeys = specialties.map((s) => s.key);
    activeSpecialtyKeys.forEach((specKey) => {
      formData.append('specialties[]', specKey);
    });

    // Duplicate overrides
    if (matches.length > 0 && overrideConfirmed) {
      matches.forEach((m: DuplicateMatch) => {
        formData.append('duplicate_acknowledged_ids[]', String(m.id));
      });
      formData.append('duplicate_reason', overrideReason.trim());
    }

    // Attached files
    pendingDocs.forEach((doc, idx) => {
      formData.append(`documents[${idx}]`, doc.file);
      formData.append(`document_types[${idx}]`, doc.type);
    });

    router.post(route('clinic.patients.store'), formData as any, {
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
        setErrors(fieldErrors);
        setFormError(
          fieldErrors.duplicate ||
            fieldErrors.duplicate_reason ||
            fieldErrors.phone ||
            'Some fields require attention. Review the errors highlighted above.',
        );
        scrollToError();
      },
      onSuccess: () => {
        key.current = requestKey();
      },
      onNetworkError: () => {
        setFormError('Connection issue: Registration was not saved. Try again without losing your entered data.');
        return false;
      },
      onHttpException: () => {
        setFormError('Server response error: Could not save patient. Try again.');
        return false;
      },
    });
  };

  const activeSpecialtyKeys = specialties.map((s) => s.key);

  return (
    <div className="mx-auto w-full max-w-[1360px] pb-16">
      <PageHeader
        eyebrow={<span className="text-[11px] font-bold uppercase tracking-[0.08em] text-[#0D9488]">Patient Management</span>}
        title="Add New Patient"
        description={`Create a permanent healthcare record${branch ? ` at ${branch.name}` : ''}. UHID is automatically assigned on registration.`}
        actions={
          <ButtonLink href={links.index} tone="ghost" icon={ArrowLeft} className="text-slate-600 hover:text-slate-900">
            Back to Directory
          </ButtonLink>
        }
      />

      {formError && (
        <div className="mb-6">
          <Notice tone="danger">{formError}</Notice>
        </div>
      )}

      {/* Main 2-Column Desktop Grid / 1-Column Mobile Layout */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_340px] xl:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        {/* Left Column: Form Sections */}
        <form noValidate onSubmit={handleSubmit} className="space-y-6">
          {/* Section 1: Personal Information (Soft Blue) */}
          <PersonalInfoSection
            data={personal}
            onChange={setPersonalField}
            errors={errors}
            lastNameRequired={form?.fields?.last_name?.state === 'required'}
            autoFocus
          />

          {/* Section 2: Contact Details (Soft Teal) */}
          <ContactInfoSection
            data={contact}
            onChange={setContactField}
            errors={errors}
          />

          {/* Section 3: Medical History & Safety (Soft Lavender) */}
          <MedicalHistorySection
            data={medical}
            onChange={setMedicalField}
            catalogueConditions={form?.catalogue?.conditions}
            catalogueAllergies={form?.catalogue?.allergies}
            errors={errors}
          />

          {/* Section 4: Specialty Clinical Information (Soft Pink) */}
          <ModuleSpecificSection
            specialties={activeSpecialtyKeys}
            data={moduleData}
            onChange={setModuleField}
            errors={errors}
          />

          {/* Section 5: Documents & Records (Soft Yellow) */}
          <DocumentsSection
            pendingFiles={pendingDocs}
            onAddPending={handleAddPendingDocs}
            onRemovePending={handleRemovePendingDoc}
          />

          {/* Desktop Fallback Bottom Bar */}
          <div className="hidden lg:flex items-center justify-between border-t border-slate-200/90 pt-4">
            <ButtonLink href={links.index} tone="ghost" className="text-slate-600 hover:text-slate-900">
              Cancel
            </ButtonLink>
            <Button
              type="submit"
              tone="primary"
              icon={UserPlus}
              loading={saving}
              loadingLabel="Registering…"
              disabled={saving}
              className="h-11 px-6 font-bold shadow-md shadow-blue-500/20"
            >
              Register Patient
            </Button>
          </div>
        </form>

        {/* Right Column: Sticky Summary & Duplicate Telemetry (Desktop Only) */}
        <aside className="hidden lg:block">
          <RegistrationSummaryPanel
            firstName={personal.first_name}
            middleName={personal.middle_name}
            lastName={personal.last_name}
            phone={contact.phone}
            gender={personal.gender}
            dateOfBirth={personal.date_of_birth}
            age={personal.age}
            dobUnknown={personal.dob_unknown}
            conditionsCount={medical.conditions.length}
            allergiesCount={medical.allergies.length}
            documentsCount={pendingDocs.length}
            duplicateMatches={matches}
            overrideConfirmed={overrideConfirmed}
            overrideReason={overrideReason}
            onOverrideConfirmChange={setOverrideConfirmed}
            onOverrideReasonChange={setOverrideReason}
            overrideReasonError={overrideReasonError}
            saving={saving}
            cancelHref={links.index}
            onSubmit={() => handleSubmit()}
          />
        </aside>
      </div>

      {/* Mobile Sticky Bottom Action Bar */}
      <div className="lg:hidden fixed bottom-0 left-0 right-0 z-30 flex items-center justify-between gap-3 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur shadow-lg">
        <ButtonLink href={links.index} tone="ghost" className="text-[13.5px] text-slate-600">
          Cancel
        </ButtonLink>
        <Button
          type="button"
          tone="primary"
          icon={UserPlus}
          onClick={() => handleSubmit()}
          loading={saving}
          loadingLabel="Saving…"
          disabled={saving}
          className="h-11 px-5 font-bold shadow-md"
        >
          Register Patient
        </Button>
      </div>
    </div>
  );
}
