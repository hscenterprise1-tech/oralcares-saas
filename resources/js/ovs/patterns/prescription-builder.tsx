import React, { useState } from 'react';
import { Plus, Trash2, Printer, Edit3, Pill, FileText } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Card } from '@/ovs/primitives/card';
import { Button } from '@/ovs/primitives/button';
import { Input } from '@/ovs/primitives/input';
import { SimpleSelect as Select } from '@/ovs/primitives/select';
import { Textarea } from '@/ovs/primitives/textarea';
import { Label } from '@/ovs/primitives/label';
import { Badge } from '@/ovs/primitives/badge';

export type PrescriptionFrequency = 'OD' | 'BD' | 'TDS' | 'QID' | 'SOS' | 'STAT';

export interface PrescriptionMedication {
  id: string;
  name: string;
  dosage: string;
  frequency: PrescriptionFrequency;
  duration: string;
  durationUnit?: 'days' | 'weeks' | 'months';
  instructions?: string;
}

export interface PrescriptionBuilderProps {
  medications: PrescriptionMedication[];
  onAdd: (med: PrescriptionMedication) => void;
  onRemove: (id: string) => void;
  onUpdate: (id: string, med: Partial<PrescriptionMedication>) => void;
  drugSuggestions?: string[];
  doctorName?: string;
  clinicName?: string;
  className?: string;
}

export const FREQUENCY_OPTIONS: { value: PrescriptionFrequency; label: string; description: string }[] = [
  { value: 'OD', label: 'OD (Once Daily)', description: 'Take 1 time per day' },
  { value: 'BD', label: 'BD (Twice Daily)', description: 'Take 2 times per day (Morning/Night)' },
  { value: 'TDS', label: 'TDS (Thrice Daily)', description: 'Take 3 times per day (Morning/Noon/Night)' },
  { value: 'QID', label: 'QID (Four Times Daily)', description: 'Take 4 times per day' },
  { value: 'SOS', label: 'SOS (As Needed)', description: 'Take when needed (e.g. for pain)' },
  { value: 'STAT', label: 'STAT (Immediately)', description: 'Take single dose immediately' },
];

