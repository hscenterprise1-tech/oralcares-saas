import React from 'react';
import { CalendarClock, Lock } from 'lucide-react';
import { CrmAvatar, CrmCard, CrmStatusPill } from '@/pages/Company/design';
import { APPOINTMENT_STATUS_PILL_TONE, APPOINTMENT_STATUS_TONE, appointmentStatusLabel } from '../statusTone';
import { AppointmentPractitioner, AppointmentRow } from '../types';
import type { PopoverAnchorRect } from './EventPopover';

/**
 * The mobile (<768px) view — a native-Calendar-app-style vertical agenda
 * instead of squeezing the desktop's side-by-side doctor columns into an
 * unreadable width. Every visible doctor's appointments in one time-ordered
 * list, since the point of a phone-width view is "what's happening today",
 * not "which doctor's column am I looking at".
 */

export function AgendaList({
  appointments,
  practitioners,
  leaveEmployeeIds,
  onEventClick,
}: {
  appointments: AppointmentRow[];
  practitioners: AppointmentPractitioner[];
  leaveEmployeeIds: number[];
  onEventClick: (appointment: AppointmentRow, anchorRect: PopoverAnchorRect) => void;
}) {
  const practitionerById = new Map(practitioners.map((p) => [p.id, p]));
  const onLeaveToday = practitioners.filter((p) => leaveEmployeeIds.includes(p.id));

  return (
    <div className="space-y-3">
      {onLeaveToday.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {onLeaveToday.map((p) => (
            <span
              key={p.id}
              className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-[11.5px] font-medium text-ovs-crm-text-secondary"
            >
              <Lock className="h-3 w-3 text-ovs-crm-text-muted" aria-hidden="true" />
              {p.name} is on leave today
            </span>
          ))}
        </div>
      )}

      {appointments.length === 0 ? (
        <CrmCard className="flex flex-col items-center gap-2 py-14 text-center">
          <CalendarClock className="h-7 w-7 text-ovs-crm-text-muted" aria-hidden="true" />
          <p className="text-[13.5px] font-medium text-ovs-crm-text-primary">No appointments for this day</p>
          <p className="text-[12px] text-ovs-crm-text-muted">Tap "New Appointment" above to book one.</p>
        </CrmCard>
      ) : (
        <div className="overflow-hidden rounded-[18px] border border-ovs-crm-border bg-white shadow-ovs-crmPanel">
          <div className="divide-y divide-ovs-crm-border/70">
            {appointments.map((appt) => {
              const doctor = practitionerById.get(appt.practitioner_id);
              const dot = (APPOINTMENT_STATUS_TONE[appt.status] ?? APPOINTMENT_STATUS_TONE.scheduled).dot;

              return (
                <button
                  key={appt.id}
                  type="button"
                  onClick={(e) => onEventClick(appt, e.currentTarget.getBoundingClientRect())}
                  className="flex min-h-[64px] w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-ovs-crm-surface-inset"
                >
                  <div className="w-16 shrink-0">
                    <p className="text-[12.5px] font-semibold tabular-nums text-ovs-crm-text-primary">{appt.scheduled_time}</p>
                  </div>

                  <span className="h-2 w-2 shrink-0 rounded-full" style={{ backgroundColor: dot }} aria-hidden="true" />

                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13.5px] font-semibold text-ovs-crm-text-primary">{appt.patient_name}</p>
                    <div className="mt-0.5 flex items-center gap-1.5">
                      {doctor && <CrmAvatar name={doctor.name} size="xs" />}
                      <p className="truncate text-[11.5px] text-ovs-crm-text-muted">{doctor?.name ?? 'Unassigned'}</p>
                    </div>
                  </div>

                  <CrmStatusPill tone={APPOINTMENT_STATUS_PILL_TONE[appt.status] ?? 'neutral'}>{appointmentStatusLabel(appt.status)}</CrmStatusPill>
                </button>
              );
            })}
          </div>
        </div>
      )}
    </div>
  );
}

export default AgendaList;
