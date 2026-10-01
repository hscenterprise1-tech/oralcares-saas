import type { CalendarView } from './types';

/**
 * Date math on clinic wall-clock strings (`YYYY-MM-DD`, `YYYY-MM-DDTHH:mm:ss`).
 *
 * The calendar never builds a Date in the browser's own timezone: dates are
 * computed in UTC on plain digits, so a reception desk in any timezone sees
 * the clinic's times exactly as the clinic keeps them.
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const WEEKDAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

function toUtc(key: string): Date {
  const [year, month, day] = key.slice(0, 10).split('-').map(Number);

  return new Date(Date.UTC(year, month - 1, day));
}

function fromUtc(date: Date): string {
  return date.toISOString().slice(0, 10);
}

export function addDays(key: string, days: number): string {
  const date = toUtc(key);
  date.setUTCDate(date.getUTCDate() + days);

  return fromUtc(date);
}

export function addMonths(key: string, months: number): string {
  const date = toUtc(key);
  const day = date.getUTCDate();
  date.setUTCDate(1);
  date.setUTCMonth(date.getUTCMonth() + months);
  const last = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
  date.setUTCDate(Math.min(day, last));

  return fromUtc(date);
}

/** 0 = Sunday, as the server's `dayOfWeek`. */
export function weekday(key: string): number {
  return toUtc(key).getUTCDay();
}

export function daysBetween(from: string, to: string): string[] {
  const days: string[] = [];
  for (let key = from; key <= to && days.length < 62; key = addDays(key, 1)) {
    days.push(key);
  }

  return days;
}

export function shiftDate(key: string, view: CalendarView, direction: 1 | -1): string {
  switch (view) {
    case 'day':
      return addDays(key, direction);
    case 'week':
      return addDays(key, 7 * direction);
    default:
      return addMonths(key, direction);
  }
}

export function dayNumber(key: string): number {
  return Number(key.slice(8, 10));
}

export function monthOf(key: string): number {
  return Number(key.slice(5, 7));
}

/** "Mon" */
export function weekdayShort(key: string): string {
  return WEEKDAYS[weekday(key)];
}

/** "Monday, 5 October" */
export function longDate(key: string): string {
  return `${WEEKDAYS_LONG[weekday(key)]}, ${dayNumber(key)} ${MONTHS_LONG[monthOf(key) - 1]}`;
}

/** "Mon 5 Oct" */
export function shortDate(key: string): string {
  return `${weekdayShort(key)} ${dayNumber(key)} ${MONTHS[monthOf(key) - 1]}`;
}

/** The header's label for the visible range: "29 Sep – 5 Oct 2026". */
export function rangeLabel(view: CalendarView, start: string, end: string, date: string): string {
  const year = (key: string) => key.slice(0, 4);
  const dm = (key: string) => `${dayNumber(key)} ${MONTHS[monthOf(key) - 1]}`;

  if (view === 'day') {
    return `${longDate(date)} ${year(date)}`;
  }
  if (view === 'month') {
    return `${MONTHS_LONG[monthOf(date) - 1]} ${year(date)}`;
  }

  return year(start) === year(end) ? `${dm(start)} – ${dm(end)} ${year(end)}` : `${dm(start)} ${year(start)} – ${dm(end)} ${year(end)}`;
}

/** Minutes from midnight of a wall-clock date-time. */
export function minutesOf(dateTime: string): number {
  return Number(dateTime.slice(11, 13)) * 60 + Number(dateTime.slice(14, 16));
}

export function clock(minutes: number): string {
  const safe = Math.max(0, Math.min(24 * 60, minutes));

  return `${String(Math.floor(safe / 60)).padStart(2, '0')}:${String(safe % 60).padStart(2, '0')}`;
}

/** "9:30 am", the way staff read times aloud. */
export function clock12(minutes: number): string {
  const hours = Math.floor(minutes / 60) % 24;
  const mins = minutes % 60;
  const suffix = hours < 12 ? 'am' : 'pm';
  const twelve = hours % 12 === 0 ? 12 : hours % 12;

  return mins === 0 ? `${twelve} ${suffix}` : `${twelve}:${String(mins).padStart(2, '0')} ${suffix}`;
}

export function timeOf(dateTime: string): string {
  return clock12(minutesOf(dateTime));
}

export function at(date: string, minutes: number): string {
  return `${date}T${clock(minutes)}:00`;
}

export function toMinutes(time: string): number {
  const [hours, mins] = time.split(':').map(Number);

  return hours * 60 + (mins || 0);
}

export function monthGrid(date: string): string[] {
  const first = `${date.slice(0, 8)}01`;
  const start = addDays(first, -((weekday(first) + 6) % 7));
  const nextMonth = addMonths(first, 1);
  const lastOfMonth = addDays(nextMonth, -1);
  const end = addDays(lastOfMonth, (7 - weekday(lastOfMonth)) % 7);

  return daysBetween(start, end);
}
