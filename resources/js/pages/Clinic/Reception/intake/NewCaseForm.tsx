import { useEffect, useRef, type ReactNode } from 'react';
import { Activity, AlertTriangle, CalendarDays, Check, Droplets, HeartPulse, IndianRupee, MapPin, Phone, ShieldCheck, Siren, Stethoscope, UserRound, Wind } from 'lucide-react';
import { cn } from '@/lib/utils';
import { DatePicker, toIsoDate } from '@/clinic';
import { ageFromDob } from '@/pages/ClinicPortal/Patients/registration/types';
import type { ClinicalChoice } from './ClinicalPicker';
import { GENDERS, labelOf, required, shown, type PatientErrors, type PatientForm, type SetPatientField } from './PatientFields';
import { chargedAtCheckIn, contextOf, forContext, reasonsFor, visitSpecialty, type IntakeConfig, type PractitionerOption, type VisitForm } from './types';
import { doctorsFor, type VisitErrors } from './VisitFields';

/*
 * New Case, drawn from scratch for the front desk: one continuous form on one
 * white sheet, nothing folded away. Three quiet headings (Patient details,
 * Health, Today's visit) and the fields paired across the width. The state, rules and request are the dialog's own
 * (`ReceptionIntakeDialog`); this file only draws them.
 *
 * Fields, exactly: patient name (first + last), mobile (possible existing
 * patients right under it), date of birth or age, gender, address, quick
 * health, doctor/therapist, reason, priority. The consultation fee is told
 * once, in the footer (`FeeSummary`).
 */

const OTHER = 'other';
const CHEVRON = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")";
/** The priorities this popup offers (`VisitPriority`): the ones that change the queue order. */
// `on`: chosen; `off`: a quiet hint of what each means even before it is chosen.
const PRIORITIES: { value: string; label: string; icon: typeof Siren | null; on: string; off: string }[] = [
  { value: 'normal', label: 'Normal', icon: null, on: 'border-[#14917F] bg-[#E3F6F2] text-[#0F6B5F]', off: 'border-[#DCE3EC] text-[#475569] hover:border-[#14917F]/50 hover:bg-[#F3FBF9]' },
  { value: 'urgent', label: 'Urgent', icon: AlertTriangle, on: 'border-[#D97706] bg-[#FFF3DC] text-[#92400E]', off: 'border-[#F3DEB8] text-[#B45309] hover:border-[#D97706]/60 hover:bg-[#FFF8EB]' },
  { value: 'emergency', label: 'Emergency', icon: Siren, on: 'border-[#E11D48] bg-[#FFE4EA] text-[#9F1239]', off: 'border-[#F6CCD6] text-[#BE123C] hover:border-[#E11D48]/50 hover:bg-[#FFF1F4]' },
];

/**
 * The quick health choices and where each is kept: four catalogue
 * conditions (by key), "Allergy" as an allergy entry (the allergen is asked
 * in the consultation), and "None known" as the recorded "no known
 * allergies" status. Each is offered only when the clinic's catalogue and
 * field settings let it be saved.
 */
// `tint`: the icon's soft colour before the chip is chosen, so each condition is recognisable at a glance.
const CONDITIONS: { key: string; label: string; icon: typeof HeartPulse; on: string; tint: string }[] = [
  { key: 'diabetes', label: 'Diabetes', icon: Droplets, on: 'border-[#7C3AED]/40 bg-[#F1EBFF] text-[#5B21B6]', tint: 'text-[#8B5CF6]' },
  { key: 'hypertension', label: 'High BP', icon: Activity, on: 'border-[#E11D48]/35 bg-[#FFE9EE] text-[#9F1239]', tint: 'text-[#F43F5E]' },
  { key: 'heart_disease', label: 'Heart problem', icon: HeartPulse, on: 'border-[#DB2777]/35 bg-[#FDE8F3] text-[#9D174D]', tint: 'text-[#EC4899]' },
  { key: 'asthma', label: 'Asthma', icon: Wind, on: 'border-[#0284C7]/35 bg-[#E3F2FD] text-[#075985]', tint: 'text-[#0EA5E9]' },
];
export const UNSPECIFIED_ALLERGY = 'Allergy (unspecified)';

const INPUT =
  'h-10 w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 text-[14px] text-slate-900 placeholder:text-slate-400 transition-colors hover:border-slate-300 focus:border-blue-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-blue-500/10 [@media(pointer:coarse)]:h-11';
const INVALID = 'border-rose-400 bg-rose-50/50 text-rose-900 focus:border-rose-500 focus:ring-rose-500/15';

