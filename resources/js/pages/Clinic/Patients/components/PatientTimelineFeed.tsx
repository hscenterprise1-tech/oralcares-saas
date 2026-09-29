import React from 'react';
import {
  CalendarClock,
  FileText,
  IndianRupee,
  Pencil,
  Sparkles,
  Stethoscope,
  UserCheck,
  type LucideIcon,
} from 'lucide-react';
import { CrmCardIcon, CrmDataState, toneHex, type CrmTone } from '@/pages/Company/design';
import { TimelineCategory, TimelineEvent } from '../types';

/**
 * The unified activity feed, one colour per category — the same "read at a
 * glance" job `CrmCardIcon` already does on the Dashboard's stat cards,
 * applied to a list instead of a grid.
 */

const CATEGORY_TONE: Record<TimelineCategory, CrmTone> = {
  appointment: 'violet',
  clinical: 'teal',
  billing: 'green',
  reception: 'blue',
  document: 'amber',
  record: 'slate',
  system: 'slate',
};

const CATEGORY_ICON: Record<TimelineCategory, LucideIcon> = {
  appointment: CalendarClock,
  clinical: Stethoscope,
  billing: IndianRupee,
  reception: UserCheck,
  document: FileText,
  record: Pencil,
  system: Sparkles,
};

export function PatientTimelineFeed({ events, limit }: { events: TimelineEvent[]; limit?: number }) {
  const shown = limit ? events.slice(0, limit) : events;

  if (shown.length === 0) {
    return <CrmDataState kind="empty" compact icon={Sparkles} title="No activity yet" description="Everything that happens with this patient will show up here." />;
  }

  return (
    <div className="space-y-1">
      {shown.map((event) => {
        const tone = CATEGORY_TONE[event.category] ?? 'slate';
        const Icon = CATEGORY_ICON[event.category] ?? Sparkles;

        return (
          <div key={event.id} className="flex items-start gap-3 rounded-[16px] px-2 py-2.5 transition hover:bg-ovs-crm-surface-inset">
            <CrmCardIcon icon={Icon} hue={toneHex(tone)} />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                <p className="truncate text-[13px] font-semibold text-ovs-crm-text-primary">{event.title}</p>
                <span className="shrink-0 text-[11px] tabular-nums text-ovs-crm-text-muted">{event.formatted_time}</span>
              </div>
              <p className="mt-0.5 truncate text-[12px] text-ovs-crm-text-secondary">{event.description}</p>
              <p className="mt-0.5 text-[11px] text-ovs-crm-text-muted">{event.actor_name}</p>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default PatientTimelineFeed;
