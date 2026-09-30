import React from 'react';
import { Lock } from 'lucide-react';
import { minutesSinceMidnight, toDateKey } from '../dateMath';
import { layoutOverlaps } from '../gridLayout';
import { AppointmentPractitioner, AppointmentRow } from '../types';
import { EventCard } from './EventCard';
import type { PopoverAnchorRect } from './EventPopover';

/**
 * The custom time-grid shared by Day (1 column) and Week (7 columns) —
 * replaces the FullCalendar `timeGrid` plugin. Every event's own visuals
 * were already 100% custom (`EventCard`); the only real job left was
 * positioning + overlap layout, which `gridLayout.ts` now does directly
 * rather than through FullCalendar's API (and the version-mismatch /
 * `businessHours` bugs that came with it).
 */

const GRID_START_HOUR = 8;
const GRID_END_HOUR = 21;
const SLOT_MINUTES = 30;
const PX_PER_MINUTE = 1;

export function TimeGrid({
  days,
  appointmentsByDay,
  practitionerById,
  leaveDoctors = [],
  showDayHeaders,
  onSlotClick,
  onEventClick,
}: {
  days: { key: string; date: Date }[];
  appointmentsByDay: Map<string, AppointmentRow[]>;
  practitionerById: Map<number, AppointmentPractitioner>;
  leaveDoctors?: AppointmentPractitioner[];
  showDayHeaders: boolean;
  onSlotClick: (dayKey: string, time: string) => void;
  onEventClick: (appointment: AppointmentRow, anchorRect: PopoverAnchorRect) => void;
}) {
  const totalMinutes = (GRID_END_HOUR - GRID_START_HOUR) * 60;
  const gridHeight = totalMinutes * PX_PER_MINUTE;
  const slotCount = totalMinutes / SLOT_MINUTES;
  const todayKey = toDateKey(new Date());
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const showNowLine = nowMinutes >= GRID_START_HOUR * 60 && nowMinutes <= GRID_END_HOUR * 60;

  const slotTime = (slotIndex: number) => {
    const minutes = GRID_START_HOUR * 60 + slotIndex * SLOT_MINUTES;
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
  };

  return (
    <div className="space-y-3">
      {leaveDoctors.length > 0 && (
        <div className="flex flex-wrap gap-2 px-1">
          {leaveDoctors.map((p) => (
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

      <div className="overflow-hidden rounded-[18px] border border-ovs-crm-border bg-white shadow-ovs-crmPanel">
        {showDayHeaders && (
          <div className="flex border-b border-ovs-crm-border">
            <div className="w-14 shrink-0" />
            {days.map((d) => (
              <div key={d.key} className="flex-1 border-l border-ovs-crm-border py-2 text-center">
                <p className={`text-[10.5px] font-semibold uppercase tracking-[0.04em] ${d.key === todayKey ? 'text-ovs-crm-ink' : 'text-ovs-crm-text-muted'}`}>
                  {d.date.toLocaleDateString('en-US', { weekday: 'short' })}
                </p>
                <p
                  className={
                    d.key === todayKey
                      ? 'mx-auto mt-0.5 flex h-6 w-6 items-center justify-center rounded-full bg-ovs-crm-ink text-[12.5px] font-bold text-white'
                      : 'mt-0.5 text-[13px] font-bold text-ovs-crm-text-primary'
                  }
                >
                  {d.date.getDate()}
                </p>
              </div>
            ))}
          </div>
        )}

        <div className="flex max-h-[640px] overflow-y-auto">
          <div className="w-14 shrink-0 border-r border-ovs-crm-border">
            <div style={{ height: gridHeight }} className="relative">
              {Array.from({ length: slotCount + 1 }, (_, i) => {
                const minutes = GRID_START_HOUR * 60 + i * SLOT_MINUTES;
                if (minutes % 60 !== 0) return null;
                const hour = Math.floor(minutes / 60);
                const label = `${hour % 12 === 0 ? 12 : hour % 12}${hour < 12 ? 'am' : 'pm'}`;
                return (
                  <span
                    key={i}
                    className="absolute right-2 -translate-y-1/2 text-[11px] font-medium text-ovs-crm-text-muted"
                    style={{ top: i * SLOT_MINUTES * PX_PER_MINUTE }}
                  >
                    {label}
                  </span>
                );
              })}
            </div>
          </div>

          {days.map((d) => {
            const dayAppointments = appointmentsByDay.get(d.key) ?? [];
            const overlapInputs = dayAppointments.map((a) => {
              const start = minutesSinceMidnight(a.scheduled_at);
              return { id: String(a.id), startMinutes: start, endMinutes: start + a.duration_minutes };
            });
            const layoutById = new Map(layoutOverlaps(overlapInputs).map((l) => [l.id, l]));

            return (
              <div key={d.key} className="relative flex-1 border-l border-ovs-crm-border" style={{ height: gridHeight }}>
                <div className="pointer-events-none absolute inset-0">
                  {Array.from({ length: slotCount }, (_, i) => (
                    <div
                      key={i}
                      className={i % 2 === 0 ? 'absolute left-0 right-0 border-t border-dashed border-ovs-crm-border' : 'absolute left-0 right-0 border-t border-ovs-crm-border/50'}
                      style={{ top: i * SLOT_MINUTES * PX_PER_MINUTE }}
                    />
                  ))}
                </div>

                <button
                  type="button"
                  aria-label={`Book a slot on ${d.key}`}
                  className="absolute inset-0 cursor-pointer transition-colors hover:bg-ovs-crm-accent-violet-soft/25"
                  onClick={(e) => {
                    const rect = e.currentTarget.getBoundingClientRect();
                    const offsetMinutes = ((e.clientY - rect.top) / PX_PER_MINUTE / SLOT_MINUTES) | 0;
                    onSlotClick(d.key, slotTime(offsetMinutes));
                  }}
                />

                {d.key === todayKey && showNowLine && (
                  <div
                    className="pointer-events-none absolute left-0 right-0 z-10 border-t-[1.5px] border-ovs-crm-danger"
                    style={{ top: (nowMinutes - GRID_START_HOUR * 60) * PX_PER_MINUTE }}
                  >
                    <span className="absolute -left-[3px] -top-[4px] h-[7px] w-[7px] rounded-full bg-ovs-crm-danger" />
                  </div>
                )}

                {dayAppointments.map((appt) => {
                  const layout = layoutById.get(String(appt.id));
                  if (!layout) return null;
                  const start = minutesSinceMidnight(appt.scheduled_at);
                  const top = (start - GRID_START_HOUR * 60) * PX_PER_MINUTE;
                  const height = Math.max(appt.duration_minutes * PX_PER_MINUTE, 22);
                  const widthPct = 100 / layout.columnCount;
                  const leftPct = layout.column * widthPct;

                  return (
                    <div
                      key={appt.id}
                      className="absolute z-[5] px-[1px]"
                      style={{ top, height, left: `${leftPct}%`, width: `${widthPct}%` }}
                    >
                      <EventCard
                        appointment={appt}
                        doctorName={practitionerById.get(appt.practitioner_id)?.name}
                        onClick={(e) => onEventClick(appt, e.currentTarget.getBoundingClientRect())}
                      />
                    </div>
                  );
                })}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

export default TimeGrid;
