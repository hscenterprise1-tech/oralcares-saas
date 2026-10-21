import { useEffect, useRef } from 'react';
import { AlertTriangle, IndianRupee, Siren, Sparkles } from 'lucide-react';
import { ContextBadge, contextForSpecialty, Input, Select } from '@/clinic';
import { cn } from '@/lib/utils';
import { ChoiceChips, chipClass, contextLabel } from '@/clinic';
import { contextOf, feeTiming, forSpecialty, reasonsFor, visitSpecialty, type IntakeConfig, type PractitionerOption, type VisitForm } from './types';

export type VisitErrors = Partial<Record<keyof VisitForm, string>>;

const OTHER = 'other';
/** Up to this many reasons show as chips (one tap); a longer list is a dropdown. */
const REASON_CHIPS_MAX = 8;

/** The doctors who can take a visit for this specialty here ('' — not yet chosen — means all of today's). */
export function doctorsFor(config: IntakeConfig, specialty: string): PractitionerOption[] {
  return specialty === '' ? config.doctors : config.doctors.filter((doctor) => doctor.contexts.some((context) => context.specialty === specialty));
}

/**
 * Doctor & reason, the same in both flows, kept compact:
 *
 * - A clinic practising several specialties (Dental and Physiotherapy)
 *   first says what the visit is for, as two or three chips; the doctors
 *   and reasons offered are then that module's only. A one-specialty
 *   clinic never sees the choice.
 * - The doctor is a dropdown of today's practitioners who can take that
 *   visit here ("Next available" leaves it to the queue). When exactly one
 *   can, they are chosen for you; reception can still pick "Next
 *   available". Choosing a doctor decides the clinical context
 *   (Dental · Orthodontics).
 * - The reason is the patient's words for why they came — chips for a
 *   short list, a dropdown for a long one, "Other" for anything else. It is
 *   never a diagnosis; the clinician assesses in the consultation.
 *
 * The server resolves and checks all of it again (`ClinicalContextResolver`).
 */
