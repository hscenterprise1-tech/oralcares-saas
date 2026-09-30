import React from 'react';
import { avatarHexForName } from '@/pages/Company/design';
import { APPOINTMENT_STATUS_TONE } from '../statusTone';
import { AppointmentRow } from '../types';

/**
 * The one pastel status-tinted appointment card. Day and Week's time-grid
 * blocks and desktop Month's per-day chips previously each hand-rolled
 * their own copy of this via FullCalendar's `eventContent` — three copies
 * that had already started drifting (different radii, border widths).
 * `variant="block"` is the grid block (avatar + name + time · doctor);
 * `variant="chip"` is Month's compact one-line row (dot + name + time).
 */
export function EventCard({
  appointment,
  doctorName,
  variant = 'block',
  onClick,
  style,
}: {
  appointment: AppointmentRow;
  doctorName?: string;
  variant?: 'block' | 'chip';
  onClick: (event: React.MouseEvent<HTMLButtonElement>) => void;
  style?: React.CSSProperties;
}) {
  const tone = APPOINTMENT_STATUS_TONE[appointment.status] ?? APPOINTMENT_STATUS_TONE.scheduled;
  const initial = appointment.patient_name.trim().charAt(0).toUpperCase() || '?';

  if (variant === 'chip') {
    return (
      <button
        type="button"
        onClick={onClick}
        style={{ ...style, backgroundColor: tone.bg }}
        className="flex w-full items-center gap-1 overflow-hidden rounded-[6px] px-1.5 py-[3px] text-left transition-transform hover:-translate-y-px"
      >
        <span className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ backgroundColor: tone.dot }} aria-hidden="true" />
        <span className="truncate text-[10px] font-semibold" style={{ color: tone.text }}>
          {appointment.patient_name}
        </span>
        <span className="ml-auto shrink-0 truncate text-[9px] font-medium opacity-75" style={{ color: tone.text }}>
          {appointment.scheduled_time}
        </span>
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={onClick}
      style={{ ...style, backgroundColor: tone.bg, borderColor: tone.border }}
      className="flex h-full w-full flex-col overflow-hidden rounded-[10px] border-2 px-2 py-1 text-left transition-transform hover:-translate-y-px"
    >
      <div className="flex items-center gap-1.5">
        <span
          className="flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[8px] font-bold text-white"
          style={{ backgroundColor: avatarHexForName(appointment.patient_name) }}
        >
          {initial}
        </span>
        <span className="truncate text-[11px] font-semibold" style={{ color: tone.text }}>
          {appointment.patient_name}
        </span>
      </div>
      <p className="truncate text-[9.5px] font-medium opacity-75" style={{ color: tone.text }}>
        {appointment.scheduled_time}
        {doctorName ? ` · ${doctorName}` : ''}
      </p>
    </button>
  );
}

export default EventCard;
