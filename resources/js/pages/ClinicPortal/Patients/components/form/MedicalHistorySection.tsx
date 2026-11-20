import { useId, useState } from 'react';
import { AlertCircle, Check, HeartPulse, Plus, ShieldAlert, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Checkbox, Field, Input, Textarea } from '@/clinic';
import { SectionCard } from './SectionCard';

export interface MedicalChoice {
  key?: string;
  label: string;
}

export interface MedicalHistoryData {
  conditions: MedicalChoice[];
  allergies: MedicalChoice[];
  allergy_none: boolean;
  medications: MedicalChoice[];
  medical_notes: string;
}

interface MedicalHistorySectionProps {
  data: MedicalHistoryData;
  onChange: <K extends keyof MedicalHistoryData>(field: K, value: MedicalHistoryData[K]) => void;
  catalogueConditions?: { key: string; name: string; category?: string | null }[];
  catalogueAllergies?: { key: string; name: string }[];
  errors: Partial<Record<string, string>>;
}

const COMMON_CONDITIONS: { label: string; key: string }[] = [
  { label: 'Diabetes', key: 'diabetes' },
  { label: 'High BP', key: 'hypertension' },
  { label: 'Heart Problem', key: 'heart_disease' },
  { label: 'Allergy', key: 'allergy' },
  { label: 'Asthma', key: 'asthma' },
];