export interface NewCaseFormProps {
  config: IntakeConfig;
  /** The field settings as they apply to this visit's module. */
  fields: IntakeConfig;
  data: PatientForm;
  setField: SetPatientField;
  /** Several fields from the current form at once (health taps). */
  patchData: (change: (current: PatientForm) => Partial<PatientForm>) => void;
  errors: PatientErrors;
  /** Possible existing patients and today's bookings for the mobile, drawn by the dialog (with their safeguards). */
  matches: ReactNode;
  visit: VisitForm;
  onVisit: (patch: Partial<VisitForm>) => void;
  visitErrors: VisitErrors;
  noun: string;
  /** Fields this clinic requires that this popup does not ask (owner settings); registering here is then refused. */
  missingRequired: string[];
  createUrl: string | null;
}

export function NewCaseForm(props: NewCaseFormProps) {
  const { config, fields, data, setField, patchData, errors, matches, visit, onVisit, visitErrors, noun, missingRequired, createUrl } = props;

  return (
    <div className="space-y-4">
      {missingRequired.length > 0 && (
        <p role="status" className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-[13px] leading-5 text-amber-900">
          This clinic also requires {missingRequired.join(', ')} at registration, which this quick form does not ask.{' '}
          {createUrl ? (
            <a href={createUrl} className="font-bold underline underline-offset-2">
              Use the full registration page
            </a>
          ) : (
            'Use the full registration page'
          )}
          .
        </p>
      )}

      {/* Form sections styled with Patient Edit's SectionCard tones */}
      <div className="space-y-4">
        <FormGroup id="nc-group-patient" title="Patient Details" icon={UserRound} tone="blue" first>
          <PatientFields fields={fields} data={data} setField={setField} errors={errors} matches={matches} />
        </FormGroup>
        <FormGroup id="nc-group-health" title="Health & Safety Alerts" hint="Optional · tap reported conditions" icon={HeartPulse} tone="lavender">
          <HealthStep config={config} fields={fields} data={data} patchData={patchData} visit={visit} />
          <FieldError id="nc-health-error" message={errors.allergy_status ?? errors.conditions ?? errors.medical_conditions ?? errors.allergies} />
        </FormGroup>
        <FormGroup id="nc-group-visit" title="Today’s Visit Details" icon={Stethoscope} tone="teal">
          <VisitStep config={config} visit={visit} onVisit={onVisit} errors={visitErrors} noun={noun} />
        </FormGroup>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------ the groups */

/** SectionCard-styled group with pastel top border and gradient icon tile */
export function FormGroup({
  id,
  title,
  hint,
  icon: Icon,
  tone = 'teal',
  first = false,
  children,
}: {
  id: string;
  title: string;
  hint?: string;
  icon: typeof UserRound;
  tone?: 'blue' | 'teal' | 'lavender';
  first?: boolean;
  children: ReactNode;
}) {
  const toneStyles = {
    blue: {
      borderAccent: 'border-t-[3.5px] border-t-[#2563EB]',
      iconBg: 'bg-gradient-to-br from-[#3B82F6] to-[#1D4ED8] text-white shadow-blue-500/25',
    },
    teal: {
      borderAccent: 'border-t-[3.5px] border-t-[#0D9488]',
      iconBg: 'bg-gradient-to-br from-[#14B8A6] to-[#0F766E] text-white shadow-teal-500/25',
    },
    lavender: {
      borderAccent: 'border-t-[3.5px] border-t-[#8B5CF6]',
      iconBg: 'bg-gradient-to-br from-[#8B5CF6] to-[#6D28D9] text-white shadow-purple-500/25',
    },
  }[tone];

  return (
    <section
      aria-labelledby={id}
      className={cn(
        'relative min-w-0 rounded-2xl border border-slate-200/80 bg-white p-4 sm:p-5 shadow-[0_2px_12px_rgba(15,23,42,0.03)]',
        toneStyles.borderAccent,
      )}
    >
      <div className="mb-3.5 flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
        <h3 id={id} className="flex flex-wrap items-center gap-2.5">
          <span
            className={cn(
              'grid h-8 w-8 shrink-0 place-items-center rounded-xl shadow-sm',
              toneStyles.iconBg
            )}
            aria-hidden="true"
          >
            <Icon className="h-4 w-4" strokeWidth={2.2} />
          </span>
          <span className="text-[14.5px] font-bold tracking-tight text-slate-900 sm:text-[15px]">
            {title}
          </span>
        </h3>
        {hint && <span className="text-[12px] font-medium text-slate-500">{hint}</span>}
      </div>
      {children}
    </section>
  );
}

function Label({ htmlFor, children, isRequired = false, aside }: { htmlFor?: string; children: ReactNode; isRequired?: boolean; aside?: ReactNode }) {
  return (
    // One label height everywhere (it fits the date-of-birth switch), so paired fields line up.
    <div className="mb-1 flex min-h-[22px] items-center justify-between gap-2">
      <label htmlFor={htmlFor} className="text-[12.5px] font-semibold text-[#334155]">
        {children}
        {isRequired && (
          <span className="ml-0.5 text-[#E5484D]" aria-hidden="true">
            *
          </span>
        )}
      </label>
      {aside}
    </div>
  );
}

function FieldError({ id, message }: { id: string; message?: string }) {
  return message ? (
    <p id={id} role="alert" className="mt-1.5 text-[12px] font-medium text-[#D92D20] motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-150">
      {message}
    </p>
  ) : null;
}

/* ---------------------------------------------------------- patient details */

/**
 * Paired across the width: first | last name, mobile | age or date of
 * birth (the possible existing patients right under that row), gender |
 * address. The names stay side by side on a phone; the other pairs stack.
 */
function PatientFields({ fields, data, setField, errors, matches }: { fields: IntakeConfig; data: PatientForm; setField: SetPatientField; errors: PatientErrors; matches: ReactNode }) {
  const lastShown = shown(fields, 'last_name');
  const lastLabel = labelOf(fields, 'last_name', 'Last name');

  return (
    // Dense packing lets age/date of birth sit beside the mobile on wider screens while the possible
    // existing patients follow the mobile directly in the markup (and so directly under it on a phone).
    <div className="grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-flow-row-dense sm:gap-x-4 sm:gap-y-2.5">
      <div className={cn('min-w-0', !lastShown && 'col-span-2 sm:col-span-1')}>
        <Label htmlFor="nc-first_name" isRequired>
          First name
        </Label>
        <input
          id="nc-first_name"
          autoComplete="off"
          autoFocus
          value={data.first_name}
          onChange={(event) => setField('first_name', event.target.value)}
          aria-invalid={!!errors.first_name}
          aria-describedby={errors.first_name ? 'nc-first_name-error' : undefined}
          className={cn(INPUT, errors.first_name && INVALID)}
        />
        <FieldError id="nc-first_name-error" message={errors.first_name} />
      </div>
      {lastShown && (
        <div className="min-w-0">
          <Label htmlFor="nc-last_name" isRequired={required(fields, 'last_name')}>
            {lastLabel}
          </Label>
          <input
            id="nc-last_name"
            autoComplete="off"
            value={data.last_name}
            onChange={(event) => setField('last_name', event.target.value)}
            aria-invalid={!!errors.last_name}
            aria-describedby={errors.last_name ? 'nc-last_name-error' : undefined}
            className={cn(INPUT, errors.last_name && INVALID)}
          />
          <FieldError id="nc-last_name-error" message={errors.last_name} />
        </div>
      )}

      <div className={cn('col-span-2 min-w-0', shown(fields, 'date_of_birth') && 'sm:col-span-1')}>
        <Label htmlFor="nc-phone" isRequired>
          Mobile number
        </Label>
        <div className="relative">
          <Phone className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[#94A3B8]" aria-hidden="true" />
          <input
            id="nc-phone"
            type="tel"
            inputMode="tel"
            autoComplete="off"
            placeholder="98765 43210"
            value={data.phone}
            onChange={(event) => setField('phone', event.target.value)}
            aria-invalid={!!errors.phone}
            aria-describedby={errors.phone ? 'nc-phone-error' : undefined}
            className={cn(INPUT, 'pl-10', errors.phone && INVALID)}
          />
        </div>
        <FieldError id="nc-phone-error" message={errors.phone} />
      </div>
      {matches && <div className="col-span-2">{matches}</div>}
      {shown(fields, 'date_of_birth') && (
        <div className="col-span-2 min-w-0 sm:col-span-1">
          <AgeField fields={fields} data={data} setField={setField} errors={errors} />
        </div>
      )}

      {shown(fields, 'gender') && (
        <div className="col-span-2 min-w-0 sm:col-span-1">
          <Label isRequired={required(fields, 'gender')}>{labelOf(fields, 'gender', 'Gender')}</Label>
          <Segmented
            label={labelOf(fields, 'gender', 'Gender')}
            options={GENDERS}
            value={data.gender}
            onChange={(gender) => setField('gender', gender)}
            invalid={!!errors.gender}
            describedBy={errors.gender ? 'nc-gender-error' : undefined}
          />
          <FieldError id="nc-gender-error" message={errors.gender} />
        </div>
      )}

      {shown(fields, 'address') && (
        <div className={cn('col-span-2 min-w-0', shown(fields, 'gender') && 'sm:col-span-1')}>
          <Label htmlFor="nc-address" isRequired={required(fields, 'address')}>
            {labelOf(fields, 'address', 'Address')} {!required(fields, 'address') && <span className="font-medium text-[#94A3B8]">· optional</span>}
          </Label>
          <div className="relative">
            <MapPin className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[#94A3B8]" aria-hidden="true" />
            <input
              id="nc-address"
              autoComplete="off"
              placeholder="House, street, area, city"
              value={data.address}
              onChange={(event) => setField('address', event.target.value)}
              aria-invalid={!!errors.address}
              aria-describedby={errors.address ? 'nc-address-error' : undefined}
              className={cn(INPUT, 'pl-10', errors.address && INVALID)}
            />
          </div>
          <FieldError id="nc-address-error" message={errors.address} />
        </div>
      )}
    </div>
  );
}

/** Date of birth, or an approximate age when it is not known — a two-way switch, both always one tap away. */
function AgeField({ fields, data, setField, errors }: { fields: IntakeConfig; data: PatientForm; setField: SetPatientField; errors: PatientErrors }) {
  const derived = data.dob_unknown ? null : ageFromDob(data.date_of_birth);
  const switchTo = (unknown: boolean) => {
    if (unknown !== data.dob_unknown) {
      setField('dob_unknown', unknown);
      setField(unknown ? 'date_of_birth' : 'age', '');
    }
  };
  const tab = (unknown: boolean, label: string) => (
    <button
      type="button"
      aria-pressed={data.dob_unknown === unknown}
      onClick={() => switchTo(unknown)}
      className={cn(
        'rounded-full px-2.5 py-px text-[12px] font-semibold motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#14917F]/40',
        data.dob_unknown === unknown ? 'bg-[#E3F6F2] text-[#0F6B5F]' : 'text-[#64748B] hover:text-[#0F2A44]',
      )}
    >
      {label}
    </button>
  );

  return (
    <div>
      <Label
        htmlFor={data.dob_unknown ? 'nc-age' : 'nc-date_of_birth'}
        isRequired={data.dob_unknown || required(fields, 'date_of_birth')}
        aside={
          <span className="inline-flex rounded-full bg-[#F1F5FA] p-0.5" role="group" aria-label="Date of birth or age">
            {tab(false, 'Birth date')}
            {tab(true, 'Age')}
          </span>
        }
      >
        {data.dob_unknown ? 'Approximate age' : labelOf(fields, 'date_of_birth', 'Date of birth')}
      </Label>
      {data.dob_unknown ? (
        <div className="relative">
          <input
            id="nc-age"
            inputMode="numeric"
            placeholder="Years"
            value={data.age}
            onChange={(event) => setField('age', event.target.value.replace(/\D/g, '').slice(0, 3))}
            aria-invalid={!!errors.age}
            aria-describedby={errors.age ? 'nc-age-error' : undefined}
            className={cn(INPUT, 'pr-14', errors.age && INVALID)}
          />
          <span className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[13px] text-[#94A3B8]">years</span>
        </div>
      ) : (
        <DatePicker id="nc-date_of_birth" value={data.date_of_birth} onChange={(value) => setField('date_of_birth', value)} max={toIsoDate(new Date())} invalid={!!errors.date_of_birth} placeholder="Pick the date" />
      )}
      {!data.dob_unknown && derived !== null && !errors.date_of_birth && (
        <p className="mt-1.5 inline-flex items-center gap-1 text-[12px] font-medium text-[#0F6B5F]">
          <CalendarDays className="h-3.5 w-3.5" aria-hidden="true" />
          {derived} years old
        </p>
      )}
      <FieldError id="nc-age-error" message={errors.age ?? errors.date_of_birth} />
    </div>
  );
}

/** A row of mutually exclusive pills, as a radio group (arrow keys move between them). */
function Segmented({ label, options, value, onChange, invalid = false, describedBy }: { label: string; options: { value: string; label: string }[]; value: string; onChange: (value: string) => void; invalid?: boolean; describedBy?: string }) {
  return (
    <div role="radiogroup" aria-label={label} aria-describedby={describedBy} className={cn('grid gap-1 rounded-xl border bg-[#F6F8FC] p-1', invalid ? 'border-[#E5484D]' : 'border-[#E3E8F4]')} style={{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }}>
      {options.map((option) => {
        const selected = value === option.value;

        return (
          <button
            key={option.value}
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={() => onChange(option.value)}
            className={cn(
              'h-8 rounded-[10px] text-[13.5px] font-semibold motion-safe:transition-[background-color,color,box-shadow] motion-safe:duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#14917F]/40 [@media(pointer:coarse)]:h-10',
              selected ? 'bg-white text-[#0F6B5F] shadow-[0_2px_8px_-3px_rgba(15,107,95,0.45)]' : 'text-[#475569] hover:text-[#0F2A44]',
            )}
          >
            {option.label}
          </button>
        );
      })}
    </div>
  );
}

/* --------------------------------------------------------------- 2 · Health */

/**
 * The quick health chips. Several may be chosen; "None known" clears them
 * and stands alone. Each writes what the backend keeps (see `CONDITIONS`):
 * nothing is shown that is not saved, and a chip the clinic's catalogue or
 * settings cannot save is not offered.
 */
function HealthStep({ config, fields, data, patchData, visit }: { config: IntakeConfig; fields: IntakeConfig; data: PatientForm; patchData: NewCaseFormProps['patchData']; visit: VisitForm }) {
  if (!config.can.record_clinical) {
    return <p className="text-[13px] text-[#64748B]">Health details are recorded by the clinical team.</p>;
  }
  const specialty = visitSpecialty(config, visit);
  const area = contextOf(config, visit)?.area ?? null;
  const catalogue = config.specialties.length > 1 && specialty === '' ? config.catalogue.conditions.filter((item) => item.specialty_keys === null) : forContext(config.catalogue.conditions, specialty, area);
  const conditions = shown(fields, 'medical_conditions') ? CONDITIONS.filter((condition) => catalogue.some((item) => item.key === condition.key)) : [];
  const allergyOffered = shown(fields, 'allergies');
  const noneOffered = allergyOffered && config.quick_care.allergy_status !== 'hidden';

  const hasCondition = (key: string) => data.conditions.some((choice) => choice.key === key);
  const hasAllergy = data.allergies.some((choice) => choice.label === UNSPECIFIED_ALLERGY);
  const noneKnown = data.allergy_none && data.conditions.length === 0 && data.allergies.length === 0;

  // Each tap reads the form as it is now, so quick taps never undo one another.
  const toggleCondition = (key: string, label: string) => {
    const name = catalogue.find((item) => item.key === key)?.name ?? label;
    patchData((current) => ({
      conditions: current.conditions.some((choice) => choice.key === key) ? current.conditions.filter((choice) => choice.key !== key) : [...current.conditions, { key, label: name } as ClinicalChoice],
      allergy_none: false,
    }));
  };
  const toggleAllergy = () =>
    patchData((current) => ({
      allergies: current.allergies.some((choice) => choice.label === UNSPECIFIED_ALLERGY) ? current.allergies.filter((choice) => choice.label !== UNSPECIFIED_ALLERGY) : [...current.allergies, { label: UNSPECIFIED_ALLERGY }],
      allergy_none: false,
    }));
  // "None known" stands alone: it clears the others; tapping it again clears it.
  const toggleNone = () => patchData((current) => (current.allergy_none ? { allergy_none: false } : { conditions: [], allergies: [], allergy_none: true }));

  const conditionChip = (condition: (typeof CONDITIONS)[number]) => (
    <HealthChip key={condition.key} icon={condition.icon} on={condition.on} tint={condition.tint} pressed={hasCondition(condition.key)} onClick={() => toggleCondition(condition.key, condition.label)}>
      {condition.label}
    </HealthChip>
  );

  if (conditions.length === 0 && !allergyOffered) {
    return <p className="text-[13px] text-[#64748B]">This clinic does not record health conditions at registration.</p>;
  }

  return (
    <div role="group" aria-label="Health the patient reports" className="flex flex-wrap gap-2">
      {/* In the desk's order: Diabetes, High BP, Heart problem, Allergy, Asthma, None known. */}
      {conditions.filter((condition) => condition.key !== 'asthma').map(conditionChip)}
      {allergyOffered && (
        <HealthChip icon={AlertTriangle} on="border-[#D97706]/40 bg-[#FFF3DC] text-[#92400E]" tint="text-[#F59E0B]" pressed={hasAllergy} onClick={toggleAllergy}>
          Allergy
        </HealthChip>
      )}
      {conditions.filter((condition) => condition.key === 'asthma').map(conditionChip)}
      {noneOffered && (
        <HealthChip icon={ShieldCheck} on="border-[#14917F]/40 bg-[#E3F6F2] text-[#0F6B5F]" tint="text-[#14B8A6]" pressed={noneKnown} onClick={toggleNone}>
          None known
        </HealthChip>
      )}
    </div>
  );
}

function HealthChip({ icon: Icon, on, tint, pressed, onClick, children }: { icon: typeof HeartPulse; on: string; tint: string; pressed: boolean; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      onClick={onClick}
      className={cn(
        'inline-flex h-9 items-center gap-2 rounded-full border px-3.5 text-[13.5px] font-semibold motion-safe:transition-[background-color,border-color,color,box-shadow,transform] motion-safe:duration-150 motion-safe:active:scale-[0.97] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#14917F]/40 [@media(pointer:coarse)]:h-11',
        pressed ? cn(on, 'shadow-[0_4px_12px_-8px_rgba(15,42,68,0.6)]') : 'border-[#DCE3EC] bg-white text-[#334155] hover:border-[#B9C6D8] hover:bg-[#F7FAFD]',
      )}
    >
      {pressed ? <Check className="h-4 w-4 motion-safe:animate-in motion-safe:zoom-in-50 motion-safe:duration-150" aria-hidden="true" /> : <Icon className={cn('h-4 w-4', tint)} aria-hidden="true" />}
      {children}
    </button>
  );
}

/* --------------------------------------------------------- 3 · Today's visit */

/**
 * Who sees them, why they came, how urgent: the same choices and rules as
 * before (`VisitFields`) — module first in a clinic offering several, a lone
 * eligible practitioner chosen for you, the area when the practitioner has
 * several, reasons from the catalogue with "Other" — drawn as tiles and pills.
 */
export function VisitStep({ config, visit, onVisit, errors, noun, idPrefix = 'nc-visit', paired = true }: { config: IntakeConfig; visit: VisitForm; onVisit: (patch: Partial<VisitForm>) => void; errors: VisitErrors; noun: string; idPrefix?: string; /** Provider | reason side by side (New Case); `below-lg`: side by side until the visit sits in its own column beside the patient (Repeat Case, from 1024px). */ paired?: boolean | 'below-lg' }) {
  const multiModule = config.specialties.length > 1;
  const moduleKey = visit.specialty !== '' ? visit.specialty : multiModule ? '' : (config.specialties[0]?.key ?? '');
  const eligible = doctorsFor(config, multiModule ? moduleKey : '');
  const doctor = config.doctors.find((option) => String(option.id) === visit.doctor_id) ?? null;
  const context = contextOf(config, visit);
  const specialty = visitSpecialty(config, visit);
  const reasons = reasonsFor(config, specialty, context?.area ?? null);
  const reasonState = config.check_in.visit_reason;
  const touched = useRef(false);

  const keepReason = (patch: Partial<VisitForm>): Partial<VisitForm> => {
    const next = { ...visit, ...patch };
    const offered = reasonsFor(config, visitSpecialty(config, next), contextOf(config, next)?.area ?? null);
    const keep = visit.reason_key === OTHER || offered.some((item) => item.key === visit.reason_key);

    return { ...patch, reason_key: keep ? visit.reason_key : '', visit_reason: keep ? visit.visit_reason : '' };
  };
  const chooseDoctor = (doctorId: string) => {
    touched.current = true;
    const chosen = config.doctors.find((option) => String(option.id) === doctorId);
    const first = chosen?.contexts.find((option) => !multiModule || moduleKey === '' || option.specialty === moduleKey) ?? chosen?.contexts[0];
    onVisit(keepReason({ doctor_id: doctorId, area: first?.area ?? '', ...(multiModule && first?.specialty ? { specialty: first.specialty } : {}) }));
  };
  const chooseModule = (next: string) => {
    const keepDoctor = doctor && doctor.contexts.some((option) => option.specialty === next);
    onVisit(keepReason({ specialty: next, ...(keepDoctor ? {} : { doctor_id: '', area: '' }) }));
  };

  // Exactly one practitioner can take this visit here today: chosen for you (reception can still pick "Next available").
  const only = eligible.length === 1 && (!multiModule || moduleKey !== '') ? eligible[0] : null;
  useEffect(() => {
    if (only && visit.doctor_id === '' && !touched.current) {
      onVisit(keepReason({ doctor_id: String(only.id), area: '' }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [only?.id, visit.doctor_id]);

  const areas = doctor ? doctor.contexts.filter((option) => !multiModule || moduleKey === '' || option.specialty === moduleKey) : [];
  const priorities = config.check_in.priority ? PRIORITIES.filter((priority) => config.priorities.some((offered) => offered.value === priority.value)) : [];

  // Full width in the paired grid; in one column a span would only add an empty second track.
  const full = cn('min-w-0', paired && 'sm:col-span-2', paired === 'below-lg' && 'lg:col-span-1');
  const selectClass = cn(INPUT, 'appearance-none truncate bg-[length:16px] bg-[right_14px_center] bg-no-repeat pr-10');
  const doctorLabel = (option: PractitionerOption) =>
    [option.name, multiModule && moduleKey === '' ? option.module?.label : option.area?.label, option.shift === 'not_set' ? null : option.shift_label].filter(Boolean).join(' · ');

  return (
    // Provider | reason side by side (stacked on a phone); the extras each take the full width only when they apply.
    <div className={cn('grid grid-cols-1 gap-x-4 gap-y-3 sm:gap-y-2.5', paired && 'sm:grid-cols-2', paired === 'below-lg' && 'lg:grid-cols-1')}>
      {multiModule && (
        <div className={full}>
          <Label isRequired>Visit for</Label>
          <Segmented label="Visit for" options={config.specialties.map((option) => ({ value: option.key, label: option.label }))} value={moduleKey} onChange={chooseModule} invalid={!!errors.specialty} describedBy={errors.specialty ? `${idPrefix}-specialty-error` : undefined} />
          <FieldError id={`${idPrefix}-specialty-error`} message={errors.specialty} />
        </div>
      )}

      {/*
        The provider is a native dropdown: drawn by the browser above everything (never clipped by the
        dialog), opening up or down as there is room, with type-ahead to jump to a name. No fee here —
        the fee is said once, in the footer.
      */}
      <div className="min-w-0">
        <Label htmlFor={`${idPrefix}-doctor`}>{noun}</Label>
        {config.doctors.length === 0 || eligible.length === 0 ? (
          <p className="flex min-h-10 items-center rounded-xl border border-dashed border-[#D8E0EC] bg-[#F8FAFD] px-3.5 text-[13px] text-[#64748B]">
            {multiModule && moduleKey === '' && config.doctors.length > 0 ? 'Choose what the visit is for first' : `No ${noun.toLowerCase()} on shift; the queue assigns one`}
          </p>
        ) : (
          <select
            id={`${idPrefix}-doctor`}
            value={visit.doctor_id}
            onChange={(event) => chooseDoctor(event.target.value)}
            aria-invalid={!!errors.doctor_id}
            aria-describedby={errors.doctor_id ? `${idPrefix}-doctor-error` : undefined}
            className={cn(selectClass, visit.doctor_id !== '' && 'border-[#14917F]/60 bg-[#F3FBF9] font-semibold', errors.doctor_id && INVALID)}
            style={{ backgroundImage: CHEVRON }}
          >
            <option value="">Next available {noun.toLowerCase()}</option>
            {eligible.map((option) => (
              <option key={option.id} value={String(option.id)}>
                {doctorLabel(option)}
              </option>
            ))}
          </select>
        )}
        <FieldError id={`${idPrefix}-doctor-error`} message={errors.doctor_id} />
      </div>

      {reasonState !== 'hidden' && (
        <div className="min-w-0">
          <Label htmlFor={`${idPrefix}-reason`} isRequired={reasonState === 'required'}>
            Reason for visit
          </Label>
          {multiModule && specialty === '' ? (
            <p className="flex min-h-10 items-center rounded-xl border border-dashed border-[#D8E0EC] bg-[#F8FAFD] px-3.5 text-[13px] text-[#64748B]">Choose what the visit is for first</p>
          ) : (
            <select
              id={`${idPrefix}-reason`}
              value={visit.reason_key}
              onChange={(event) => onVisit({ reason_key: event.target.value, visit_reason: event.target.value === OTHER ? visit.visit_reason : '' })}
              aria-invalid={!!errors.reason_key}
              aria-describedby={errors.reason_key || errors.visit_reason ? `${idPrefix}-reason-error` : undefined}
              className={cn(selectClass, visit.reason_key !== '' && 'border-[#14917F]/60 bg-[#F3FBF9] font-semibold', errors.reason_key && INVALID)}
              style={{ backgroundImage: CHEVRON }}
            >
              <option value="">{reasonState === 'required' ? 'Choose a reason' : 'Not given'}</option>
              {reasons.map((item) => (
                <option key={item.key} value={item.key}>
                  {item.name}
                </option>
              ))}
              <option value={OTHER}>Other…</option>
            </select>
          )}
          <FieldError id={`${idPrefix}-reason-error`} message={errors.reason_key ?? errors.visit_reason} />
        </div>
      )}

      {visit.reason_key === OTHER && (
        <div className={full}>
          <input
            id={`${idPrefix}-reason-other`}
            aria-label="Describe the reason"
            autoFocus
            maxLength={255}
            placeholder="Describe the reason in a few words, e.g. broken retainer"
            value={visit.visit_reason}
            onChange={(event) => onVisit({ visit_reason: event.target.value })}
            aria-invalid={!!errors.visit_reason}
            className={cn(INPUT, 'motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-150', errors.visit_reason && INVALID)}
          />
        </div>
      )}

      {areas.length > 1 && doctor && (
        <div className={full}>
          <Label>What {doctor.name} is seeing them for</Label>
          <Segmented label={`What ${doctor.name} is seeing them for`} options={areas.map((option) => ({ value: option.area ?? '', label: option.area_label ?? option.module_label ?? 'General' }))} value={context?.area ?? ''} onChange={(area) => onVisit(keepReason({ area }))} />
        </div>
      )}

      {priorities.length > 1 && (
        <div className={full}>
          <Label>Priority</Label>
          <div role="radiogroup" aria-label="Priority" className="grid grid-cols-3 gap-2">
            {priorities.map((priority) => {
              const selected = (visit.priority || 'normal') === priority.value;
              const Icon = priority.icon;

              return (
                <button
                  key={priority.value}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  onClick={() => onVisit({ priority: priority.value })}
                  className={cn(
                    'inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border text-[13.5px] font-semibold [@media(pointer:coarse)]:h-11 motion-safe:transition-[background-color,border-color,color,box-shadow] motion-safe:duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#14917F]/40',
                    selected ? cn(priority.on, 'shadow-[0_4px_12px_-8px_rgba(15,42,68,0.6)]') : cn('bg-white', priority.off),
                    'motion-safe:active:scale-[0.98]',
                  )}
                >
                  {Icon && <Icon className="h-4 w-4" aria-hidden="true" />}
                  {priority.label}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

/* ------------------------------------------------------------ the fee, once */

/**
 * The consultation fee, in the footer beside the buttons it applies to: the
 * chosen practitioner's own fee from the server and when it is billed; or,
 * when the clinic charges at check-in and nobody is chosen yet, what to do.
 * Nothing when no fee applies. Never worked out here.
 */
export function FeeSummary({ config, doctor, module, noun, needsDoctor }: { config: IntakeConfig; doctor: PractitionerOption | null; module: string | null; noun: string; needsDoctor: boolean }) {
  if (!doctor) {
    return needsDoctor ? (
      <p className="inline-flex min-w-0 items-center gap-2 rounded-xl bg-[#FFF8EB] py-1 pl-1 pr-3 text-[12.5px] font-medium leading-5 text-[#92400E] ring-1 ring-[#F5DFB3] motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-200">
        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-[#FFEBC2]" aria-hidden="true">
          <IndianRupee className="h-4 w-4" />
        </span>
        Fee is charged at check-in · choose the {noun.toLowerCase()} to see it
      </p>
    ) : null;
  }
  const atCheckIn = chargedAtCheckIn(config, doctor, module);
  if (doctor.fee === null || (!atCheckIn && !config.check_in.show_fee)) {
    return null;
  }

  return (
    // A soft highlight: amber when it is charged at check-in, blue when it is billed after the visit.
    <div className={cn('inline-flex min-w-0 items-center gap-2.5 rounded-xl py-1 pl-1 pr-3 ring-1 motion-safe:animate-in motion-safe:fade-in-0 motion-safe:duration-200', atCheckIn ? 'bg-[#FFF8EB] ring-[#F5DFB3]' : 'bg-[#F3F8FD] ring-[#D7E6F5]')}>
      <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-lg', atCheckIn ? 'bg-[#FFEBC2] text-[#92400E]' : 'bg-[#E3EEFA] text-[#1769AA]')} aria-hidden="true">
        <IndianRupee className="h-4 w-4" />
      </span>
      <div className="min-w-0 leading-tight">
        <p className="text-[16px] font-bold tabular-nums text-[#0F2A44]">₹{doctor.fee.toLocaleString('en-IN')}</p>
        <p className="truncate text-[12px] text-[#64748B]">Consultation · {atCheckIn ? (config.payment.can_collect ? 'charged at check-in, collect after' : 'charged at check-in') : 'billed after the visit'}</p>
      </div>
    </div>
  );
}
