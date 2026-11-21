import { useEffect } from 'react';
import { Stethoscope, ClipboardList, AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { IconChip } from './IconChip';
import { contextOf, reasonsFor, type IntakeConfig, type VisitForm } from './types';

interface VisitComplaintSectionProps {
  config?: IntakeConfig | null;
  value: VisitForm;
  onChange: (patch: Partial<VisitForm>) => void;
  errors?: Partial<Record<keyof VisitForm, string>>;
  disabled?: boolean;
  hideDoctor?: boolean;
  hidePriority?: boolean;
}

const OTHER = 'other';

const PRIORITIES = [
  { value: 'normal', label: 'Normal', color: 'border-[#DCE6F3] bg-slate-50/60 text-slate-700 hover:bg-slate-100/60' },
  { value: 'urgent', label: 'Urgent', color: 'border-amber-200/90 bg-amber-50/60 text-amber-800 hover:bg-amber-100/60' },
  { value: 'emergency', label: 'Emergency', color: 'border-rose-200/90 bg-rose-50/60 text-rose-700 hover:bg-rose-100/60' },
];

export function VisitComplaintSection({
  config,
  value,
  onChange,
  errors = {},
  disabled = false,
  hideDoctor = false,
  hidePriority = false,
}: VisitComplaintSectionProps) {
  // Determine if active module is Dental or Physio
  const currentSpecialty = value.specialty || config?.specialties?.[0]?.key || '';
  const isPhysio = currentSpecialty.toLowerCase().includes('physio');
  // The clinic's own reasons for this specialty (a physiotherapy visit: the owner's problems, with the side), so the
  // problem chosen here reaches the therapist already suggested. The server checks the choice again.
  // The chosen doctor's practice area narrows what the server accepts, so it narrows the list too: a reason shown here is never refused later.
  const doctorArea = config ? (contextOf(config, value)?.area ?? null) : null;
  const reasons = config ? reasonsFor(config, currentSpecialty, doctorArea) : [];

  // Doctors available for this branch and specialty
  const availableDoctors = (config?.doctors || []).filter((doc) => {
    if (!currentSpecialty) return true;
    return doc.contexts.some((ctx) => !ctx.specialty || ctx.specialty === currentSpecialty);
  });

  // Auto-select doctor only when doctor selection is enabled
  useEffect(() => {
    if (!hideDoctor && !value.doctor_id && availableDoctors.length > 0) {
      const firstOnShift = availableDoctors.find((d) => d.shift === 'on_shift') || availableDoctors[0];
      if (firstOnShift) {
        onChange({ doctor_id: String(firstOnShift.id) });
      }
    }
  }, [availableDoctors, value.doctor_id, hideDoctor, onChange]);

  // However the doctor came to change (chosen, or picked for the desk above), a reason the doctor's area does not offer is dropped.
  useEffect(() => {
    if (config && value.reason_key && value.reason_key !== OTHER && !reasons.some((item) => item.key === value.reason_key)) {
      onChange({ reason_key: '', visit_reason: '' });
    }
  }, [reasons, value.reason_key]); // eslint-disable-line react-hooks/exhaustive-deps

  const selectedDoctor = availableDoctors.find((d) => String(d.id) === value.doctor_id) || null;

  const isOther = value.reason_key === OTHER;
  const selectedReason = isOther || reasons.some((item) => item.key === value.reason_key) ? value.reason_key : '';

  return (
    <div className="space-y-4">
      {/* Section header, as Patient Details and Medical History open theirs (the Owner Dashboard's tile, title and caption). */}
      <div className="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
        <div className="flex min-w-0 items-center gap-3">
          <IconChip icon={ClipboardList} tone="teal" size="md" variant="solid" />
          <div className="min-w-0">
            <h3 className="text-[15px] font-semibold tracking-tight text-cx-nav-ink">Visit Complaint</h3>
            <p className="text-[12px] text-cx-text-3">Why the patient came today</p>
          </div>
        </div>
        <span className="shrink-0 rounded-full bg-cx-teal-bg px-2.5 py-0.5 text-[11.5px] font-semibold text-cx-teal-ink">{isPhysio ? 'Physiotherapy' : 'Dental'}</span>
      </div>

      {/* Specialty Selection if Multi-Specialty Clinic */}
      {config?.specialties && config.specialties.length > 1 && (
        <div>
          <label className="block text-[12px] font-semibold text-slate-700 mb-1.5">
            Care Specialty / Department <span className="text-rose-500">*</span>
          </label>
          <div className="flex flex-wrap gap-2">
            {config.specialties.map((spec) => {
              const active = currentSpecialty === spec.key;
              return (
                <button
                  key={spec.key}
                  type="button"
                  disabled={disabled}
                  onClick={() =>
                    onChange({
                      specialty: spec.key,
                      reason_key: '',
                      doctor_id: '',
                    })
                  }
                  className={cn(
                    'min-h-10 rounded-full border px-4 text-[13px] font-semibold motion-safe:transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/30',
                    active
                      ? 'border-[#0E9F87]/50 bg-cx-teal-bg text-cx-teal-ink'
                      : 'border-[#DCE6F3] bg-white text-cx-text-2 hover:border-[#B9C6D8] hover:text-cx-nav-ink'
                  )}
                >
                  {spec.label}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* Visit Complaint / Reason Dropdown */}
      <div>
        <label className="mb-1.5 block text-[12px] font-semibold leading-none text-slate-700">
          Complaint / reason <span className="text-rose-500">*</span>
        </label>

        <select
          disabled={disabled}
          value={selectedReason}
          onChange={(e) => {
            const chosen = e.target.value;
            // A listed reason is stored by its key with its name; "Other" takes the words typed below.
            onChange({ reason_key: chosen, visit_reason: chosen === OTHER ? '' : (reasons.find((item) => item.key === chosen)?.name ?? '') });
          }}
          className={cn(
            'h-[50px] w-full rounded-xl border bg-white px-3.5 text-[13.5px] font-semibold text-cx-nav-ink transition-colors focus:bg-white focus:outline-none focus:ring-2',
            errors.visit_reason
              ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
              : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
          )}
        >
          <option value="" disabled>
            Select primary {isPhysio ? 'physio' : 'dental'} complaint...
          </option>
          {reasons.map((item) => (
            <option key={item.key} value={item.key}>
              {item.name}
            </option>
          ))}
          <option value={OTHER}>Other…</option>
        </select>

        {isOther && (
          <div className="mt-2">
            <input
              type="text"
              disabled={disabled}
              placeholder={isPhysio ? 'What brings the patient in (e.g. pain after a fall)' : 'What brings the patient in (e.g. pain on chewing)'}
              value={value.visit_reason}
              onChange={(e) => onChange({ visit_reason: e.target.value })}
              className="h-[50px] w-full rounded-xl border border-[#DCE6F3] bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20 transition-colors"
            />
          </div>
        )}
        {errors.visit_reason && (
          <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.visit_reason}</p>
        )}
      </div>

      {/* Attending Doctor Selection (Only shown when not hidden) */}
      {!hideDoctor && (
        <div>
          <div className="flex items-center justify-between mb-1.5">
            <label className="inline-flex items-center gap-2 text-[12px] font-semibold text-slate-700">
              <IconChip icon={Stethoscope} tone="blue" size="xs" variant="soft" />
              <span>Attending Doctor</span>
              <span className="text-rose-500">*</span>
            </label>
            {selectedDoctor && (
              <span className="text-[11px] font-semibold text-teal-700">
                {selectedDoctor.shift === 'on_shift' ? '● On Shift Now' : selectedDoctor.shift === 'off_shift' ? selectedDoctor.shift_label : 'Scheduled Today'}
              </span>
            )}
          </div>

          {availableDoctors.length > 0 ? (
            <select
              disabled={disabled}
              value={value.doctor_id}
              onChange={(e) => {
                const next = config ? contextOf(config, { ...value, doctor_id: e.target.value, area: '' })?.area ?? null : null;
                const stillOffered = !value.reason_key || value.reason_key === OTHER || (config ? reasonsFor(config, currentSpecialty, next) : []).some((item) => item.key === value.reason_key);
                // A reason this doctor's area does not offer is cleared, not carried into a refusal.
                onChange({ doctor_id: e.target.value, area: '', ...(stillOffered ? {} : { reason_key: '', visit_reason: '' }) });
              }}
              className={cn(
                'h-9.5 w-full rounded-xl border bg-white px-3 text-[13px] font-semibold text-cx-nav-ink transition-colors focus:bg-white focus:outline-none focus:ring-2',
                errors.doctor_id
                  ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'
                  : 'border-[#DCE6F3] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:ring-[#1769AA]/20'
              )}
            >
              <option value="" disabled>
                Select attending doctor...
              </option>
              {availableDoctors.map((doc) => {
                const specName = doc.contexts.map((c) => c.specialty).filter(Boolean).join(', ');
                const feeText = doc.fee !== null && doc.fee > 0 ? ` · ₹${doc.fee}` : '';
                return (
                  <option key={doc.id} value={String(doc.id)}>
                    {doc.name} {specName ? `(${specName})` : ''} {feeText}{doc.shift === 'off_shift' && doc.shift_label ? ` · ${doc.shift_label}` : ''}
                  </option>
                );
              })}
            </select>
          ) : (
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-2.5 text-[12px] text-amber-800">
              No doctors currently scheduled for this specialty{config?.branch?.name ? ` at ${config.branch.name}` : ''}.
            </div>
          )}
          {errors.doctor_id && (
            <p className="mt-1 text-[11px] font-medium text-rose-600">{errors.doctor_id}</p>
          )}
        </div>
      )}

      {/* Queue Priority (Only shown when not hidden) */}
      {!hidePriority && (
        <div>
          <label className="inline-flex items-center gap-2 text-[12px] font-semibold text-slate-700 mb-1.5">
            <IconChip icon={AlertCircle} tone="amber" size="xs" variant="soft" />
            <span>Queue Priority</span>
          </label>
          <div className="grid grid-cols-3 gap-2">
            {PRIORITIES.map((p) => {
              const active = (value.priority || 'normal') === p.value;
              return (
                <button
                  key={p.value}
                  type="button"
                  disabled={disabled}
                  onClick={() => onChange({ priority: p.value })}
                  className={cn(
                    'h-9.5 rounded-xl text-center text-[12.5px] font-semibold border transition-all',
                    active
                      ? 'border-teal-500 bg-teal-50 text-teal-800 ring-2 ring-teal-500/20 shadow-2xs'
                      : p.color
                  )}
                >
                  {p.label}
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}
