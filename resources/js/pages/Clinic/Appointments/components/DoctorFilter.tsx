import React from 'react';
import * as PopoverPrimitive from '@radix-ui/react-popover';
import { Check, ChevronDown, Stethoscope } from 'lucide-react';
import { CrmAvatar } from '@/pages/Company/design';
import { AppointmentPractitioner } from '../types';

/**
 * A compact "which doctors" filter — a single button that opens a
 * checklist, replacing the earlier full-width row of large doctor chips.
 * That row ate a whole card's worth of vertical space just to pick who to
 * see; this is the same job in the space one button takes.
 */
export function DoctorFilter({
  practitioners,
  selectedIds,
  onToggle,
}: {
  practitioners: AppointmentPractitioner[];
  selectedIds: number[];
  onToggle: (id: number) => void;
}) {
  const allSelected = selectedIds.length === 0;
  const label = allSelected ? 'All doctors' : selectedIds.length === 1 ? '1 doctor' : `${selectedIds.length} doctors`;

  if (practitioners.length === 0) {
    return null;
  }

  return (
    <PopoverPrimitive.Root>
      <PopoverPrimitive.Trigger asChild>
        <button
          type="button"
          className="flex min-h-11 items-center gap-2 rounded-full border border-ovs-crm-border bg-white px-4 text-[12.5px] font-medium text-ovs-crm-text-primary transition-colors hover:border-ovs-crm-border-strong"
        >
          <Stethoscope className="h-3.5 w-3.5 text-ovs-crm-text-muted" aria-hidden="true" />
          {label}
          <ChevronDown className="h-3.5 w-3.5 text-ovs-crm-text-muted" aria-hidden="true" />
        </button>
      </PopoverPrimitive.Trigger>

      <PopoverPrimitive.Portal>
        <PopoverPrimitive.Content
          align="start"
          sideOffset={6}
          className="z-50 w-[240px] rounded-[18px] border border-ovs-crm-border bg-white p-2 shadow-ovs-crmLift data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
        >
          {practitioners.map((p) => {
            const active = allSelected || selectedIds.includes(p.id);
            return (
              <button
                key={p.id}
                type="button"
                onClick={() => onToggle(p.id)}
                className="flex min-h-11 w-full items-center gap-2.5 rounded-[12px] px-2 text-left transition-colors hover:bg-ovs-crm-surface-inset"
              >
                <CrmAvatar name={p.name} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-[12.5px] font-medium text-ovs-crm-text-primary">{p.name}</span>
                  <span className="block truncate text-[10.5px] text-ovs-crm-text-muted">{p.specialty}</span>
                </span>
                <span
                  className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full border ${
                    active ? 'border-ovs-crm-ink bg-ovs-crm-ink text-white' : 'border-ovs-crm-border text-transparent'
                  }`}
                >
                  <Check className="h-3 w-3" aria-hidden="true" />
                </span>
              </button>
            );
          })}
        </PopoverPrimitive.Content>
      </PopoverPrimitive.Portal>
    </PopoverPrimitive.Root>
  );
}

export default DoctorFilter;
