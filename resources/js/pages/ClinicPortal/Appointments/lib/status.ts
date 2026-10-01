import type { StatusValue } from './types';

/**
 * One colour per status, by meaning, within the portal palette: blue booked,
 * teal confirmed, amber arrived, orange waiting, violet with the doctor,
 * green done, red cancelled, muted rose for a no-show. Each pairs a tinted
 * card with text that passes WCAG AA on it, and a solid bar for the edge.
 */
export const STATUS_STYLE: Record<StatusValue, { label: string; card: string; bar: string; chip: string; dot: string }> = {
  scheduled: {
    label: 'Scheduled',
    card: 'bg-blue-50 text-blue-950 hover:bg-blue-100/80',
    bar: 'bg-blue-500',
    chip: 'bg-blue-50 text-blue-700 ring-blue-200',
    dot: 'bg-blue-500',
  },
  confirmed: {
    label: 'Confirmed',
    card: 'bg-teal-50 text-teal-950 hover:bg-teal-100/80',
    bar: 'bg-teal-500',
    chip: 'bg-teal-50 text-teal-700 ring-teal-200',
    dot: 'bg-teal-500',
  },
  arrived: {
    label: 'Arrived',
    card: 'bg-amber-50 text-amber-950 hover:bg-amber-100/80',
    bar: 'bg-amber-500',
    chip: 'bg-amber-50 text-amber-800 ring-amber-200',
    dot: 'bg-amber-500',
  },
  checked_in: {
    label: 'Checked in',
    card: 'bg-orange-50 text-orange-950 hover:bg-orange-100/80',
    bar: 'bg-orange-500',
    chip: 'bg-orange-50 text-orange-800 ring-orange-200',
    dot: 'bg-orange-500',
  },
  waiting: {
    label: 'Waiting',
    card: 'bg-orange-50 text-orange-950 hover:bg-orange-100/80',
    bar: 'bg-orange-500',
    chip: 'bg-orange-50 text-orange-800 ring-orange-200',
    dot: 'bg-orange-500',
  },
  in_consultation: {
    label: 'In consultation',
    card: 'bg-violet-50 text-violet-950 hover:bg-violet-100/80',
    bar: 'bg-violet-500',
    chip: 'bg-violet-50 text-violet-700 ring-violet-200',
    dot: 'bg-violet-500',
  },
  completed: {
    label: 'Completed',
    card: 'bg-emerald-50 text-emerald-950 hover:bg-emerald-100/80',
    bar: 'bg-emerald-600',
    chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    dot: 'bg-emerald-600',
  },
  cancelled: {
    label: 'Cancelled',
    card: 'bg-rose-50/70 text-rose-900 line-through decoration-rose-300 hover:bg-rose-50',
    bar: 'bg-rose-500',
    chip: 'bg-rose-50 text-rose-700 ring-rose-200',
    dot: 'bg-rose-500',
  },
  no_show: {
    label: 'No-show',
    card: 'bg-rose-50/60 text-rose-950 hover:bg-rose-100/60',
    bar: 'bg-rose-300',
    chip: 'bg-rose-50 text-rose-800 ring-rose-200',
    dot: 'bg-rose-300',
  },
};

export function statusStyle(status: string) {
  return STATUS_STYLE[status as StatusValue] ?? STATUS_STYLE.scheduled;
}

export function humanize(value: string | null | undefined): string {
  if (!value) {
    return '';
  }
  const text = value.replace(/_/g, ' ');

  return text.charAt(0).toUpperCase() + text.slice(1);
}


const PROVIDER_TONES = [
  'bg-sky-100 text-sky-800',
  'bg-violet-100 text-violet-800',
  'bg-emerald-100 text-emerald-800',
  'bg-amber-100 text-amber-900',
  'bg-rose-100 text-rose-800',
  'bg-teal-100 text-teal-800',
  'bg-indigo-100 text-indigo-800',
  'bg-orange-100 text-orange-900',
];

/** A provider's own soft colour for their initials, stable for their id. */
export function providerTone(id: number | null): string {
  return PROVIDER_TONES[Math.abs(id ?? 0) % PROVIDER_TONES.length];
}

/** "Dr. Vikram Rao" → "VR". */
export function initials(name: string | null): string {
  const words = (name ?? '').replace(/^(dr|mr|mrs|ms|prof)\.?\s+/i, '').trim().split(/\s+/).filter(Boolean);

  return (words[0]?.[0] ?? '') + (words.length > 1 ? words[words.length - 1][0] : '');
}
