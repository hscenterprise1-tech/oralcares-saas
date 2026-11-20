import { useId, useState } from 'react';
import { Activity, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Field, Select, Textarea } from '@/clinic/ui/controls';
import { SectionCard } from './SectionCard';

export interface ModuleSpecificData {
  dental_history?: string;
  dental_treatments?: string[];
  physio_pain_area?: string;
  physio_pain_duration?: string;
  physio_mobility_limitation?: string;
  physio_notes?: string;
}

interface ModuleSpecificSectionProps {
  specialties: string[]; // e.g. ['dental', 'physiotherapy']
  data: ModuleSpecificData;
  onChange: <K extends keyof ModuleSpecificData>(field: K, value: ModuleSpecificData[K]) => void;
  errors?: Record<string, string>;
}

const COMMON_DENTAL_CONDITIONS = [
  'Tooth Sensitivity',
  'Bleeding Gums',
  'Previous Root Canal',
  'Dental Implants',
  'Orthodontic Treatment (Braces)',
  'Wisdom Tooth Pain',
  'Teeth Grinding (Bruxism)',
  'Cavities / Fillings',
];

const PHYSIO_PAIN_AREAS = [
  { value: 'knee', label: 'Knee Joint' },
  { value: 'lower_back', label: 'Lower Back / Lumbar Spine' },
  { value: 'neck', label: 'Neck / Cervical Spine' },
  { value: 'shoulder', label: 'Shoulder Joint' },
  { value: 'hip', label: 'Hip Joint' },
  { value: 'ankle_foot', label: 'Ankle / Foot' },
  { value: 'wrist_hand', label: 'Wrist / Hand' },
  { value: 'general', label: 'Multi-joint / Postural' },
];

const PHYSIO_DURATIONS = [
  { value: 'acute', label: 'Acute (< 6 weeks)' },
  { value: 'subacute', label: 'Subacute (6 - 12 weeks)' },
  { value: 'chronic', label: 'Chronic (> 3 months)' },
  { value: 'recurrent', label: 'Recurrent Episodes' },
];

const PHYSIO_LIMITATIONS = [
  { value: 'none', label: 'Normal Mobility / Mild Discomfort' },
  { value: 'walking', label: 'Difficulty Walking or Standing' },
  { value: 'bending_lifting', label: 'Difficulty Bending or Lifting' },
  { value: 'overhead_reach', label: 'Limited Overhead Shoulder Range' },
  { value: 'stairs', label: 'Difficulty Climbing Stairs' },
  { value: 'severe', label: 'Severe Limitation / Assisted Mobility' },
];

