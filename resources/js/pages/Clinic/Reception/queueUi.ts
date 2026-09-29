import { useEffect, useMemo, useState } from 'react';
import type { CrmStatusTone } from '@/pages/Company/design';
import { APPOINTMENT_STATUS_TONE } from '../Appointments/statusTone';
import type { QueueAction, QueueStatus } from './types';

/**
 * Queue colours reuse the appointment calendar's status palette, so a
 * patient "with the doctor" looks the same on the calendar and in the queue.
 */
export const QUEUE_STATUS: Record<QueueStatus, { label: string; dot: string; pill: CrmStatusTone }> = {
  waiting: { label: 'Waiting', dot: APPOINTMENT_STATUS_TONE.checked_in.dot, pill: 'info' },
  called: { label: 'Called', dot: APPOINTMENT_STATUS_TONE.scheduled.dot, pill: 'blue' },
  in_consultation: { label: 'With doctor', dot: APPOINTMENT_STATUS_TONE.in_consultation.dot, pill: 'warn' },
  skipped: { label: 'Not present', dot: APPOINTMENT_STATUS_TONE.cancelled.dot, pill: 'neutral' },
};

/** The one next step for each status, and the less common ones behind it. */
export const QUEUE_ACTIONS: Record<QueueStatus, { primary: QueueAction; more: QueueAction[] }> = {
  waiting: { primary: 'call', more: ['start', 'skip', 'cancel'] },
  called: { primary: 'start', more: ['recall', 'skip', 'cancel'] },
  in_consultation: { primary: 'complete', more: [] },
  skipped: { primary: 'requeue', more: ['cancel'] },
};

export const ACTION_LABEL: Record<QueueAction, string> = {
  call: 'Call in',
  recall: 'Call again',
  start: 'Take in',
  complete: 'Done',
  skip: 'Not present',
  requeue: 'Back to queue',
  cancel: 'Left without being seen',
};

/** Server datetimes are clinic wall-clock digits with no offset; the browser reads them as local time. */
export const parseClinicTime = (value: string | null): Date | null => (value ? new Date(value) : null);

export const formatClock = (value: string | null): string => {
  const date = parseClinicTime(value);

  return date ? date.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : '—';
};

export const formatHour = (hour: number): string => {
  const suffix = hour < 12 ? 'am' : 'pm';
  const twelve = hour % 12 === 0 ? 12 : hour % 12;

  return `${twelve} ${suffix}`;
};

/**
 * "Now" on the clinic's clock, re-rendering every 30 seconds so wait timers
 * tick between polls. Anchored to the server's `now` so a wrong device clock
 * can't skew the minutes.
 */
export function useClinicNow(serverNow: string): Date {
  const offset = useMemo(() => new Date(serverNow).getTime() - Date.now(), [serverNow]);
  const [now, setNow] = useState(() => new Date(Date.now() + offset));

  useEffect(() => {
    setNow(new Date(Date.now() + offset));
    const timer = window.setInterval(() => setNow(new Date(Date.now() + offset)), 30_000);

    return () => window.clearInterval(timer);
  }, [offset]);

  return now;
}

export const minutesBetween = (from: string | null, to: Date): number | null => {
  const start = parseClinicTime(from);

  return start ? Math.max(0, Math.floor((to.getTime() - start.getTime()) / 60_000)) : null;
};

export const formatMinutes = (minutes: number | null): string => {
  if (minutes === null) return '—';
  if (minutes < 60) return `${minutes} min`;

  return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
};
