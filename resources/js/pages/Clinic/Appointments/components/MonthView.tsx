import React from 'react';
import { CalendarClock } from 'lucide-react';
import { CrmCard } from '@/pages/Company/design';
import { cn } from '@/lib/utils';
import { buildMonthGrid, groupAppointmentsByDate, toDateKey } from '../dateMath';
import { APPOINTMENT_STATUS_TONE } from '../statusTone';
import { AppointmentPractitioner, AppointmentRow } from '../types';
import { AgendaList } from './AgendaList';
import { EventCard } from './EventCard';
import type { PopoverAnchorRect } from './EventPopover';

/**
 * Month view — one shared grid (`buildMonthGrid`) and one shared
 * per-day appointment map (`groupAppointmentsByDate`) feed two genuinely
 * different presentations, so they can never drift apart on the date math
 * itself, only on how much a cell shows:
 *
 * - Mobile (<768px): a rounded date tile with up to 4 status dots — no
 *   room for readable event text at that width. Tapping a tile calls
 *   `navigate(date, 'month')` (a real URL change, not local state — the
 *   inline agenda below is whatever `appointments`/`date` say *after* that
 *   round-trip, so it can never go stale against the header/URL the way
 *   the previous local-`useState` version did).
 * - Desktop (>=768px): a denser grid, up to 3 event chips per cell plus a
 *   "+N more" link. Clicking a chip opens the event popover; clicking the
 *   cell itself or "+N more" opens that day's full Day view.
 */
const MAX_DESKTOP_CHIPS = 3;
const WEEKDAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