export function ModuleSpecificSection({
  specialties,
  data,
  onChange,
  errors = {},
}: ModuleSpecificSectionProps) {
  const baseId = useId();
  const hasDental = specialties.some((s) => s.toLowerCase().includes('dental') || s.toLowerCase().includes('orthodont'));
  const hasPhysio = specialties.some((s) => s.toLowerCase().includes('physio'));

  // If clinic has neither or empty specialties, don't show module section
  if (!hasDental && !hasPhysio) {
    return null;
  }

  const [activeTab, setActiveTab] = useState<'dental' | 'physio'>(
    hasDental ? 'dental' : 'physio',
  );

  const toggleDentalCondition = (condition: string) => {
    const current = data.dental_treatments ?? [];
    if (current.includes(condition)) {
      onChange(
        'dental_treatments',
        current.filter((c) => c !== condition),
      );
    } else {
      onChange('dental_treatments', [...current, condition]);
    }
  };

  return (
    <SectionCard
      id="section-specialty-info"
      title="Specialty Clinical Information"
      subtitle="Module-specific clinical notes tailored to active clinic disciplines."
      icon={Sparkles}
      tone="pink"
      badge="Specialty"
      actions={
        hasDental && hasPhysio ? (
          <div className="flex rounded-lg border border-pink-200 bg-pink-50/70 p-0.5">
            <button
              type="button"
              onClick={() => setActiveTab('dental')}
              className={cn(
                'rounded-md px-3 py-1 text-[12px] font-bold transition-all',
                activeTab === 'dental'
                  ? 'bg-pink-600 text-white shadow-sm'
                  : 'text-pink-900 hover:bg-pink-100',
              )}
            >
              Dental Care
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('physio')}
              className={cn(
                'rounded-md px-3 py-1 text-[12px] font-bold transition-all',
                activeTab === 'physio'
                  ? 'bg-pink-600 text-white shadow-sm'
                  : 'text-pink-900 hover:bg-pink-100',
              )}
            >
              Physiotherapy
            </button>
          </div>
        ) : undefined
      }
    >
      {/* Dental Module Form (NEVER visible in physio-only clinic) */}
      {hasDental && (!hasPhysio || activeTab === 'dental') && (
        <div className="space-y-4">
          <div>
            <label className="mb-2 block text-[12.5px] font-semibold text-slate-800">
              Past Dental History &amp; Treatments
            </label>
            <div className="flex flex-wrap gap-2">
              {COMMON_DENTAL_CONDITIONS.map((cond) => {
                const selected = (data.dental_treatments ?? []).includes(cond);
                return (
                  <button
                    key={cond}
                    type="button"
                    onClick={() => toggleDentalCondition(cond)}
                    className={cn(
                      'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-all',
                      selected
                        ? 'border-pink-600 bg-pink-600 text-white shadow-sm ring-2 ring-pink-300'
                        : 'border-pink-200 bg-pink-50/70 text-pink-900 hover:border-pink-300 hover:bg-pink-100/70',
                    )}
                  >
                    {cond}
                  </button>
                );
              })}
            </div>
          </div>

          <Field
            label="Relevant Dental History / Chief Notes"
            htmlFor={`${baseId}-dental-history`}
            hint="Details regarding previous dental surgeries, crowns, orthodontic progress, or sensitivity"
          >
            <Textarea
              id={`${baseId}-dental-history`}
              rows={3}
              value={data.dental_history ?? ''}
              onChange={(e) => onChange('dental_history', e.target.value)}
              placeholder="e.g. Patient had ceramic braces 3 years ago; currently experiencing localized sensitivity in upper right quadrant."
              className="text-[13.5px]"
            />
          </Field>
        </div>
      )}

      {/* Physio Module Form (NEVER visible in dental-only clinic) */}
      {hasPhysio && (!hasDental || activeTab === 'physio') && (
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field
              label="Primary Pain / Treatment Area"
              htmlFor={`${baseId}-physio-area`}
              hint="Main anatomical focus"
            >
              <Select
                id={`${baseId}-physio-area`}
                value={data.physio_pain_area ?? ''}
                onChange={(e) => onChange('physio_pain_area', e.target.value)}
                options={PHYSIO_PAIN_AREAS}
                placeholder="Select focus area"
                className="h-10 text-[14px]"
              />
            </Field>

            <Field
              label="Symptom Duration"
              htmlFor={`${baseId}-physio-duration`}
              hint="Onset timeline"
            >
              <Select
                id={`${baseId}-physio-duration`}
                value={data.physio_pain_duration ?? ''}
                onChange={(e) => onChange('physio_pain_duration', e.target.value)}
                options={PHYSIO_DURATIONS}
                placeholder="Select duration"
                className="h-10 text-[14px]"
              />
            </Field>

            <Field
              label="Mobility Limitation"
              htmlFor={`${baseId}-physio-limitation`}
              hint="Functional impairment level"
            >
              <Select
                id={`${baseId}-physio-limitation`}
                value={data.physio_mobility_limitation ?? ''}
                onChange={(e) => onChange('physio_mobility_limitation', e.target.value)}
                options={PHYSIO_LIMITATIONS}
                placeholder="Select functional impact"
                className="h-10 text-[14px]"
              />
            </Field>
          </div>

          <Field
            label="Rehabilitation Goals & Clinical Notes"
            htmlFor={`${baseId}-physio-notes`}
            hint="Functional goals, posture assessment notes, or injury history"
          >
            <Textarea
              id={`${baseId}-physio-notes`}
              rows={3}
              value={data.physio_notes ?? ''}
              onChange={(e) => onChange('physio_notes', e.target.value)}
              placeholder="e.g. Post-ACL reconstruction rehabilitation. Goal is returning to recreational badminton in 12 weeks."
              className="text-[13.5px]"
            />
          </Field>
        </div>
      )}
    </SectionCard>
  );
}