export function MedicalHistorySection({
  data,
  onChange,
  catalogueConditions = [],
  catalogueAllergies = [],
  errors,
}: MedicalHistorySectionProps) {
  const baseId = useId();
  const [customCondition, setCustomCondition] = useState('');
  const [customMedication, setCustomMedication] = useState('');
  const [customAllergy, setCustomAllergy] = useState('');

  const isConditionSelected = (labelOrKey: string) =>
    data.conditions.some(
      (c) =>
        c.key?.toLowerCase() === labelOrKey.toLowerCase() ||
        c.label.toLowerCase() === labelOrKey.toLowerCase(),
    );

  const toggleCommonCondition = (item: { label: string; key: string }) => {
    if (isConditionSelected(item.key) || isConditionSelected(item.label)) {
      onChange(
        'conditions',
        data.conditions.filter(
          (c) =>
            c.key?.toLowerCase() !== item.key.toLowerCase() &&
            c.label.toLowerCase() !== item.label.toLowerCase(),
        ),
      );
    } else {
      onChange('conditions', [...data.conditions, { key: item.key, label: item.label }]);
    }
  };

  const addCustomCondition = () => {
    const trimmed = customCondition.trim();
    if (!trimmed) return;
    if (!data.conditions.some((c) => c.label.toLowerCase() === trimmed.toLowerCase())) {
      onChange('conditions', [...data.conditions, { label: trimmed }]);
    }
    setCustomCondition('');
  };

  const removeCondition = (index: number) => {
    onChange(
      'conditions',
      data.conditions.filter((_, idx) => idx !== index),
    );
  };

  const addMedication = () => {
    const trimmed = customMedication.trim();
    if (!trimmed) return;
    if (!data.medications.some((m) => m.label.toLowerCase() === trimmed.toLowerCase())) {
      onChange('medications', [...data.medications, { label: trimmed }]);
    }
    setCustomMedication('');
  };

  const removeMedication = (index: number) => {
    onChange(
      'medications',
      data.medications.filter((_, idx) => idx !== index),
    );
  };

  const addAllergy = (allergyName: string) => {
    const trimmed = allergyName.trim();
    if (!trimmed) return;
    if (!data.allergies.some((a) => a.label.toLowerCase() === trimmed.toLowerCase())) {
      onChange('allergies', [...data.allergies, { label: trimmed }]);
      onChange('allergy_none', false);
    }
    setCustomAllergy('');
  };

  const removeAllergy = (index: number) => {
    onChange(
      'allergies',
      data.allergies.filter((_, idx) => idx !== index),
    );
  };

  return (
    <SectionCard
      id="section-medical-history"
      title="Medical History & Safety"
      subtitle="Pre-existing clinical conditions, drug allergies and current medications."
      icon={ShieldAlert}
      tone="lavender"
      badge="Safety"
    >
      <div className="space-y-6">
        {/* Common Medical Condition Quick Chips */}
        <div>
          <label className="mb-2 block text-[12.5px] font-semibold text-slate-800">
            Common Conditions (Quick Selection)
          </label>
          <div className="flex flex-wrap gap-2">
            {COMMON_CONDITIONS.map((cond) => {
              const selected = isConditionSelected(cond.key) || isConditionSelected(cond.label);
              return (
                <button
                  key={cond.key}
                  type="button"
                  onClick={() => toggleCommonCondition(cond)}
                  className={cn(
                    'inline-flex items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-[13px] font-semibold transition-all duration-150',
                    selected
                      ? 'border-purple-600 bg-purple-600 text-white shadow-sm ring-2 ring-purple-300'
                      : 'border-purple-200 bg-purple-50/70 text-purple-900 hover:border-purple-300 hover:bg-purple-100/70',
                  )}
                >
                  {selected && <Check className="h-3.5 w-3.5 stroke-[2.5]" aria-hidden="true" />}
                  {cond.label}
                </button>
              );
            })}
          </div>
          {errors.conditions && (
            <p className="mt-1.5 text-[12px] font-medium text-rose-600">{errors.conditions}</p>
          )}
        </div>

        {/* Selected Conditions List & Custom Add */}
        <div className="rounded-xl border border-slate-100 bg-slate-50/60 p-3.5">
          <label className="mb-2 block text-[12px] font-bold uppercase tracking-wider text-slate-600">
            Recorded Conditions &amp; Diagnoses ({data.conditions.length})
          </label>

          {data.conditions.length > 0 ? (
            <div className="mb-3 flex flex-wrap gap-1.5">
              {data.conditions.map((item, index) => (
                <span
                  key={`${item.label}-${index}`}
                  className="inline-flex items-center gap-1 rounded-full bg-purple-100 px-3 py-1 text-[12.5px] font-semibold text-purple-900"
                >
                  {item.label}
                  <button
                    type="button"
                    onClick={() => removeCondition(index)}
                    aria-label={`Remove ${item.label}`}
                    className="ml-0.5 rounded-full p-0.5 text-purple-600 hover:bg-purple-200 hover:text-purple-900"
                  >
                    <X className="h-3 w-3" />
                  </button>
                </span>
              ))}
            </div>
          ) : (
            <p className="mb-3 text-[12.5px] italic text-slate-500">
              No medical conditions currently recorded for this patient.
            </p>
          )}

          {/* Add condition input */}
          <div className="flex gap-2">
            <Input
              id={`${baseId}-custom-condition`}
              value={customCondition}
              onChange={(e) => setCustomCondition(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  addCustomCondition();
                }
              }}
              placeholder="Type additional condition (e.g. Thyroid, Kidney Disease, Epilepsy)…"
              className="h-9 text-[13.5px]"
            />
            <button
              type="button"
              onClick={addCustomCondition}
              className="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg border border-purple-300 bg-purple-50 px-3 text-[13px] font-semibold text-purple-800 hover:bg-purple-100"
            >
              <Plus className="h-3.5 w-3.5" />
              Add
            </button>
          </div>
        </div>

        {/* Allergies Section */}
        <div>
          <div className="flex items-center justify-between gap-2">
            <label className="text-[12.5px] font-semibold text-slate-800">
              Allergies (Drug / Food / Environment)
            </label>
            <Checkbox
              checked={data.allergy_none}
              disabled={data.allergies.length > 0}
              onCheckedChange={(checked: boolean) => {
                onChange('allergy_none', checked);
                if (checked) {
                  onChange('allergies', []);
                }
              }}
              label="No known allergies"
            />
          </div>

          {!data.allergy_none ? (
            <div className="mt-2 space-y-2">
              {data.allergies.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                  {data.allergies.map((allergy, index) => (
                    <span
                      key={`${allergy.label}-${index}`}
                      className="inline-flex items-center gap-1 rounded-full bg-rose-100 px-3 py-1 text-[12.5px] font-semibold text-rose-900"
                    >
                      {allergy.label}
                      <button
                        type="button"
                        onClick={() => removeAllergy(index)}
                        aria-label={`Remove ${allergy.label}`}
                        className="ml-0.5 rounded-full p-0.5 text-rose-600 hover:bg-rose-200 hover:text-rose-900"
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </span>
                  ))}
                </div>
              )}

              <div className="flex gap-2">
                <Input
                  id={`${baseId}-allergy`}
                  value={customAllergy}
                  onChange={(e) => setCustomAllergy(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      addAllergy(customAllergy);
                    }
                  }}
                  placeholder="e.g. Penicillin, Latex, NSAIDs, Sulfa drugs…"
                  className="h-9 text-[13.5px]"
                />
                <button
                  type="button"
                  onClick={() => addAllergy(customAllergy)}
                  className="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg border border-rose-300 bg-rose-50 px-3 text-[13px] font-semibold text-rose-800 hover:bg-rose-100"
                >
                  <Plus className="h-3.5 w-3.5" />
                  Add
                </button>
              </div>
            </div>
          ) : (
            <p className="mt-1.5 rounded-lg bg-emerald-50 px-3 py-2 text-[12.5px] font-medium text-emerald-800">
              ✓ Confirmed: Patient has no known drug or medical allergies.
            </p>
          )}
          {errors.allergies && (
            <p className="mt-1 text-[12px] font-medium text-rose-600">{errors.allergies}</p>
          )}
        </div>

        {/* Current Medications */}
        <div>
          <Field
            label="Current Medications"
            htmlFor={`${baseId}-medications`}
            hint="Regular prescribed medicines or supplements the patient currently takes"
          >
            {data.medications.length > 0 && (
              <div className="mb-2 flex flex-wrap gap-1.5">
                {data.medications.map((med, index) => (
                  <span
                    key={`${med.label}-${index}`}
                    className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-[12.5px] font-semibold text-slate-800"
                  >
                    {med.label}
                    <button
                      type="button"
                      onClick={() => removeMedication(index)}
                      className="ml-0.5 rounded-full p-0.5 text-slate-500 hover:bg-slate-200 hover:text-slate-800"
                    >
                      <X className="h-3 w-3" />
                    </button>
                  </span>
                ))}
              </div>
            )}
            <div className="flex gap-2">
              <Input
                id={`${baseId}-medications`}
                value={customMedication}
                onChange={(e) => setCustomMedication(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    addMedication();
                  }
                }}
                placeholder="Type medication name (e.g. Metformin 500mg, Telmisartan 40mg)…"
                className="h-9 text-[13.5px]"
              />
              <button
                type="button"
                onClick={addMedication}
                className="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg border border-slate-300 bg-slate-100 px-3 text-[13px] font-semibold text-slate-800 hover:bg-slate-200"
              >
                <Plus className="h-3.5 w-3.5" />
                Add
              </button>
            </div>
          </Field>
        </div>

        {/* Medical Notes */}
        <div>
          <Field
            label="Medical Notes & Clinical Warnings"
            htmlFor={`${baseId}-medical_notes`}
            hint="Important health precautions, past hospitalisations, or special care requirements"
          >
            <Textarea
              id={`${baseId}-medical_notes`}
              rows={3}
              value={data.medical_notes}
              onChange={(e) => onChange('medical_notes', e.target.value)}
              placeholder="e.g. History of mild asthma during winters. Patient advised premedication before invasive procedures."
              className="text-[13.5px]"
            />
          </Field>
        </div>
      </div>
    </SectionCard>
  );
}
