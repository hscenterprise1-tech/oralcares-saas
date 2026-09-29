import React from 'react';
import { buildWeekDays, groupAppointmentsByDate } from '../dateMath';
import { AppointmentPractitioner, AppointmentRow } from '../types';
import { TimeGrid } from './TimeGrid';
import type { PopoverAnchorRect } from './EventPopover';

/** Week view — the same `TimeGrid` as Day, 7 columns instead of 1. */
export function WeekView({
  date,
  appointments,
  practitioners,
  leaveDoctors,
  onSlotSelect,
  onEventClick,
}: {
  date: string;
  appointments: AppointmentRow[];
  practitioners: AppointmentPractitioner[];
  leaveDoctors: AppointmentPractitioner[];
  onSlotSelect: (dayKey: string, time: string) => void;
  onEventClick: (appointment: AppointmentRow, anchorRect: PopoverAnchorRect) => void;
}) {
  const practitionerById = new Map(practitioners.map((p) => [p.id, p]));
  const appointmentsByDay = groupAppointmentsByDate(appointments);
  const days = buildWeekDays(date);

  return (
    <TimeGrid
      days={days}
      appointmentsByDay={appointmentsByDay}
      practitionerById={practitionerById}
      leaveDoctors={leaveDoctors}
      showDayHeaders
      onSlotClick={onSlotSelect}
      onEventClick={onEventClick}
    />
  );
}

export default WeekView;
