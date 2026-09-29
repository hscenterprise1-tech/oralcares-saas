import React from 'react';
import { groupAppointmentsByDate, parseDateKey } from '../dateMath';
import { AppointmentPractitioner, AppointmentRow } from '../types';
import { TimeGrid } from './TimeGrid';
import type { PopoverAnchorRect } from './EventPopover';

/** Day view — a single `TimeGrid` column. One merged calendar, not a column per doctor; every event card carries its own doctor's name via `EventCard`. */
export function DayView({
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
  onSlotSelect: (time: string) => void;
  onEventClick: (appointment: AppointmentRow, anchorRect: PopoverAnchorRect) => void;
}) {
  const practitionerById = new Map(practitioners.map((p) => [p.id, p]));
  const appointmentsByDay = groupAppointmentsByDate(appointments);

  return (
    <TimeGrid
      days={[{ key: date, date: parseDateKey(date) }]}
      appointmentsByDay={appointmentsByDay}
      practitionerById={practitionerById}
      leaveDoctors={leaveDoctors}
      showDayHeaders={false}
      onSlotClick={(_dayKey, time) => onSlotSelect(time)}
      onEventClick={onEventClick}
    />
  );
}

export default DayView;