export function MonthView({
  date,
  appointments,
  practitioners,
  leaveEmployeeIds,
  onMobileDateSelect,
  onDesktopDayOpen,
  onEventClick,
}: {
  date: string;
  appointments: AppointmentRow[];
  practitioners: AppointmentPractitioner[];
  leaveEmployeeIds: number[];
  onMobileDateSelect: (date: string) => void;
  onDesktopDayOpen: (date: string) => void;
  onEventClick: (appointment: AppointmentRow, anchorRect: PopoverAnchorRect) => void;
}) {
  const grid = buildMonthGrid(date);
  const byDate = groupAppointmentsByDate(appointments);
  const todayKey = toDateKey(new Date());
  const practitionerById = new Map(practitioners.map((p) => [p.id, p]));

  const selectedAppointments = (byDate.get(date) ?? []).slice().sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at));
  const onLeaveSelected = date === todayKey ? practitioners.filter((p) => leaveEmployeeIds.includes(p.id)) : [];

  return (
    <>
      {/* Mobile: rounded tiles + dots, tap reveals that day's agenda inline. */}
      <div className="space-y-3 md:hidden">
        <CrmCard className="p-3">
          <div className="grid grid-cols-7 gap-1 pb-1.5 text-center text-[10px] font-bold uppercase tracking-[0.06em] text-ovs-crm-text-muted">
            {WEEKDAY_LABELS.map((label, i) => (
              <span key={i}>{label}</span>
            ))}
          </div>
          <div className="grid grid-cols-7 gap-1">
            {grid.map((cell) => {
              const dayAppointments = byDate.get(cell.key) ?? [];
              const dots = Array.from(new Set(dayAppointments.map((a) => a.status))).slice(0, 4);
              const isToday = cell.key === todayKey;
              const isSelected = cell.key === date;

              return (
                <button
                  key={cell.key}
                  type="button"
                  onClick={() => onMobileDateSelect(cell.key)}
                  className={cn(
                    'flex min-h-11 flex-col items-center justify-center gap-1 rounded-[14px] py-1.5 transition-colors',
                    !cell.inMonth && 'opacity-30',
                    isSelected ? 'bg-ovs-crm-ink' : isToday ? 'bg-ovs-crm-accent-violet-soft' : 'hover:bg-ovs-crm-surface-inset'
                  )}
                >
                  <span className={cn('text-[13px] font-semibold', isSelected ? 'text-white' : 'text-ovs-crm-text-primary')}>
                    {cell.date.getDate()}
                  </span>
                  <span className="flex h-[6px] items-center gap-[3px]">
                    {dots.map((status) => (
                      <span key={status} className="h-[5px] w-[5px] shrink-0 rounded-full" style={{ backgroundColor: APPOINTMENT_STATUS_TONE[status].dot }} />
                    ))}
                  </span>
                </button>
              );
            })}
          </div>
        </CrmCard>

        {onLeaveSelected.length > 0 && (
          <div className="flex flex-wrap gap-2 px-1">
            {onLeaveSelected.map((p) => (
              <span key={p.id} className="inline-flex items-center gap-1.5 rounded-full bg-ovs-crm-surface-inset px-3 py-1.5 text-[11.5px] font-medium text-ovs-crm-text-secondary">
                {p.name} is on leave today
              </span>
            ))}
          </div>
        )}

        {selectedAppointments.length === 0 ? (
          <CrmCard className="flex flex-col items-center gap-2 py-12 text-center">
            <CalendarClock className="h-7 w-7 text-ovs-crm-text-muted" aria-hidden="true" />
            <p className="text-[13.5px] font-medium text-ovs-crm-text-primary">No appointments this day</p>
          </CrmCard>
        ) : (
          <AgendaList appointments={selectedAppointments} practitioners={practitioners} leaveEmployeeIds={[]} onEventClick={onEventClick} />
        )}
      </div>

      {/* Desktop/tablet: denser grid, up to 3 chips per cell + "N more". */}
      <div className="hidden overflow-hidden rounded-[18px] border border-ovs-crm-border bg-white shadow-ovs-crmPanel md:block">
        <div className="grid grid-cols-7 border-b border-ovs-crm-border">
          {WEEKDAY_LABELS.map((label, i) => (
            <div key={i} className="border-l border-ovs-crm-border py-2 text-center text-[10.5px] font-semibold uppercase tracking-[0.06em] text-ovs-crm-text-muted first:border-l-0">
              {label}
            </div>
          ))}
        </div>
        <div className="grid grid-cols-7">
          {grid.map((cell) => {
            const dayAppointments = (byDate.get(cell.key) ?? []).slice().sort((a, b) => a.scheduled_at.localeCompare(b.scheduled_at));
            const visible = dayAppointments.slice(0, MAX_DESKTOP_CHIPS);
            const overflow = dayAppointments.length - visible.length;
            const isToday = cell.key === todayKey;

            return (
              <div
                key={cell.key}
                className={cn('relative min-h-[110px] border-l border-t border-ovs-crm-border p-1.5', !cell.inMonth && 'bg-ovs-crm-surface-inset/30')}
              >
                {/* A click-catcher sibling, not a wrapping div with
                    `stopPropagation` on the chips inside it — nesting an
                    interactive chip/button inside another element's
                    `onClick` made hit-testing unreliable (a real click
                    could land on the outer handler instead of the chip
                    directly under the pointer). Same non-nested pattern
                    `TimeGrid.tsx` already uses for its slot-click layer. */}
                <button
                  type="button"
                  aria-label={`Open ${cell.key}`}
                  className="absolute inset-0 cursor-pointer transition-colors hover:bg-ovs-crm-surface-inset/60"
                  onClick={() => onDesktopDayOpen(cell.key)}
                />

                <span
                  className={cn(
                    'relative z-[1] mb-1 inline-flex h-6 w-6 items-center justify-center rounded-full text-[12px] font-semibold',
                    isToday ? 'bg-ovs-crm-ink text-white' : cell.inMonth ? 'text-ovs-crm-text-primary' : 'text-ovs-crm-text-muted opacity-60'
                  )}
                >
                  {cell.date.getDate()}
                </span>
                <div className="relative z-[1] space-y-1">
                  {visible.map((appt) => (
                    <EventCard
                      key={appt.id}
                      appointment={appt}
                      variant="chip"
                      onClick={(e) => onEventClick(appt, e.currentTarget.getBoundingClientRect())}
                    />
                  ))}
                  {overflow > 0 && (
                    <button
                      type="button"
                      onClick={() => onDesktopDayOpen(cell.key)}
                      className="block w-full rounded-[4px] px-1.5 py-0.5 text-left text-[10.5px] font-semibold text-ovs-crm-accent-violet-text hover:bg-ovs-crm-accent-violet-soft"
                    >
                      +{overflow} more…
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
}

export default MonthView;
