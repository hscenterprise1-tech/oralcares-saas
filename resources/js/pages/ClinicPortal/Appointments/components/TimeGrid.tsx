import { useEffect, useMemo, useRef, useState, type MouseEvent, type PointerEvent as ReactPointerEvent } from 'react';
import { AlertTriangle, Plus, Repeat } from 'lucide-react';
import { cn } from '@/lib/utils';
import { at, clock, clock12, dayNumber, minutesOf, toMinutes, weekdayShort } from '../lib/dates';
import { layoutOverlaps } from '../lib/layout';
import { initials, providerTone, statusStyle } from '../lib/status';
import { personName, type AppointmentItem, type BranchDay, type FreeTime, type Window } from '../lib/types';

export interface MoveRequest {
  appointment: AppointmentItem;
  start: string;
  duration: number;
}

const DRAG_THRESHOLD = 5;

interface DragState {
  appointment: AppointmentItem;
  mode: 'move' | 'resize';
  pointerX: number;
  pointerY: number;
  date: string;
  start: number;
  duration: number;
  moved: boolean;
}

/**
 * The Day and Week grid: dates across, time down. Every authorised
 * provider's appointments share it; who the appointment is with is written
 * inside the appointment, never used as a column.
 *
 * Time nobody works is shaded, the clinic's break is marked, a line shows
 * the current time, and appointments that overlap sit side by side. In Day
 * view the open times show as "available". Appointments can be dragged to a
 * new time or resized; every drop is sent to the server, which re-checks it.
 */
