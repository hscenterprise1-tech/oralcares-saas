import { addDays, addMonths, addWeeks } from 'date-fns';
import { AppointmentRow, CalendarView } from './types';

/**
 * Every date/grid calculation the calendar needs, in one place, so Day,
 * Week and Month's two variants all compute "which days show" the same
 * way. Previously each view had its own inline copy of this math — that's
 * exactly how the Month-view date-reset bug happened (one copy quietly
 * diverged from the others).
 */

/** `yyyy-mm-dd` from a Date's LOCAL calendar fields — never `toISOString()`, which is UTC and can roll the date back a day in any timezone ahead of UTC (e.g. IST). */
export function toDateKey(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

/** `yyyy-mm-dd` -> a Date at local midnight, so the day never shifts by timezone. */
export function parseDateKey(key: string): Date {
  return new Date(`${key}T00:00:00`);
}

export function startOfWeekMonday(date: Date): Date {
  const daysSinceMonday = (date.getDay() + 6) % 7; // getDay(): 0=Sun..6=Sat
  return addDays(date, -daysSinceMonday);
}

/** Moves `key` by one Day/Week/Month unit — the only place any control should compute a new date; every caller feeds the result straight to `navigate()`. */
export function shiftDateKey(key: string, view: CalendarView, direction: 1 | -1): string {
  const base = parseDateKey(key);
  const next = view === 'day' ? addDays(base, direction) : view === 'week' ? addWeeks(base, direction) : addMonths(base, direction);
  return toDateKey(next);
}

export interface GridDay {
  key: string;
  date: Date;
  inMonth: boolean;
}

/** The Monday-first grid (including the leading/trailing days of adjacent months) for the month containing `dateKey` — 5 or 6 rows depending on the month. */
export function buildMonthGrid(dateKey: string): GridDay[] {
  const cursor = parseDateKey(dateKey);
  const year = cursor.getFullYear();
  const month = cursor.getMonth();
  const firstOfMonth = new Date(year, month, 1);
  const gridStart = startOfWeekMonday(firstOfMonth);
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const firstWeekday = (firstOfMonth.getDay() + 6) % 7;
  const weeksNeeded = Math.ceil((firstWeekday + daysInMonth) / 7);

  return Array.from({ length: weeksNeeded * 7 }, (_, i) => {
    const d = addDays(gridStart, i);
    return { key: toDateKey(d), date: d, inMonth: d.getMonth() === month };
  });
}

/** The Monday-first 7-day range for the week containing `dateKey` — matches the backend's `startOfWeek(Carbon::MONDAY)` computation for Week view's `rangeStart`. */
export function buildWeekDays(dateKey: string): { key: string; date: Date }[] {
  const start = startOfWeekMonday(parseDateKey(dateKey));
  return Array.from({ length: 7 }, (_, i) => {
    const d = addDays(start, i);
    return { key: toDateKey(d), date: d };
  });
}

export function groupAppointmentsByDate(appointments: AppointmentRow[]): Map<string, AppointmentRow[]> {
  const map = new Map<string, AppointmentRow[]>();
  for (const appt of appointments) {
    const key = appt.scheduled_at.slice(0, 10);
    if (!map.has(key)) map.set(key, []);
    map.get(key)!.push(appt);
  }
  return map;
}

export function minutesSinceMidnight(isoLocal: string): number {
  const d = new Date(isoLocal);
  return d.getHours() * 60 + d.getMinutes();
}
