import React from 'react';
import { MapPin, MessageCircle, Phone } from 'lucide-react';
import {
  CrmCard,
  CrmCardIcon,
  CrmAvatar,
  CrmBadge,
  CrmStageBadge,
  specialtyTone,
  priorityTone,
  sourceTone,
  toneHex,
  humanize,
} from '@/pages/Company/design';

/*
 * What the rep is about to create, rendered the way it will actually look on
 * the pipeline — before it exists.
 *
 * A fast-intake form asks for the same five things every time, in the same
 * order, and a rep filling it out has no way to tell whether the specialty
 * they just picked reads as intended until the deal is already saved and
 * sitting in the list. This mirrors the badge and avatar treatment the Deals
 * table and Deal 360 masthead already use, so what appears here is a preview
 * of the real thing rather than a second visual language for the form to
 * maintain.
 */

interface SpecialtyOption {
  value: string;
  label: string;
}

export interface DealPreviewCardProps {
  contactName: string;
  companyName: string;
  city: string;
  phone: string;
  specialtyValues: string[];
  specialtyOptions: SpecialtyOption[];
  priority: string;
  source: string;
  sourceLabel: string | null;
  assignedUserName: string | null;
  assignedTeamName: string | null;
  className?: string;
}

export function DealPreviewCard({
  contactName,
  companyName,
  city,
  phone,
  specialtyValues,
  specialtyOptions,
  priority,
  source,
  sourceLabel,
  assignedUserName,
  assignedTeamName,
  className,
}: DealPreviewCardProps) {
  const selected = specialtyOptions.filter((s) => specialtyValues.includes(s.value));
  const accentHex = toneHex(selected[0] ? specialtyTone(selected[0].value) : 'violet');
  const displayName = contactName.trim() || companyName.trim() || '?';

  return (
    <CrmCard className={className ? `overflow-hidden !p-0 ${className}` : 'overflow-hidden !p-0'}>
      <div className="h-[3px] w-full" style={{ backgroundColor: accentHex }} aria-hidden="true" />

      <div className="space-y-4 p-5">
        <div className="flex items-center justify-between gap-2">
          <p className="text-[10px] font-bold uppercase tracking-[0.12em] text-ovs-crm-text-muted">
            Live preview
          </p>
          <CrmStageBadge stage="new" short />
        </div>

        <div className="flex items-start gap-3">
          <CrmAvatar name={displayName} size="md" />
          <div className="min-w-0 flex-1">
            <p className="truncate text-[15px] font-semibold text-ovs-crm-text-primary">
              {contactName.trim() || 'Doctor name'}
            </p>
            <p className="truncate text-[12.5px] text-ovs-crm-text-secondary">
              {companyName.trim() || 'Clinic or hospital'}
            </p>
            {city.trim() && (
              <p className="mt-0.5 flex items-center gap-1 text-[11.5px] text-ovs-crm-text-muted">
                <MapPin className="h-3 w-3 shrink-0" aria-hidden="true" />
                {city}
              </p>
            )}
          </div>
        </div>

        {selected.length > 0 ? (
          <div className="flex flex-wrap gap-1.5">
            {selected.map((s) => (
              <CrmBadge key={s.value} tone={specialtyTone(s.value)}>
                {s.label}
              </CrmBadge>
            ))}
          </div>
        ) : (
          <p className="text-[11.5px] text-ovs-crm-text-muted">
            Pick a specialty to see it badged here.
          </p>
        )}

        <div className="flex flex-wrap items-center gap-2 border-t border-ovs-crm-border pt-3.5">
          <CrmCardIcon icon={MessageCircle} hue={toneHex('emerald')} />
          <CrmCardIcon icon={Phone} hue={toneHex('blue')} />
          <span className="ml-auto truncate text-[11px] tabular-nums text-ovs-crm-text-muted">
            {phone.trim() || 'No number yet'}
          </span>
        </div>

        <div className="flex flex-wrap items-center gap-1.5 border-t border-ovs-crm-border pt-3.5">
          <CrmBadge tone={priorityTone(priority)}>{humanize(priority)} priority</CrmBadge>

          {source && (
            <CrmBadge tone={sourceTone(source)}>{sourceLabel ?? humanize(source)}</CrmBadge>
          )}

          <span className="ml-auto flex min-w-0 items-center gap-1.5 text-[11.5px] text-ovs-crm-text-secondary">
            {assignedUserName ? (
              <>
                <CrmAvatar name={assignedUserName} size="xs" />
                <span className="truncate">{assignedUserName}</span>
              </>
            ) : assignedTeamName ? (
              <span className="truncate">{assignedTeamName} team</span>
            ) : (
              'Auto-assigns on save'
            )}
          </span>
        </div>
      </div>
    </CrmCard>
  );
}