export function TimeGrid({
  dates,
  today,
  days,
  dayWindows,
  appointments,
  freeTimes,
  slotMinutes,
  dayStart,
  dayEnd,
  breakWindow,
  now,
  canCreate,
  canMove,
  onSelect,
  onCreate,
  onMove,
}: {
  dates: string[];
  today: string;
  days: Record<string, BranchDay>;
  dayWindows: Record<string, Window[]>;
  appointments: AppointmentItem[];
  /** Day view only; undefined while loading. */
  freeTimes?: FreeTime[];
  slotMinutes: number;
  dayStart: number;
  dayEnd: number;
  breakWindow: Window | null;
  now: string;
  canCreate: boolean;
  canMove: boolean;
  onSelect: (appointment: AppointmentItem) => void;
  onCreate: (start: string) => void;
  onMove: (request: MoveRequest) => void;
}) {
  const single = dates.length === 1;
  const hourPx = single ? 104 : 88;
  const pxPerMin = hourPx / 60;
  const scrollRef = useRef<HTMLDivElement>(null);
  const [drag, setDrag] = useState<DragState | null>(null);
  const dragRef = useRef<DragState | null>(null);
  const [nowMinutes, setNowMinutes] = useState(() => minutesOf(now));

  useEffect(() => {
    setNowMinutes(minutesOf(now));
    const timer = window.setInterval(() => setNowMinutes((minutes) => minutes + 1), 60_000);

    return () => window.clearInterval(timer);
  }, [now]);

  // Bookable windows per date; null when nothing is enforced (all bookable).
  const windowsFor = (date: string): Window[] | null => (days[date]?.enforced ? (dayWindows[date] ?? []) : null);
  const blockedReason = (date: string): string | null => {
    const day = days[date];

    return day && !day.open && day.enforced ? day.reason : null;
  };

  // The visible hours: the clinic's day, widened to fit every appointment.
  const [gridStart, gridEnd] = useMemo(() => {
    let start = dayStart;
    let end = dayEnd;
    appointments.forEach((appointment) => {
      start = Math.min(start, minutesOf(appointment.start));
      end = Math.max(end, minutesOf(appointment.start) + appointment.duration_minutes);
    });
    dates.forEach((date) =>
      (days[date]?.enforced ? (dayWindows[date] ?? []) : []).forEach(([from, to]) => {
        start = Math.min(start, from);
        end = Math.max(end, to);
      }),
    );

    return [Math.max(0, Math.floor(start / 60) * 60), Math.min(24 * 60, Math.ceil(end / 60) * 60)];
  }, [appointments, dates, days, dayWindows, dayStart, dayEnd]);

  const hours = useMemo(() => {
    const list: number[] = [];
    for (let minute = gridStart; minute < gridEnd; minute += 60) {
      list.push(minute);
    }

    return list;
  }, [gridStart, gridEnd]);

  const height = (gridEnd - gridStart) * pxPerMin;
  const top = (minutes: number) => (minutes - gridStart) * pxPerMin;

  // Open on the working day, or a little before the current time today.
  useEffect(() => {
    const target = dates.includes(today) ? nowMinutes - 90 : dayStart;
    scrollRef.current?.scrollTo({ top: Math.max(0, top(target)) });
    // Only when the range changes, not every minute.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dates.join('|')]);

  const byDate = useMemo(() => {
    const map = new Map<string, AppointmentItem[]>();
    dates.forEach((date) => map.set(date, []));
    appointments.forEach((appointment) => map.get(appointment.start.slice(0, 10))?.push(appointment));

    return map;
  }, [appointments, dates]);

  const freeByDate = useMemo(() => {
    const map = new Map<string, FreeTime[]>();
    (freeTimes ?? []).forEach((free) => {
      const key = free.start.slice(0, 10);
      map.set(key, [...(map.get(key) ?? []), free]);
    });

    return map;
  }, [freeTimes]);

  const snap = (minutes: number) => Math.round(minutes / slotMinutes) * slotMinutes;

  const dateAt = (x: number, y: number): string | null => {
    const element = document
      .elementsFromPoint(x, y)
      .find((node) => node instanceof HTMLElement && node.dataset.gridDate) as HTMLElement | undefined;

    return element?.dataset.gridDate ?? null;
  };

  const isBookable = (date: string, minutes: number): boolean => {
    if (blockedReason(date) || date < today || (date === today && minutes + slotMinutes <= nowMinutes)) {
      return false;
    }
    const windows = windowsFor(date);

    return windows === null || windows.some(([from, to]) => minutes >= from && minutes < to);
  };

  const beginDrag = (event: ReactPointerEvent, appointment: AppointmentItem, mode: 'move' | 'resize') => {
    if (event.button !== 0) {
      return;
    }
    event.stopPropagation();
    dragRef.current = {
      appointment,
      mode,
      pointerX: event.clientX,
      pointerY: event.clientY,
      date: appointment.start.slice(0, 10),
      start: minutesOf(appointment.start),
      duration: appointment.duration_minutes,
      moved: false,
    };
    (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
  };

  const continueDrag = (event: ReactPointerEvent) => {
    const state = dragRef.current;
    if (!state || !canMove || !state.appointment.upcoming) {
      return;
    }
    const dx = event.clientX - state.pointerX;
    const dy = event.clientY - state.pointerY;
    if (!state.moved && Math.hypot(dx, dy) < DRAG_THRESHOLD) {
      return;
    }
    const delta = snap(dy / pxPerMin);
    const original = minutesOf(state.appointment.start);
    const next: DragState = { ...state, moved: true };

    if (state.mode === 'resize') {
      next.duration = Math.max(slotMinutes, Math.min(8 * 60, state.appointment.duration_minutes + delta));
    } else {
      next.start = Math.max(gridStart, Math.min(gridEnd - state.duration, original + delta));
      next.date = dateAt(event.clientX, event.clientY) ?? state.date;
    }
    dragRef.current = next;
    setDrag(next);
  };

  const endDrag = () => {
    const state = dragRef.current;
    dragRef.current = null;
    setDrag(null);
    if (!state) {
      return;
    }
    if (!state.moved) {
      onSelect(state.appointment);

      return;
    }
    const start = at(state.date, state.start);
    if (start === state.appointment.start && state.duration === state.appointment.duration_minutes) {
      return;
    }
    onMove({ appointment: state.appointment, start, duration: state.duration });
  };

  const createAt = (event: MouseEvent<HTMLDivElement>, date: string) => {
    if (!canCreate || dragRef.current) {
      return;
    }
    const rect = event.currentTarget.getBoundingClientRect();
    const minutes = Math.floor(((event.clientY - rect.top) / pxPerMin + gridStart) / slotMinutes) * slotMinutes;
    if (isBookable(date, minutes)) {
      onCreate(at(date, minutes));
    }
  };

  return (
    <div
      ref={scrollRef}
      className="relative max-h-[calc(100dvh-230px)] min-h-[460px] overflow-auto overscroll-contain"
      onPointerMove={continueDrag}
      onPointerUp={endDrag}
      onPointerCancel={() => {
        dragRef.current = null;
        setDrag(null);
      }}
    >
      <div style={{ minWidth: single ? undefined : 56 + 120 * dates.length }}>
        {/* Date headers */}
        <div className="sticky top-0 z-20 flex border-b border-clinic-border bg-white/95 backdrop-blur">
          <div className="sticky left-0 z-10 w-14 shrink-0 bg-white/95" />
          {dates.map((date) => {
            const isToday = date === today;
            const count = (byDate.get(date) ?? []).filter((item) => item.display_status !== 'cancelled').length;

            return (
              <div key={date} className="flex min-w-0 flex-1 items-center justify-center gap-2 border-l border-clinic-border px-2 py-2.5">
                <span className={cn('text-[11px] font-bold uppercase tracking-wide', isToday ? 'text-clinic-brand-strong' : 'text-clinic-secondary')}>
                  {weekdayShort(date)}
                </span>
                <span
                  className={cn(
                    'flex h-8 w-8 items-center justify-center rounded-full text-[14px] font-bold tabular-nums',
                    isToday ? 'bg-clinic-ink text-white' : 'text-clinic-ink',
                  )}
                >
                  {dayNumber(date)}
                </span>
                {count > 0 && (
                  <span className="rounded-full bg-clinic-inset px-1.5 py-px text-[10.5px] font-bold tabular-nums text-clinic-secondary">
                    {count}
                  </span>
                )}
              </div>
            );
          })}
        </div>

        <div className="relative flex" style={{ height }}>
          {/* Time axis */}
          <div className="sticky left-0 z-10 w-14 shrink-0 bg-white/95">
            {hours.map((minute) => (
              <div
                key={minute}
                className="absolute right-2 -translate-y-1/2 text-[10.5px] font-medium tabular-nums text-clinic-secondary"
                style={{ top: top(minute) }}
              >
                {minute === gridStart ? '' : clock12(minute)}
              </div>
            ))}
          </div>

          {dates.map((date) => {
            const items = byDate.get(date) ?? [];
            const placed = layoutOverlaps(
              items.map((item) => ({
                id: item.id,
                start: minutesOf(item.start),
                end: minutesOf(item.start) + Math.max(item.duration_minutes, slotMinutes),
              })),
            );
            const blocked = blockedReason(date);
            const unavailable = blocked ? [[gridStart, gridEnd] as Window] : complement(windowsFor(date), gridStart, gridEnd);
            const isToday = date === today;
            const pastUntil = date < today ? gridEnd : isToday ? Math.min(gridEnd, Math.max(gridStart, nowMinutes)) : gridStart;
            const taken = items.filter((item) => item.display_status !== 'cancelled' && item.display_status !== 'no_show');

            return (
              <div
                key={date}
                data-grid-date={date}
                onClick={(event) => {
                  if (event.target === event.currentTarget || (event.target as HTMLElement).dataset.slotLayer) {
                    createAt(event, date);
                  }
                }}
                className={cn('relative min-w-0 flex-1 border-l border-clinic-border', canCreate && 'cursor-cell', isToday && !single && 'bg-clinic-brand-soft/20')}
              >
                {hours.map((minute) => (
                  <div key={minute} className="pointer-events-none absolute inset-x-0" style={{ top: top(minute) }}>
                    <div className="border-t border-clinic-border" />
                    <div className="border-t border-dashed border-clinic-border/60" style={{ marginTop: hourPx / 2 - 1 }} />
                  </div>
                ))}

                {unavailable.map(([from, to]) => (
                  <div
                    key={`off-${from}`}
                    data-slot-layer="1"
                    className="absolute inset-x-0 bg-[repeating-linear-gradient(135deg,rgba(148,156,172,0.11)_0_6px,rgba(148,156,172,0.04)_6px_12px)]"
                    style={{ top: top(from), height: (to - from) * pxPerMin }}
                  />
                ))}

                {pastUntil > gridStart && (
                  <div className="pointer-events-none absolute inset-x-0 top-0 bg-slate-100/40" style={{ height: (pastUntil - gridStart) * pxPerMin }} />
                )}

                {blocked && (
                  <div className="pointer-events-none absolute inset-x-2 top-3 z-[1] rounded-xl bg-white/90 px-2 py-1.5 text-center text-[11.5px] font-semibold text-clinic-secondary shadow-sm">
                    {blocked}
                  </div>
                )}

                {breakWindow && !blocked && breakWindow[1] > gridStart && breakWindow[0] < gridEnd && (
                  <div
                    data-slot-layer="1"
                    className="absolute inset-x-0 flex items-center justify-center bg-slate-100/80"
                    style={{ top: top(breakWindow[0]), height: (breakWindow[1] - breakWindow[0]) * pxPerMin }}
                  >
                    <span className="text-[10.5px] font-bold uppercase tracking-[0.12em] text-clinic-secondary">Break</span>
                  </div>
                )}

                {/* Day view: the open half-hours, one tap to book. */}
                {single &&
                  canCreate &&
                  (freeByDate.get(date) ?? [])
                    .filter((free) => {
                      const minute = minutesOf(free.start);

                      return minute % 30 === 0 && !taken.some((item) => minutesOf(item.start) <= minute && minute < minutesOf(item.start) + item.duration_minutes);
                    })
                    .map((free) => (
                      <button
                        key={free.start}
                        type="button"
                        onClick={() => onCreate(free.start)}
                        aria-label={`${clock12(minutesOf(free.start))} available, ${free.providers} ${free.providers === 1 ? 'provider' : 'providers'} free. Book`}
                        className="absolute left-2 z-[1] flex h-7 items-center gap-1 rounded-full border border-dashed border-emerald-300 bg-emerald-50/90 px-2.5 text-[11px] font-semibold text-emerald-700 hover:border-emerald-400 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                        style={{ top: top(minutesOf(free.start)) + 4 }}
                      >
                        <Plus className="h-3 w-3" aria-hidden="true" />
                        {clock12(minutesOf(free.start))} · Available
                        {free.providers > 1 && <span className="font-medium text-emerald-600">({free.providers} free)</span>}
                      </button>
                    ))}

                {items.map((item) => {
                  const position = placed.get(item.id);
                  const width = 100 / (position?.columns ?? 1);

                  return (
                    <AppointmentBlock
                      key={item.id}
                      item={item}
                      wide={single}
                      narrow={!single && (position?.columns ?? 1) > 1}
                      top={top(minutesOf(item.start))}
                      height={Math.max(item.duration_minutes, slotMinutes) * pxPerMin}
                      left={`${(position?.column ?? 0) * width}%`}
                      width={`${width}%`}
                      faded={drag?.appointment.id === item.id}
                      draggable={canMove && item.upcoming}
                      onPointerDown={(event, mode) => beginDrag(event, item, mode)}
                      onKeyOpen={() => onSelect(item)}
                    />
                  );
                })}

                {drag && drag.date === date && drag.moved && (
                  <div
                    className="pointer-events-none absolute inset-x-1 z-20 rounded-xl border-2 border-dashed border-clinic-brand-strong bg-clinic-brand-soft/80 px-2 py-1 text-[11.5px] font-semibold text-clinic-brand-strong"
                    style={{ top: top(drag.start), height: Math.max(drag.duration, slotMinutes) * pxPerMin }}
                  >
                    {clock(drag.start)}–{clock(drag.start + drag.duration)}
                  </div>
                )}

                {isToday && nowMinutes >= gridStart && nowMinutes <= gridEnd && (
                  <div className="pointer-events-none absolute inset-x-0 z-10" style={{ top: top(nowMinutes) }} aria-hidden="true">
                    <div className="relative border-t-2 border-rose-500">
                      <span className="absolute -left-1 -top-[5px] h-2 w-2 rounded-full bg-rose-500" />
                    </div>
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

/**
 * One appointment: time, patient, service, provider and status. The
 * provider is part of the appointment, with their initials in their colour.
 */
function AppointmentBlock({
  item,
  wide,
  narrow,
  top,
  height,
  left,
  width,
  faded,
  draggable,
  onPointerDown,
  onKeyOpen,
}: {
  item: AppointmentItem;
  wide: boolean;
  /** Sharing a week column with an overlapping appointment. */
  narrow: boolean;
  top: number;
  height: number;
  left: string;
  width: string;
  faded: boolean;
  draggable: boolean;
  onPointerDown: (event: ReactPointerEvent, mode: 'move' | 'resize') => void;
  onKeyOpen: () => void;
}) {
  const style = statusStyle(item.display_status);
  const name = personName(item);
  const tiny = height < 34;
  const short = height < 58;
  const time = clock12(minutesOf(item.start));
  const label = `${time}, ${name}${item.service ? `, ${item.service.name}` : ''}${item.provider_name ? `, with ${item.provider_name}` : ''}, ${style.label}`;

  return (
    <div className={cn('absolute z-[2] px-0.5 py-px', wide && 'pl-[188px] sm:pl-[196px]')} style={{ top, height, left, width }}>
      <button
        type="button"
        aria-label={label}
        onPointerDown={(event) => onPointerDown(event, 'move')}
        onKeyDown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onKeyOpen();
          }
        }}
        className={cn(
          'group relative flex h-full w-full select-none overflow-hidden rounded-xl text-left shadow-[0_1px_2px_rgba(16,24,40,0.06)] ring-1 ring-black/[0.05] motion-safe:transition-shadow hover:shadow-[0_4px_12px_-4px_rgba(16,24,40,0.18)]',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-clinic-brand-strong',
          style.card,
          draggable ? 'cursor-grab touch-none active:cursor-grabbing' : 'cursor-pointer',
          faded && 'opacity-40',
        )}
      >
        <span className={cn('w-1 shrink-0', style.bar)} aria-hidden="true" />
        <span className={cn('flex min-w-0 flex-1 gap-2 px-2', tiny ? 'items-center py-0' : 'py-1.5')}>
          <span className="min-w-0 flex-1">
            {narrow && !tiny && <span className="block text-[10.5px] font-bold tabular-nums opacity-75">{time}</span>}
            <span className="flex min-w-0 items-center gap-1.5">
              {!(narrow && !tiny) && <span className="shrink-0 text-[10.5px] font-bold tabular-nums opacity-75">{time}</span>}
              <span className="truncate text-[12px] font-bold leading-tight">{name}</span>
              {item.new_case && (
                <span className="shrink-0 rounded-full bg-white/80 px-1.5 text-[9px] font-bold uppercase tracking-wide">New</span>
              )}
              {item.provider_on_leave && item.upcoming && (
                <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-amber-600" aria-label="Provider on leave" />
              )}
            </span>
            {!tiny && !narrow && (
              <span className="mt-0.5 block truncate text-[11px] font-medium leading-tight opacity-85">
                {item.service?.name ?? 'Appointment'}
                {short && item.provider_name ? ` · ${item.provider_name}` : ''}
              </span>
            )}
            {narrow && !short && (
              <span className="mt-1 flex min-w-0 items-center gap-1">
                <span className={cn('flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold', providerTone(item.provider_id))} title={item.provider_name ?? undefined}>
                  {initials(item.provider_name)}
                </span>
                <span className="truncate text-[10.5px] font-medium opacity-85">{item.service?.name ?? 'Appointment'}</span>
              </span>
            )}
            {!short && !narrow && (
              <span className="mt-1 flex min-w-0 items-center gap-1.5">
                <span className={cn('flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[9px] font-bold', providerTone(item.provider_id))}>
                  {initials(item.provider_name)}
                </span>
                <span className="truncate text-[11px] font-semibold opacity-90">{item.provider_name ?? 'No provider'}</span>
              </span>
            )}
          </span>
          {!tiny && wide && (
            <span className="flex shrink-0 flex-col items-end gap-1">
              <span className="rounded-full bg-white/75 px-1.5 py-px text-[9.5px] font-bold uppercase tracking-wide">{style.label}</span>
              {item.series_id && <Repeat className="h-3 w-3 opacity-70" aria-label="Recurring" />}
            </span>
          )}
        </span>
      </button>
      {draggable && height >= 28 && (
        <span
          role="presentation"
          onPointerDown={(event) => onPointerDown(event, 'resize')}
          className={cn('absolute bottom-0 h-2 cursor-ns-resize touch-none rounded-b-xl opacity-0 hover:bg-black/10 hover:opacity-100', wide ? 'left-[196px] right-3' : 'inset-x-3')}
        />
      )}
    </div>
  );
}

/** The parts of [from, to) not covered by any window. */
function complement(windows: Window[] | null, from: number, to: number): Window[] {
  if (windows === null) {
    return [];
  }
  const sorted = [...windows].sort((a, b) => a[0] - b[0]);
  const gaps: Window[] = [];
  let cursor = from;
  sorted.forEach(([start, end]) => {
    if (start > cursor) {
      gaps.push([cursor, Math.min(start, to)]);
    }
    cursor = Math.max(cursor, end);
  });
  if (cursor < to) {
    gaps.push([cursor, to]);
  }

  return gaps.filter(([start, end]) => end > start);
}

export function breakFrom(start: string | null, end: string | null): Window | null {
  return start && end ? [toMinutes(start), toMinutes(end)] : null;
}
