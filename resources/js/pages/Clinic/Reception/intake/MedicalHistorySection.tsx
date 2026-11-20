import { useState } from 'react';
import {
  HeartPulse,
  Droplet,
  Droplets,
  Activity,
  Wind,
  ShieldAlert,
  Zap,
  Plus,
  X,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { IconChip, type Tone } from './IconChip';
import type { SafetyFlag } from './types';

interface MedicalHistoryData {
  conditions: Array<{ key?: string; label: string }>;
  notes?: string;
}

interface MedicalHistorySectionProps {
  data: MedicalHistoryData;
  onChange: (patch: Partial<MedicalHistoryData>) => void;
  savedSafetyFlags?: SafetyFlag[];
  isReturningPatient?: boolean;
  disabled?: boolean;
}

export const SUPPORTED_CONDITIONS: Array<{
  key: string;
  label: string;
  icon: typeof Droplet;
  tone: Tone;
  activeClass: string;
}> = [
  {
    key: 'diabetes',
    label: 'Diabetes',
    icon: Droplet,
    tone: 'violet',
    activeClass: 'border-violet-400 bg-violet-50/70 text-cx-nav-ink ring-1 ring-violet-400/50 shadow-2xs',
  },
  {
    key: 'hypertension',
    label: 'Blood Pressure',
    icon: Activity,
    tone: 'rose',
    activeClass: 'border-rose-400 bg-rose-50/70 text-cx-nav-ink ring-1 ring-rose-400/50 shadow-2xs',
  },
  {
    key: 'heart_disease',
    label: 'Heart Problem',
    icon: HeartPulse,
    tone: 'rose',
    activeClass: 'border-rose-400 bg-rose-50/70 text-cx-nav-ink ring-1 ring-rose-400/50 shadow-2xs',
  },
  {
    key: 'asthma',
    label: 'Asthma',
    icon: Wind,
    tone: 'blue',
    activeClass: 'border-blue-400 bg-blue-50/70 text-cx-nav-ink ring-1 ring-blue-400/50 shadow-2xs',
  },
  {
    key: 'thyroid_disorder',
    label: 'Thyroid',
    icon: ShieldAlert,
    tone: 'amber',
    activeClass: 'border-amber-400 bg-amber-50/70 text-cx-nav-ink ring-1 ring-amber-400/50 shadow-2xs',
  },
  {
    key: 'kidney_disease',
    label: 'Kidney Disease',
    icon: Droplets,
    tone: 'teal',
    activeClass: 'border-teal-400 bg-teal-50/70 text-cx-nav-ink ring-1 ring-teal-400/50 shadow-2xs',
  },
  {
    key: 'epilepsy',
    label: 'Epilepsy',
    icon: Zap,
    tone: 'violet',
    activeClass: 'border-purple-400 bg-purple-50/70 text-cx-nav-ink ring-1 ring-purple-400/50 shadow-2xs',
  },
  {
    key: 'other',
    label: 'Other Condition',
    icon: Plus,
    tone: 'indigo',
    activeClass: 'border-indigo-400 bg-indigo-50/70 text-cx-nav-ink ring-1 ring-indigo-400/50 shadow-2xs',
  },
];

export function MedicalHistorySection({
  data,
  onChange,
  savedSafetyFlags = [],
  isReturningPatient = false,
  disabled = false,
}: MedicalHistorySectionProps) {
  const [otherInput, setOtherInput] = useState('');

  const currentConditions = data?.conditions || [];
  const isConditionActive = (key: string) => currentConditions.some((c) => c.key === key);

  const otherConditions = currentConditions.filter(
    (c) => c.key !== 'other' && !SUPPORTED_CONDITIONS.some((item) => item.key === c.key)
  );

  const isOtherActive = isConditionActive('other') || otherConditions.length > 0;

  const toggleCondition = (key: string, label: string) => {
    if (disabled) return;
    if (key === 'other') {
      if (isOtherActive) {
        onChange({ conditions: currentConditions.filter((c) => c.key !== 'other') });
      } else {
        onChange({ conditions: [...currentConditions, { key: 'other', label: 'Other Condition' }] });
      }
      return;
    }

    if (isConditionActive(key)) {
      onChange({ conditions: currentConditions.filter((c) => c.key !== key) });
    } else {
      onChange({ conditions: [...currentConditions, { key, label }] });
    }
  };

  const addOtherCondition = () => {
    const trimmed = otherInput.trim();
    if (!trimmed || disabled) return;
    if (!currentConditions.some((c) => c.label.toLowerCase() === trimmed.toLowerCase())) {
      onChange({ conditions: [...currentConditions, { label: trimmed }] });
    }
    setOtherInput('');
  };

  const removeCondition = (label: string) => {
    if (disabled) return;
    onChange({ conditions: currentConditions.filter((c) => c.label !== label) });
  };

  return (
    <div className="space-y-3">
      {/* Section Header */}
      <div className="flex items-center justify-between border-b border-slate-100 pb-3">
        <div className="flex items-center gap-3">
          <IconChip icon={HeartPulse} tone="violet" size="md" variant="solid" />
          <div>
            <h3 className="text-[15px] font-semibold text-cx-nav-ink tracking-tight">Medical History</h3>
            <p className="text-[12px] text-cx-text-3">Tap what the patient reports · optional</p>
          </div>
        </div>
      </div>

      {/* Returning Patient Saved Safety Alerts Banner */}
      {isReturningPatient && savedSafetyFlags.length > 0 && (
        <div className="rounded-xl border border-rose-200 bg-rose-50/80 p-2.5 space-y-1.5">
          <div className="flex items-center gap-1.5 text-rose-900 font-semibold text-[11.5px]">
            <ShieldAlert className="h-3.5 w-3.5 text-rose-600" />
            <span>Recorded Medical Safety Alerts</span>
          </div>
          <div className="flex flex-wrap gap-1.5">
            {savedSafetyFlags.map((flag, idx) => (
              <span
                key={idx}
                className="inline-flex items-center rounded-md bg-white border border-rose-200 px-2 py-0.5 text-[11px] font-semibold text-rose-700 shadow-2xs"
              >
                {flag.label}
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Standard Condition Chips Grid - Responsive 2 to 4 columns */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 gap-2">
        {SUPPORTED_CONDITIONS.map((cond) => {
          const active = cond.key === 'other' ? isOtherActive : isConditionActive(cond.key);
          return (
            <button
              key={cond.key}
              type="button"
              disabled={disabled}
              onClick={() => toggleCondition(cond.key, cond.label)}
              className={cn(
                'flex h-[42px] items-center gap-2 rounded-xl border px-2.5 py-1 text-left text-[12px] font-semibold transition-all',
                active
                  ? cn(cond.activeClass, 'font-semibold')
                  : 'border-[#DCE6F3] bg-white text-slate-700 hover:border-[#B9C6D8] hover:bg-slate-100/60'
              )}
            >
              <IconChip
                icon={cond.icon}
                tone={cond.tone}
                size="xs"
                variant={active ? 'solid' : 'soft'}
              />
              <span className="truncate">{cond.label}</span>
            </button>
          );
        })}
      </div>

      {/* Other Relevant Conditions Tag Input - Conditionally Displayed */}
      {isOtherActive && (
        <div className="space-y-1.5 pt-0.5">
          <label className="block text-[12px] font-semibold text-slate-700">
            Other condition
          </label>
          <div className="flex gap-2">
            <input
              type="text"
              disabled={disabled}
              placeholder="Type condition & press Add (e.g. Hepatitis, Arthritis)"
              value={otherInput}
              onChange={(e) => setOtherInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  addOtherCondition();
                }
              }}
              className="h-11 min-w-0 flex-1 rounded-xl border border-[#DCE6F3] bg-white px-3.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20 transition-colors"
            />
            <button
              type="button"
              disabled={disabled || !otherInput.trim()}
              onClick={addOtherCondition}
              className="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-full bg-cx-violet-bg px-4 text-[13px] font-semibold text-cx-violet-ink transition-colors hover:bg-[#E4DCFD] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1769AA]/30 disabled:opacity-40"
            >
              <Plus className="h-3.5 w-3.5" />
              <span>Add</span>
            </button>
          </div>

          {/* Display Added Other Conditions */}
          {otherConditions.length > 0 && (
            <div className="flex flex-wrap gap-1.5 pt-0.5">
              {otherConditions.map((c) => (
                <span
                  key={c.label}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-purple-200 bg-purple-50/80 px-2.5 py-0.5 text-[11px] font-semibold text-purple-800"
                >
                  <span>{c.label}</span>
                  <button
                    type="button"
                    disabled={disabled}
                    onClick={() => removeCondition(c.label)}
                    className="text-purple-500 hover:text-purple-800"
                  >
                    <X className="h-3 w-3" />
                  </button>
                </span>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Additional Medical History / Remarks Field */}
      <div className="space-y-1 pt-0.5">
        <label className="block text-[12px] font-semibold text-slate-700">
          Medical history notes <span className="text-[#64748B] font-normal">· optional</span>
        </label>
        <textarea
          rows={2}
          disabled={disabled}
          placeholder="Precautions or ongoing health details"
          value={data.notes || ''}
          onChange={(e) => onChange({ notes: e.target.value })}
          className="w-full resize-none rounded-xl border border-[#DCE6F3] bg-white px-3.5 py-2.5 text-[13.5px] text-cx-nav-ink placeholder:text-[#64748B] hover:border-[#B9C6D8] focus:border-[#1769AA] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#1769AA]/20 transition-colors"
        />
      </div>
    </div>
  );
}