export function PrescriptionBuilder({
  medications,
  onAdd,
  onRemove,
  onUpdate,
  drugSuggestions = [
    'Amoxicillin 500mg',
    'Paracetamol 650mg',
    'Ibuprofen 400mg',
    'Metronidazole 400mg',
    'Chlorhexidine 0.2% Mouthwash',
    'Ketorolac DT 10mg',
    'Amoxicillin + Clavulanic Acid 625mg',
  ],
  doctorName = 'Dr. Rahul Sharma, BDS MDS',
  clinicName = 'OralCares Dental Super Speciality',
  className,
}: PrescriptionBuilderProps) {
  const [isPreviewMode, setIsPreviewMode] = useState<boolean>(false);

  const handleCreateEmptyRow = () => {
    const newMed: PrescriptionMedication = {
      id: `med-${Date.now()}-${Math.random().toString(36).substr(2, 5)}`,
      name: '',
      dosage: '1 Tablet',
      frequency: 'BD',
      duration: '5',
      durationUnit: 'days',
      instructions: 'After food',
    };
    onAdd(newMed);
  };

  return (
    <div className={cn('w-full bg-white border border-ovs-border-subtle rounded-2xl p-5 shadow-2xs space-y-5', className)}>
      {/* Top Header & Mode Toggle */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-ovs-border-subtle pb-3">
        <div className="flex items-center gap-2">
          <Pill className="w-5 h-5 text-ovs-accent" />
          <div>
            <h3 className="text-sm font-bold text-ovs-primary font-display">
              Clinical Prescription Builder
            </h3>
            <span className="text-[11px] text-ovs-secondary block">
              {doctorName} · {clinicName}
            </span>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            size="sm"
            variant={!isPreviewMode ? 'primary' : 'secondary'}
            onClick={() => setIsPreviewMode(false)}
            className="text-xs gap-1.5"
          >
            <Edit3 className="w-3.5 h-3.5" /> Edit Builder
          </Button>
          <Button
            size="sm"
            variant={isPreviewMode ? 'primary' : 'secondary'}
            onClick={() => setIsPreviewMode(true)}
            className="text-xs gap-1.5"
          >
            <Printer className="w-3.5 h-3.5" /> Print Preview
          </Button>
        </div>
      </div>

      {/* Datalist for Drug Autocomplete Suggestions */}
      <datalist id="ovs-drug-suggestions">
        {drugSuggestions.map((drug, i) => (
          <option key={i} value={drug} />
        ))}
      </datalist>

      {/* Mode 1: Edit Mode */}
      {!isPreviewMode && (
        <div className="space-y-4">
          {medications.length === 0 ? (
            <div className="text-center py-8 border-2 border-dashed border-ovs-border-subtle rounded-2xl space-y-2">
              <Pill className="w-8 h-8 text-ovs-tertiary mx-auto" />
              <p className="text-xs text-ovs-secondary font-medium">No medications added to this prescription yet.</p>
              <Button size="sm" variant="secondary" onClick={handleCreateEmptyRow} className="text-xs">
                + Add First Medication
              </Button>
            </div>
          ) : (
            medications.map((med, index) => (
              <Card key={med.id} className="p-4 bg-ovs-background/50 border border-ovs-border-subtle rounded-2xl space-y-3">
                <div className="flex items-center justify-between border-b border-ovs-border-subtle/60 pb-2">
                  <span className="text-xs font-mono font-bold text-ovs-accent uppercase tracking-wider">
                    #{index + 1} Medication Item
                  </span>
                  <Button
                    size="icon"
                    variant="ghost"
                    onClick={() => onRemove(med.id)}
                    className="w-7 h-7 text-ovs-semantic-danger-text hover:bg-ovs-semantic-danger-bg rounded-lg"
                    title="Remove Medication"
                  >
                    <Trash2 className="w-3.5 h-3.5" />
                  </Button>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-12 gap-3">
                  {/* Drug Name with Autocomplete */}
                  <div className="md:col-span-4 space-y-1">
                    <Label className="text-[11px]">Drug / Medication Name</Label>
                    <Input
                      list="ovs-drug-suggestions"
                      placeholder="e.g. Amoxicillin 500mg"
                      value={med.name}
                      onChange={(e) => onUpdate(med.id, { name: e.target.value })}
                      className="text-xs bg-white"
                    />
                  </div>

                  {/* Dosage */}
                  <div className="md:col-span-2 space-y-1">
                    <Label className="text-[11px]">Dosage</Label>
                    <Input
                      placeholder="e.g. 500mg / 1 tab"
                      value={med.dosage}
                      onChange={(e) => onUpdate(med.id, { dosage: e.target.value })}
                      className="text-xs bg-white"
                    />
                  </div>

                  {/* Frequency Shorthand */}
                  <div className="md:col-span-3 space-y-1">
                    <Label className="text-[11px]">Frequency (Rx Shorthand)</Label>
                    <Select
                      value={med.frequency}
                      onValueChange={(val) => onUpdate(med.id, { frequency: val as PrescriptionFrequency })}
                      options={FREQUENCY_OPTIONS.map((f) => ({
                        value: f.value,
                        label: f.label,
                      }))}
                    />
                  </div>

                  {/* Duration + Unit */}
                  <div className="md:col-span-3 space-y-1">
                    <Label className="text-[11px]">Duration</Label>
                    <div className="flex items-center gap-1.5">
                      <Input
                        placeholder="5"
                        value={med.duration}
                        onChange={(e) => onUpdate(med.id, { duration: e.target.value })}
                        className="text-xs bg-white w-16"
                      />
                      <Select
                        value={med.durationUnit || 'days'}
                        onValueChange={(val) => onUpdate(med.id, { durationUnit: val as any })}
                        options={[
                          { value: 'days', label: 'Days' },
                          { value: 'weeks', label: 'Weeks' },
                          { value: 'months', label: 'Months' },
                        ]}
                      />
                    </div>
                  </div>

                  {/* Instructions */}
                  <div className="md:col-span-12 space-y-1 pt-1">
                    <Label className="text-[11px]">Patient Instructions</Label>
                    <Input
                      placeholder="e.g. Take after meals twice daily. Complete 5 day course."
                      value={med.instructions || ''}
                      onChange={(e) => onUpdate(med.id, { instructions: e.target.value })}
                      className="text-xs bg-white"
                    />
                  </div>
                </div>
              </Card>
            ))
          )}

          <Button
            type="button"
            variant="secondary"
            onClick={handleCreateEmptyRow}
            className="w-full text-xs gap-1.5 border-dashed"
          >
            <Plus className="w-3.5 h-3.5" /> Add Medication Line
          </Button>
        </div>
      )}

      {/* Mode 2: Clean Print Preview Mode */}
      {isPreviewMode && (
        <div className="p-6 bg-white border border-ovs-border-strong rounded-2xl space-y-6 shadow-sm">
          {/* Rx Header */}
          <div className="flex items-start justify-between border-b-2 border-ovs-primary pb-4">
            <div>
              <h2 className="text-base font-bold text-ovs-primary font-display">{clinicName}</h2>
              <p className="text-xs text-ovs-secondary">{doctorName}</p>
            </div>
            <div className="text-right">
              <span className="text-2xl font-extrabold font-display text-ovs-accent leading-none block">Rx</span>
              <span className="text-[11px] text-ovs-tertiary font-mono">Date: {new Date().toLocaleDateString('en-IN')}</span>
            </div>
          </div>

          {/* Numbered Medication List */}
          <div className="space-y-4">
            {medications.map((med, idx) => {
              const freqObj = FREQUENCY_OPTIONS.find((f) => f.value === med.frequency);
              return (
                <div key={med.id} className="flex items-start gap-3 border-b border-ovs-border-subtle pb-3">
                  <span className="w-6 h-6 rounded-full bg-ovs-primary text-white text-xs font-mono font-bold flex items-center justify-center flex-shrink-0 mt-0.5">
                    {idx + 1}
                  </span>
                  <div className="flex-1 space-y-1">
                    <div className="flex items-baseline justify-between">
                      <span className="text-sm font-bold text-ovs-primary">{med.name}</span>
                      <span className="text-xs font-mono font-bold text-ovs-accent">
                        {med.dosage} · {med.duration} {med.durationUnit || 'days'}
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge variant="neutral" className="text-[10px] font-mono font-bold py-0.5">
                        {freqObj?.label || med.frequency}
                      </Badge>
                      <span className="text-xs text-ovs-secondary italic">
                        ({freqObj?.description})
                      </span>
                    </div>
                    {med.instructions && (
                      <p className="text-xs text-ovs-secondary bg-ovs-background p-2 rounded-lg border border-ovs-border-subtle mt-1">
                        Instruction: {med.instructions}
                      </p>
                    )}
                  </div>
                </div>
              );
            })}
          </div>

          {/* Doctor Signature Footer */}
          <div className="pt-8 flex items-center justify-between border-t border-ovs-border-subtle">
            <span className="text-[10px] font-mono text-ovs-tertiary">
              Generated via OralCares Healthcare Operating System
            </span>
            <div className="text-center border-t border-ovs-primary pt-1 w-44">
              <span className="text-xs font-bold text-ovs-primary block">{doctorName}</span>
              <span className="text-[10px] text-ovs-tertiary block">Registered Dental Surgeon</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