export function VisitFields({
  config,
  value,
  onChange,
  errors,
  idPrefix,
  showPriority = false,
}: {
  config: IntakeConfig;
  value: VisitForm;
  onChange: (patch: Partial<VisitForm>) => void;
  errors: VisitErrors;
  idPrefix: string;
  showPriority?: boolean;
}) {
  const doctor = config.doctors.find((option) => String(option.id) === value.doctor_id) ?? null;
  const context = contextOf(config, value);
  const specialty = visitSpecialty(config, value);
  const reasons = reasonsFor(config, specialty, context?.area ?? null);
  const reasonState = config.check_in.visit_reason;
  const multiModule = config.specialties.length > 1;
  // The module the doctor list is narrowed to: the one chosen, else the chosen doctor's.
  const moduleKey = value.specialty !== '' ? value.specialty : multiModule ? '' : (config.specialties[0]?.key ?? '');
  const eligible = doctorsFor(config, multiModule ? moduleKey : '');
  // Reception chose "Next available" (or another doctor) by hand: never choose for them again.
  const doctorTouched = useRef(false);

  // A new context keeps the reason only if it is still offered there.
  const keepReason = (patch: Partial<VisitForm>): Partial<VisitForm> => {
    const next = { ...value, ...patch };
    const offered = reasonsFor(config, visitSpecialty(config, next), contextOf(config, next)?.area ?? null);
    const keep = value.reason_key === OTHER || offered.some((item) => item.key === value.reason_key);

    return { ...patch, reason_key: keep ? value.reason_key : '', visit_reason: keep ? value.visit_reason : '' };
  };

  // Exactly one doctor can take this visit here today: choose them (only from the server's own list).
  const onlyDoctor = eligible.length === 1 && (!multiModule || moduleKey !== '') ? eligible[0] : null;
  useEffect(() => {
    if (onlyDoctor && value.doctor_id === '' && !doctorTouched.current) {
      onChange(keepReason({ doctor_id: String(onlyDoctor.id), area: '' }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [onlyDoctor?.id, value.doctor_id]);

  const chooseModule = (next: string) => {
    // A doctor who cannot take a visit for the new module is let go.
    const keepDoctor = doctor && doctor.contexts.some((option) => option.specialty === next);
    onChange(keepReason({ specialty: next, ...(keepDoctor ? {} : { doctor_id: '', area: '' }) }));
  };
  const chooseDoctor = (doctorId: string) => {
    doctorTouched.current = true;
    const chosen = config.doctors.find((option) => String(option.id) === doctorId);
    const firstContext = chosen?.contexts.find((option) => !multiModule || moduleKey === '' || option.specialty === moduleKey) ?? chosen?.contexts[0];
    onChange(keepReason({ doctor_id: doctorId, area: firstContext?.area ?? '', ...(multiModule && firstContext?.specialty ? { specialty: firstContext.specialty } : {}) }));
  };
  // The chosen doctor's practice areas within the module (a dentist-physio is not offered Physio areas for a Dental visit).
  const areaChoices = doctor ? doctor.contexts.filter((option) => !multiModule || moduleKey === '' || option.specialty === moduleKey) : [];
  const doctorLabel = (option: PractitionerOption) =>
    [option.name, option.credentials, multiModule && moduleKey === '' ? option.module?.label : option.area?.label, option.shift === 'not_set' ? null : option.shift_label].filter(Boolean).join(' · ');

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {multiModule && (
        <div className="sm:col-span-2">
          <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Visit for</p>
          <ChoiceChips
            label="Visit for"
            id={`${idPrefix}-specialty`}
            options={config.specialties.map((option) => ({ value: option.key, label: option.label }))}
            value={moduleKey}
            onChange={chooseModule}
            invalid={!!errors.specialty}
            describedBy={errors.specialty ? `${idPrefix}-specialty-error` : undefined}
          />
          {errors.specialty && (
            <p id={`${idPrefix}-specialty-error`} role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
              {errors.specialty}
            </p>
          )}
        </div>
      )}

      <div className="sm:col-span-2">
        <label htmlFor={`${idPrefix}-doctor`} className="mb-1.5 block text-[12px] font-semibold text-clinic-ink">
          Doctor
        </label>
        {config.doctors.length === 0 ? (
          <p className="rounded-xl bg-clinic-inset/60 px-3 py-2.5 text-[12.5px] text-clinic-secondary">No doctor is on shift here today; the queue will assign one.</p>
        ) : eligible.length === 0 ? (
          <p className="rounded-xl bg-clinic-inset/60 px-3 py-2.5 text-[12.5px] text-clinic-secondary">No doctor for this is on shift here today; the queue will assign one.</p>
        ) : (
          <Select
            id={`${idPrefix}-doctor`}
            value={value.doctor_id}
            onChange={(event) => chooseDoctor(event.target.value)}
            options={eligible.map((option) => ({ value: String(option.id), label: doctorLabel(option) }))}
            placeholder="Next available doctor"
            invalid={!!errors.doctor_id}
            aria-describedby={errors.doctor_id ? `${idPrefix}-doctor-error` : undefined}
          />
        )}
        {errors.doctor_id && (
          <p id={`${idPrefix}-doctor-error`} role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
            {errors.doctor_id}
          </p>
        )}
      </div>

      {doctor && context && (
        <div className="sm:col-span-2" aria-live="polite">
          <p className="flex flex-wrap items-center gap-2 rounded-xl bg-clinic-inset/60 px-3 py-2 text-[12.5px] text-clinic-ink">
            <Sparkles className="h-3.5 w-3.5 text-clinic-secondary" aria-hidden="true" />
            <span className="font-semibold">Visit for</span>
            {contextLabel(context) && <ContextBadge context={contextForSpecialty(context.module)} label={contextLabel(context)} />}
            <span className="text-clinic-secondary">with {doctor.name}</span>
            {doctor.room && <span className="text-clinic-secondary">· {doctor.room}</span>}
            {config.check_in.show_fee && doctor.fee !== null && (
              <span className="ml-auto inline-flex items-center gap-0.5 font-semibold text-clinic-ink">
                <IndianRupee className="h-3 w-3" aria-hidden="true" />
                {doctor.fee.toLocaleString('en-IN')}
                <span className="font-normal text-clinic-secondary">&nbsp;consultation, {feeTiming(config, doctor, context?.module ?? null)}</span>
              </span>
            )}
          </p>
          {areaChoices.length > 1 && (
            <div className="mt-2">
              <ChoiceChips
                label={`What ${doctor.name} is seeing them for`}
                id={`${idPrefix}-area`}
                options={areaChoices.map((option) => ({ value: option.area ?? '', label: option.area_label ?? option.module_label ?? 'General' }))}
                value={context.area ?? ''}
                onChange={(area) => onChange(keepReason({ area }))}
              />
            </div>
          )}
        </div>
      )}

      {/* Reasons wait for the module: a mixed list across specialties is never offered. */}
      {reasonState !== 'hidden' && !(multiModule && specialty === '') && (
        <div className="sm:col-span-2">
          <label htmlFor={`${idPrefix}-reason`} className="mb-1.5 block text-[12px] font-semibold text-clinic-ink">
            Reason for visit
            {reasonState === 'required' && <span className="text-clinic-danger"> *</span>}
            <span className="font-medium text-clinic-secondary"> · as the patient says it</span>
          </label>
          {reasons.length <= REASON_CHIPS_MAX ? (
            <ChoiceChips
              label="Reason for visit"
              id={`${idPrefix}-reason`}
              options={[...reasons.map((item) => ({ value: item.key, label: item.name })), { value: OTHER, label: 'Other' }]}
              value={value.reason_key}
              onChange={(reason_key) => onChange({ reason_key, visit_reason: reason_key === OTHER ? value.visit_reason : '' })}
              invalid={!!errors.reason_key}
              describedBy={errors.reason_key || errors.visit_reason ? `${idPrefix}-reason-error` : undefined}
            />
          ) : (
            <Select
              id={`${idPrefix}-reason`}
              value={value.reason_key}
              onChange={(event) => onChange({ reason_key: event.target.value, visit_reason: event.target.value === OTHER ? value.visit_reason : '' })}
              options={[...reasons.map((item) => ({ value: item.key, label: item.name })), { value: OTHER, label: 'Other…' }]}
              placeholder={reasonState === 'required' ? 'Choose a reason' : 'Not given'}
              invalid={!!errors.reason_key}
              aria-describedby={errors.reason_key || errors.visit_reason ? `${idPrefix}-reason-error` : undefined}
            />
          )}
          {value.reason_key === OTHER && (
            <div className="mt-2 max-w-md">
              <Input
                id={`${idPrefix}-visit_reason`}
                data-autofocus
                aria-label="Describe the reason"
                value={value.visit_reason}
                onChange={(event) => onChange({ visit_reason: event.target.value })}
                invalid={!!errors.visit_reason}
                maxLength={255}
                placeholder="A few words, e.g. broken retainer"
                autoComplete="off"
              />
            </div>
          )}
          {(errors.reason_key || errors.visit_reason) && (
            <p id={`${idPrefix}-reason-error`} role="alert" className="mt-1.5 text-[12px] font-medium text-clinic-danger">
              {errors.reason_key ?? errors.visit_reason}
            </p>
          )}
        </div>
      )}

      {showPriority && <PriorityField config={config} value={value.priority} onChange={(priority) => onChange({ priority })} idPrefix={idPrefix} />}
    </div>
  );
}

/**
 * Normal, urgent and (if the clinic uses it) emergency, as words and icons —
 * never colour alone. Operational triage at the desk, not a diagnosis.
 */
export function PriorityField({ config, value, onChange, idPrefix }: { config: IntakeConfig; value: string; onChange: (value: string) => void; idPrefix: string }) {
  if (!config.check_in.priority || config.priorities.length < 2) {
    return null;
  }

  return (
    <div className="sm:col-span-2">
      <p className="mb-1.5 text-[12px] font-semibold text-clinic-ink">Priority</p>
      <div role="radiogroup" aria-label="Priority" id={`${idPrefix}-priority`} className="flex flex-wrap gap-2">
        {config.priorities.map((priority) => {
          const selected = value === priority.value;
          const Icon = priority.value === 'emergency' ? Siren : priority.value === 'urgent' ? AlertTriangle : null;

          return (
            <button
              key={priority.value}
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => onChange(priority.value)}
              className={cn(
                chipClass(selected),
                selected && priority.value === 'emergency' && 'border-rose-700 bg-rose-700',
                selected && priority.value === 'urgent' && 'border-amber-600 bg-amber-600',
              )}
            >
              {Icon && <Icon className="h-3.5 w-3.5" aria-hidden="true" />}
              {priority.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

export function validateVisit(config: IntakeConfig, value: VisitForm): VisitErrors {
  const errors: VisitErrors = {};
  if (value.doctor_id === '' && config.specialties.length > 1 && value.specialty === '') {
    errors.specialty = 'Choose the doctor, or what the visit is for.';
  }
  if (config.check_in.visit_reason === 'required' && value.reason_key === '') {
    errors.reason_key = 'Choose the reason for today’s visit.';
  }
  if (value.reason_key === OTHER && value.visit_reason.trim() === '') {
    errors.visit_reason = 'Say what the visit is for.';
  }

  return errors;
}

/**
 * A fresh visit. One specialty here: no choice to make. Several: the one the
 * dialog was opened for (a module's own page), else the preselected doctor's,
 * else reception chooses. Only what the server offered is ever preselected.
 */
export function emptyVisit(config: IntakeConfig | null, doctorId = '', specialtyKey = ''): VisitForm {
  const doctor = config?.doctors.find((option) => String(option.id) === doctorId) ?? null;
  const offered = (key: string | null | undefined) => !!key && !!config?.specialties.some((option) => option.key === key);
  const specialty =
    config && config.specialties.length === 1
      ? config.specialties[0].key
      : offered(specialtyKey)
        ? specialtyKey
        : (doctor?.contexts.find((context) => offered(context.specialty))?.specialty ?? '');
  // A preselected doctor who cannot take a visit for the module asked for is not kept.
  const keepDoctor = doctor && (specialty === '' || config!.specialties.length === 1 || doctor.contexts.some((context) => context.specialty === specialty));

  return { specialty, doctor_id: keepDoctor ? String(doctor.id) : '', area: '', visit_type: '', reason_key: '', visit_reason: '', priority: 'normal' };
}

/**
 * What check-in takes for today's visit. With a doctor, only which of their
 * areas; the server derives the specialty from them. Without, the specialty
 * chosen by hand.
 */
export function visitPayload(config: IntakeConfig, value: VisitForm): Record<string, unknown> {
  const context = contextOf(config, value);
  const reasonShown = config.check_in.visit_reason !== 'hidden';

  return {
    visit_area: context?.area ?? null,
    visit_specialty: value.doctor_id === '' ? value.specialty || null : null,
    visit_reason_key: reasonShown ? value.reason_key || null : null,
    visit_reason: reasonShown && value.reason_key === OTHER ? value.visit_reason.trim() || null : null,
    priority: config.check_in.priority ? value.priority : 'normal',
  };
}

/** The reason as the summary shows it: the catalogue name, or the words typed for "Other". */
export function reasonLabel(config: IntakeConfig, value: VisitForm): string | null {
  if (value.reason_key === OTHER) {
    return value.visit_reason.trim() || 'Other';
  }

  return forSpecialty(config.catalogue.visit_reasons, '').find((item) => item.key === value.reason_key)?.name ?? null;
}
