import React, { useMemo } from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CrmBadge } from '@/pages/Company/design';
import { TagChipInput } from './TagChipInput';

/**
 * Allergies, picked from a curated master list rather than typed free-hand
 * — see `AllergyMaster` for why (typo risk: "Penicillin" vs "penicillin"
 * vs a misspelling, three different strings a plain `TagChipInput` would
 * have treated as three different allergies). The master list is a
 * reference, not a constraint, exactly like `HealthcareSpecialtyMaster`:
 * anything not in it still gets in through "Other allergies" below, stored
 * the same way — a plain name in the same array — so a clinic is never
 * blocked by a catalogue gap.
 */

export interface AllergyOption {
  key: string;
  name: string;
  category: string;
  category_label: string;
}

export function AllergyPicker({
  catalogue,
  value,
  onChange,
}: {
  catalogue: AllergyOption[];
  value: string[];
  onChange: (next: string[]) => void;
}) {
  const catalogueNames = useMemo(() => new Set(catalogue.map((a) => a.name)), [catalogue]);
  const customEntries = useMemo(() => value.filter((name) => !catalogueNames.has(name)), [value, catalogueNames]);

  const grouped = useMemo(() => {
    const groups = new Map<string, AllergyOption[]>();
    for (const allergy of catalogue) {
      const list = groups.get(allergy.category_label) ?? [];
      list.push(allergy);
      groups.set(allergy.category_label, list);
    }
    return groups;
  }, [catalogue]);

  const toggle = (name: string) => {
    onChange(value.includes(name) ? value.filter((v) => v !== name) : [...value, name]);
  };

  return (
    <div className="space-y-3">
      {[...grouped.entries()].map(([categoryLabel, allergies]) => (
        <div key={categoryLabel}>
          <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-ovs-crm-text-muted">{categoryLabel}</p>
          <div className="flex flex-wrap gap-1.5">
            {allergies.map((allergy) => {
              const on = value.includes(allergy.name);

              return (
                <button key={allergy.key} type="button" onClick={() => toggle(allergy.name)} aria-pressed={on}>
                  <CrmBadge
                    tone="coral"
                    variant={on ? 'solid' : 'soft'}
                    size="sm"
                    icon={on ? <Check className="h-3 w-3 shrink-0" aria-hidden="true" /> : undefined}
                    className={cn('cursor-pointer transition', !on && 'opacity-80 hover:opacity-100')}
                  >
                    {allergy.name}
                  </CrmBadge>
                </button>
              );
            })}
          </div>
        </div>
      ))}

      <div>
        <p className="mb-1.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-ovs-crm-text-muted">Other allergies</p>
        <TagChipInput
          value={customEntries}
          onChange={(nextCustom) => onChange([...value.filter((name) => catalogueNames.has(name)), ...nextCustom])}
          placeholder="Not on the list? Type it here"
          tone="coral"
        />
      </div>
    </div>
  );
}
